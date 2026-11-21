<?php

namespace App\Filament\Support\ContentPlan;

use App\Filament\Support\Schedule\ErrorMasker;
use App\Models\Campaign;
use App\Models\Website;
use App\Services\Keywords\KeywordAiUnavailable;
use App\Services\Planning\Import\PlanFileFields;
use App\Services\Planning\Import\PlanFileHeuristics;
use App\Services\Planning\Import\PlanFileImporter;
use App\Services\Planning\Import\PlanFileLinks;
use App\Services\Planning\Import\PlanFileMapper;
use App\Services\Planning\Import\SheetReader;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;

/**
 * Nút "Nhập từ file" của trang Kế hoạch (hộp nổi giữa màn hình): tải file Excel/CSV của người dùng, AI (Claude) tự đoán cột nào ứng với trường nào,
 * người dùng chỉ rà lại; bản xem trước tự cập nhật mỗi khi đổi, rồi một nút Tạo mới ghi bài/chiến dịch thật.
 *
 * Các dòng của file KHÔNG nằm trong trạng thái Livewire (nặng và gửi qua lại mỗi lần): chỉ lưu ở cache theo (người dùng, mã phiên) trong 2 giờ.
 * Trạng thái phía trình duyệt (importState) chỉ chứa tên sheet, dòng tiêu đề và trường đã gán; mọi giá trị đều được kiểm lại ở máy chủ.
 */
trait HasPlanFileImport
{
    use WithFileUploads;

    public const IMPORT_TTL = 7200;

    public bool $importOpen = false;

    public $planFile = null;

    #[Locked]
    public ?string $importToken = null;

    /** @var array<string,mixed>|null */
    public ?array $importState = null;

    /** by_column | new | solo | id chiến dịch */
    public string $importTarget = PlanFileImporter::TARGET_BY_COLUMN;

    public string $importNewName = '';

    /** Người dùng đã tự đổi ô "Đưa bài vào đâu": hệ thống không đổi mặc định thay họ nữa (phân tích lại, đổi sheet...). */
    public bool $importTargetTouched = false;

    /** @var array<string,mixed>|null */
    public ?array $importPreview = null;

    /** @var array<string,mixed>|null */
    public ?array $importResult = null;

    /** Lỗi hiện ngay trong hộp (toast của Filament nằm dưới hộp nổi nên dễ bị che). */
    public ?string $importError = null;

    abstract protected function planSite(): ?Website;

    private function importGuard(): void
    {
        abort_unless(Auth::user()?->isApprover() ?? false, 403);
    }

    /** Tải file mẫu kế hoạch (xlsx hoặc csv) để điền rồi nhập lại; tiêu đề cột đã được bộ nhận diện hiểu chắc chắn. */
    public function downloadPlanTemplate(string $format = 'xlsx'): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->importGuard();
        $csv = $format === 'csv';

        return response()->streamDownload(function () use ($csv) {
            echo $csv ? \App\Services\Planning\Import\PlanFileTemplate::csv() : \App\Services\Planning\Import\PlanFileTemplate::xlsx();
        }, 'mau-ke-hoach-noi-dung.'.($csv ? 'csv' : 'xlsx'), ['Content-Type' => $csv ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function openImport(): void
    {
        $this->importGuard();
        $this->importOpen = true;
    }

    public function closeImport(): void
    {
        $this->importOpen = false;
        if ($this->importResult !== null) { // đã xong việc: lần mở sau bắt đầu lại từ đầu
            $this->discardImport();
        }
    }

    private function importKey(): string
    {
        return 'plan-import:'.Auth::id().':'.$this->importToken;
    }

    /** @return list<array{name:string, rows:list<list<string>>, total:int, truncated:bool}> */
    private function importSheets(): array
    {
        $d = $this->importToken ? Cache::get($this->importKey()) : null;
        if (! is_array($d)) {
            $this->importState = null;
            $this->importPreview = null;
            $this->importError = 'File đã hết hạn (phiên nhập chỉ giữ 2 giờ). Hãy chọn lại file.';

            return [];
        }

        return $d['sheets'];
    }

    /** @return array{name:string, rows:list<list<string>>, total:int, truncated:bool}|null */
    private function importSheet(array $sheets): ?array
    {
        foreach ($sheets as $s) {
            if ($s['name'] === ($this->importState['sheet'] ?? null)) {
                return $s;
            }
        }

        return null;
    }

    /** @return array<int,string> chỉ số cột => trường, theo những gì người dùng đang chọn */
    private function importFields(): array
    {
        $out = [];
        foreach ((array) ($this->importState['columns'] ?? []) as $c) {
            if (is_array($c) && isset($c['index'])) {
                $out[(int) $c['index']] = in_array($c['field'] ?? '', PlanFileFields::keys(), true) ? (string) $c['field'] : 'ignore';
            }
        }

        return $out;
    }

    /** @return list<int> số dòng (đề mục / bỏ qua) do AI báo, lấy lại từ trạng thái; mọi giá trị ép số nguyên và được kiểm lại khi dựng danh sách */
    private function importRows(string $key): array
    {
        return array_slice(array_values(array_filter(array_map('intval', (array) ($this->importState[$key] ?? [])), fn ($n) => $n > 0)), 0, 300);
    }

    /** Liên kết sang sheet khác (đã kiểm lại ở máy chủ: trạng thái trình duyệt có thể bị sửa). */
    private function importLinks(array $sheets): array
    {
        return PlanFileLinks::resolve($sheets, (string) ($this->importState['sheet'] ?? ''), (array) ($this->importState['links'] ?? []));
    }

    private function importHeader(array $sheet): int
    {
        return max(0, min((int) ($this->importState['header_row'] ?? 0), min(count($sheet['rows']) - 1, 50)));
    }

    public function updatedPlanFile(): void
    {
        $this->importGuard();
        $file = $this->planFile;
        if (! $file) {
            return;
        }
        $this->importResult = null;
        $this->importError = null;
        try {
            $sheets = (new SheetReader)->read($file->getRealPath(), $file->getClientOriginalExtension(), $file->getSize());
        } catch (InvalidArgumentException $e) {
            $this->importError = \App\Support\UserFacingError::from($e);

            return;
        } finally {
            $name = PlanFileFields::text((string) $file->getClientOriginalName(), 120);
            $this->planFile = null;
            try {
                $file->delete();
            } catch (\Throwable) {
            }
        }
        $this->importToken = bin2hex(random_bytes(8));
        Cache::put($this->importKey(), ['file' => $name, 'sheets' => $sheets], self::IMPORT_TTL);
        $first = $sheets[0];
        $header = PlanFileMapper::guessHeaderRow($first);
        $this->importPreview = null;
        $this->importState = [
            'file' => $name,
            'sheets' => array_map(fn ($s) => ['name' => $s['name'], 'total' => $s['total'], 'truncated' => $s['truncated']], $sheets),
            'sheet' => $first['name'], 'sheet_locked' => false, 'header_row' => $header, 'is_plan' => true, 'analyzed' => false, 'notes' => [], 'section_rows' => [], 'skip_rows' => [], 'links' => [], 'ai' => null,
            'columns' => PlanFileMapper::columns($first, $header, PlanFileHeuristics::seed($first, $header)),
        ];
        $this->runAnalysis();
    }

    /** Phân tích lại bằng AI (nút nhỏ trong hộp). */
    public function analyzeImport(): void
    {
        $this->importGuard();
        $this->runAnalysis();
    }

    /** Một lượt gọi AI (tốn phí): chỉ ĐỌC cấu trúc, chưa ghi gì. Lỗi hiện trong hộp; người dùng vẫn tự chọn cột được. */
    private function runAnalysis(): void
    {
        $sheets = $this->importSheets();
        if ($sheets === [] || $this->importState === null) {
            return;
        }
        $this->importError = null;
        // File làm đúng theo FILE MẪU của hệ thống: đọc trực tiếp (đúng sheet, đúng cột, nối sheet Chiến dịch), không gọi AI, không tốn phí, dùng được cả khi chưa kết nối AI.
        if (($rec = \App\Services\Planning\Import\PlanFileTemplate::recognize($sheets)) !== null) {
            $main = collect($sheets)->firstWhere('name', $rec['sheet']);
            $this->importState = array_merge($this->importState, [
                'sheet' => $rec['sheet'], 'sheet_locked' => true, 'header_row' => $rec['header_row'], 'is_plan' => true, 'analyzed' => true, 'section_rows' => [], 'skip_rows' => [], 'links' => $rec['links'],
                'columns' => PlanFileMapper::columns($main, $rec['header_row'], PlanFileHeuristics::seed($main, $rec['header_row'])),
                'notes' => ['Nhận ra file mẫu của hệ thống: đọc trực tiếp theo tên cột, không dùng AI.'],
                'ai' => ['label' => 'File mẫu của hệ thống (không dùng AI)', 'model' => null, 'cost_vnd' => 0],
            ]);
            $this->previewImport();
            $this->defaultTargetFromFile();

            return;
        }
        try {
            $r = app(PlanFileMapper::class)->map($sheets, ($this->importState['sheet_locked'] ?? false) ? (string) $this->importState['sheet'] : null);
        } catch (InvalidArgumentException|KeywordAiUnavailable $e) {
            $this->previewAfterAiFailure(\App\Support\UserFacingError::from($e).' Bạn vẫn có thể tự chọn cột bên dưới.');

            return;
        } catch (\Throwable $e) {
            report($e);
            $this->previewAfterAiFailure(\App\Support\UserFacingError::from($e).' Bạn vẫn có thể tự chọn cột bên dưới.');

            return;
        }
        $this->importState = array_merge($this->importState, [
            'sheet' => $r['sheet'], 'header_row' => $r['header_row'], 'columns' => $r['columns'], 'section_rows' => $r['section_rows'], 'skip_rows' => $r['skip_rows'], 'links' => $r['links'], 'notes' => $r['notes'], 'is_plan' => $r['is_plan'], 'analyzed' => true,
            'ai' => ['label' => $r['label'], 'model' => $r['model'], 'cost_vnd' => $r['cost_vnd']],
        ]);
        $this->previewImport();
        $this->defaultTargetFromFile();
    }

    /** AI lỗi/chưa kết nối: vẫn dựng bản xem trước từ cột đã đoán bằng tên cột, để kết quả hiện ngay; lời báo lỗi AI giữ lại. */
    private function previewAfterAiFailure(string $message): void
    {
        $this->previewImport();
        $this->defaultTargetFromFile(); // dựng lại bản xem trước nên làm trước, để không xóa lời báo bên dưới
        $this->importError = $this->importPreview !== null ? $message : ($this->importError ?? $message);
    }

    /**
     * File không nói bài thuộc chiến dịch nào (không có cột chiến dịch, không có dòng đề mục): mặc định gom vào MỘT chiến dịch mới
     * (tạm dừng, tên lấy từ tên file) để bài có chỗ chạy, thay vì lặng lẽ thành "bài lẻ" không có lịch. Chỉ khi người dùng chưa tự chọn.
     */
    private function defaultTargetFromFile(): void
    {
        $pv = $this->importPreview;
        if ($this->importTargetTouched || $this->importTarget !== PlanFileImporter::TARGET_BY_COLUMN || ! $pv || $pv['count'] === 0 || $pv['campaigns'] > 0) {
            return;
        }
        $this->importTarget = PlanFileImporter::TARGET_NEW;
        if (trim($this->importNewName) === '') {
            $this->importNewName = $this->defaultCampaignName();
        }
        $this->previewImport();
    }

    private function defaultCampaignName(): string
    {
        $base = pathinfo((string) ($this->importState['file'] ?? ''), PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[\s_]+/u', ' ', $base));

        return PlanFileFields::text($base !== '' ? $base : 'Kế hoạch nhập từ file', 120);
    }

    /** Nút "Gom vào chiến dịch mới" ở cảnh báo bài lẻ: một chạm thay vì tự tìm ô chọn. */
    public function importIntoNewCampaign(): void
    {
        $this->importGuard();
        $this->importTargetTouched = true;
        $this->importTarget = PlanFileImporter::TARGET_NEW;
        if (trim($this->importNewName) === '') {
            $this->importNewName = $this->defaultCampaignName();
        }
        $this->previewImport();
    }

    public function chooseImportSheet(string $name): void
    {
        $this->importGuard();
        foreach ($this->importSheets() as $s) {
            if ($s['name'] === $name && $this->importState !== null) {
                $h = PlanFileMapper::guessHeaderRow($s);
                $this->importPreview = null;
                $this->importState = array_merge($this->importState, ['sheet' => $name, 'sheet_locked' => true, 'header_row' => $h, 'analyzed' => false, 'notes' => [], 'section_rows' => [], 'skip_rows' => [], 'links' => [], 'columns' => PlanFileMapper::columns($s, $h, PlanFileHeuristics::seed($s, $h))]);
                $this->runAnalysis();

                return;
            }
        }
    }

    /** Đổi dòng tiêu đề (tùy chọn nâng cao): dựng lại tên cột và giá trị mẫu, GIỮ trường đã gán cho từng cột. */
    public function applyImportHeader(): void
    {
        $this->importGuard();
        $sheet = $this->importSheet($this->importSheets());
        if (! $sheet || $this->importState === null) {
            return;
        }
        $h = $this->importHeader($sheet);
        $keep = [];
        foreach ($this->importFields() as $i => $f) {
            $keep[$i] = ['field' => $f, 'confidence' => null, 'reason' => ''];
        }
        $this->importState['header_row'] = $h;
        $this->importState['columns'] = PlanFileMapper::columns($sheet, $h, $keep);
        $this->previewImport();
    }

    /** Mọi thay đổi người dùng làm trong hộp (đổi trường của cột, dòng tiêu đề, nơi nhận bài) cập nhật lại bản xem trước. */
    public function updatedImportState(mixed $value, string $key): void
    {
        $this->importGuard();
        if (str_starts_with($key, 'header_row')) {
            $this->applyImportHeader();
        } else {
            $this->previewImport();
        }
    }

    public function updatedImportTarget(): void
    {
        $this->importGuard();
        $this->importTargetTouched = true;
        $this->previewImport();
    }

    public function updatedImportNewName(): void
    {
        $this->importGuard();
        $this->previewImport();
    }

    /** Tính trước, KHÔNG ghi gì: bao nhiêu bài tạo được, dòng nào bị chặn và vì sao. Lỗi cấu hình hiện inline. */
    public function previewImport(): void
    {
        $this->importGuard();
        $site = $this->planSite();
        $sheets = $this->importSheets();
        $sheet = $this->importSheet($sheets);
        $this->importPreview = null;
        if (! $site || ! $sheet) {
            return;
        }
        try {
            $b = (new PlanFileImporter)->build($site, $sheet['rows'], $this->importFields(), $this->importHeader($sheet), $this->importRows('section_rows'), $this->importRows('skip_rows'), $this->importLinks($sheets));
        } catch (InvalidArgumentException $e) {
            $this->importError = \App\Support\UserFacingError::from($e);

            return;
        }
        // Tên chiến dịch hiển thị phải đúng nơi bài SẼ vào theo ô "Đưa bài vào đâu", không phải cột trong file khi người dùng chọn nơi khác.
        $where = match (true) {
            $this->importTarget === PlanFileImporter::TARGET_BY_COLUMN => null,
            $this->importTarget === PlanFileImporter::TARGET_NEW => trim(PlanFileFields::text($this->importNewName, 120)) ?: '(chưa nhập tên)',
            $this->importTarget === PlanFileImporter::TARGET_SOLO => '',
            default => (string) (Campaign::query()->where('website_id', $site->id)->whereKey((int) $this->importTarget)->value('name') ?? ''),
        };
        $items = array_map(fn ($i) => $where === null ? $i : array_merge($i, ['campaign' => $where !== '' ? $where : null]), $b['items']);
        $this->importError = null;
        $this->importPreview = [
            'count' => count($items),
            'campaigns' => count(array_unique(array_filter(array_column($items, 'campaign')))),
            'campaign_names' => array_slice(array_values(array_unique(array_filter(array_column($items, 'campaign')))), 0, 5),
            'solo' => count(array_filter($items, fn ($i) => empty($i['campaign']))),
            'dated' => count(array_filter(array_column($items, 'date'))),
            'volumes' => count(array_filter(array_column($items, 'volume'), fn ($v) => $v !== null)),
            'with_notes' => count(array_filter(array_column($items, 'guidance'))),
            'with_secondary' => count(array_filter(array_column($items, 'secondary'))),
            'dates_ok' => \App\Filament\Support\ContentPlan\PlanData::canPersistDates(),
            'problem_count' => count($b['problems']),
            'problems' => array_slice($b['problems'], 0, 40),
            'blank' => $b['blank'],
            'over_limit' => $b['over_limit'],
            'unlinked' => $b['unlinked'],
            'link_hits' => $b['link_hits'],
            'warnings' => $b['warnings'] ?? [],
            'difficulties' => count(array_filter(array_column($items, 'difficulty'), fn ($v) => $v !== null)),
            // Thông tin viết bài (brief) tìm thấy trong file: số bài có từng loại, số bài có ràng buộc ĐO ĐƯỢC, và những gì file chưa có (gợi ý bổ sung)
            'brief_cov' => array_filter(array_combine(\App\Services\Planning\Import\PlanBrief::keys(), array_map(fn ($k) => count(array_filter($items, fn ($i) => in_array($k, (array) ($i['brief'] ?? []), true))), \App\Services\Planning\Import\PlanBrief::keys()))),
            'measurable' => count(array_filter($items, fn ($i) => ! empty($i['measurable']))),
            'internal' => (function () use ($items) { // liên kết nội bộ: số khớp bài đã có/trong file, số URL (không kiểm), tên chưa có bài
                $m = 0;
                $u = 0;
                $miss = [];
                foreach ($items as $i) {
                    $m += (int) ($i['link_report']['match'] ?? 0);
                    $u += (int) ($i['link_report']['url'] ?? 0);
                    $miss = array_merge($miss, (array) ($i['link_report']['missing'] ?? []));
                }

                return ['match' => $m, 'url' => $u, 'missing' => array_slice(array_values(array_unique($miss)), 0, 8), 'missing_total' => count(array_unique($miss))];
            })(),
            'rows' => array_map(fn ($i) => ['row' => $i['row'], 'keyword' => $i['keyword'], 'campaign' => $i['campaign'], 'cluster' => $i['cluster'], 'date' => $i['date'], 'priority' => $i['priority'], 'intent' => $i['intent'], 'has_guidance' => $i['guidance'] !== null, 'guidance' => $i['guidance'], 'measurable' => $i['measurable'] ?? []], array_slice($items, 0, 30)),
        ];
    }

    /** Người duyệt bấm tạo: bài ở "Đã lên kế hoạch", chiến dịch mới ở trạng thái TẠM DỪNG; chưa gọi AI viết. */
    public function runImport(): void
    {
        $this->importGuard();
        $site = $this->planSite();
        $sheets = $this->importSheets();
        $sheet = $this->importSheet($sheets);
        if (! $site || ! $sheet) {
            return;
        }
        try {
            $imp = new PlanFileImporter;
            $b = $imp->build($site, $sheet['rows'], $this->importFields(), $this->importHeader($sheet), $this->importRows('section_rows'), $this->importRows('skip_rows'), $this->importLinks($sheets));
            $r = $imp->apply(Auth::user(), $site, $b['items'], $this->importTarget, $this->importNewName, (string) ($this->importState['file'] ?? ''));
        } catch (InvalidArgumentException $e) {
            $this->importError = \App\Support\UserFacingError::from($e);

            return;
        }
        $this->importResult = $r + ['blocked' => array_map(fn ($p) => "dòng {$p['row']}".($p['keyword'] !== '' ? " ({$p['keyword']})" : '').": {$p['reason']}", $b['problems'])];
        Notification::make()->title("Đã tạo {$r['created']} bài".($r['campaigns'] ? ' và '.count($r['campaigns']).' chiến dịch (tạm dừng)' : ''))
            ->body('Bài ở trạng thái Đã lên kế hoạch, chưa tốn token viết.')->success()->send();
        $this->discardImport(false);
    }

    public function discardImport(bool $clearResult = true): void
    {
        if ($this->importToken) {
            Cache::forget($this->importKey());
        }
        $this->importToken = null;
        $this->importState = null;
        $this->importPreview = null;
        $this->importError = null;
        $this->importNewName = '';
        $this->importTargetTouched = false;
        $this->importTarget = PlanFileImporter::TARGET_BY_COLUMN;
        if ($clearResult) {
            $this->importResult = null;
        }
    }
}
