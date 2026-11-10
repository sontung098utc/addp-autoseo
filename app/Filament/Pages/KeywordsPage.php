<?php

namespace App\Filament\Pages;

use App\Filament\Support\Demo\DemoMode;
use App\Filament\Support\Keywords\CsvKeywordParser;
use App\Filament\Support\Keywords\KeywordArticleLinks;
use App\Filament\Support\Keywords\KeywordIntent;
use App\Filament\Support\Keywords\KeywordService;
use App\Models\Keyword;
use App\Models\User;
use App\Models\Website;
use App\Services\Keywords\KeywordAiResolver;
use App\Services\Keywords\KeywordAiUnavailable;
use App\Services\Keywords\KeywordResearchService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\WithFileUploads;

class KeywordsPage extends Page
{
    use WithFileUploads;

    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static ?string $navigationLabel = 'Từ khóa';

    protected static ?string $navigationGroup = 'Khám phá';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Từ khóa';

    protected static ?string $slug = 'tu-khoa';

    protected static string $view = 'filament.pages.keywords';

    public string $csvText = '';

    public $csvFile = null;

    /** @var array<string,mixed>|null kết quả xem trước (chỉ để hiển thị; khi Xác nhận sẽ phân tích lại phía máy chủ) */
    public ?array $preview = null;

    public string $newKeyword = '';

    public string $newVolume = '';

    public string $newIntent = 'informational';

    public ?string $renaming = null;

    public string $renameTo = '';

    /** @var array<int,string> tên mới cho từng đề xuất */
    public array $proposalNames = [];

    public ?int $splitting = null;

    /** @var list<string> */
    public array $splitKeywords = [];

    public string $splitName = '';

    public ?int $mergeFrom = null;

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return $u instanceof User && $u->is_active && $u->isWriter();
    }

    private function guard(): bool
    {
        if (! static::canAccess()) {
            abort(403);
        }

        return true;
    }

    private function fail(string $title, string $body): void
    {
        Notification::make()->title($title)->body($body)->danger()->send();
    }

    public function updatedCsvFile(): void
    {
        $this->guard();
        if ($this->csvFile) {
            if ($this->csvFile->getSize() > CsvKeywordParser::MAX_BYTES) {
                $this->csvText = '';
                $this->preview = ['fatal' => 'Tệp vượt quá 1 MB. Hãy tách thành nhiều tệp nhỏ hơn.', 'rows' => [], 'errors' => [], 'duplicates' => []];

                return;
            }
            $this->csvText = (string) file_get_contents($this->csvFile->getRealPath());
            $this->previewCsv();
        }
    }

    public function previewCsv(): void
    {
        $this->guard();
        $r = (new CsvKeywordParser)->parse($this->csvText, KeywordService::make()->existingKeywords());
        $this->preview = $r;
    }

    public function loadSample(): void
    {
        $this->csvText = "keyword,volume,intent\nhọc bổng du học Pháp,720,informational\nđăng ký học thử online,300,transactional\n";
        $this->previewCsv();
    }

    public function cancelImport(): void
    {
        $this->csvText = '';
        $this->csvFile = null;
        $this->preview = null;
    }

    /** Lưu thật sau khi người bấm Xác nhận. Phân tích lại từ nội dung, không tin dữ liệu xem trước ở trình duyệt. */
    public function confirmImport(): void
    {
        $this->guard();
        $r = (new CsvKeywordParser)->parse($this->csvText, KeywordService::make()->existingKeywords());
        if ($r['fatal'] || $r['rows'] === []) {
            $this->fail('Chưa nhập được', $r['fatal'] ?? 'Không có dòng hợp lệ nào để lưu.');

            return;
        }
        $n = KeywordService::make()->addRows($r['rows'], 'csv');
        $this->cancelImport();
        Notification::make()->title('Đã nhập '.$n.' từ khóa')->body(count($r['errors']).' dòng lỗi và '.count($r['duplicates']).' dòng trùng đã được bỏ qua.')->success()->send();
    }

    /** G3: nút "Khảo sát thị trường (AI)". Chỉ writer trở lên; tắt kèm lý do khi chưa có AI. */
    protected function getHeaderActions(): array
    {
        $av = KeywordAiResolver::availability('keyword_suggest');
        $site = KeywordService::defaultSite();
        $off = ! $av['ok'] ? $av['reason'] : ($site === null || ! KeywordService::useReal() ? 'Cần một website đang hoạt động và dữ liệu thật (không phải bản minh họa).' : null);

        return [
            Action::make('marketResearch')
                ->label('Khảo sát thị trường (AI)')
                ->icon('heroicon-o-sparkles')
                ->visible(fn () => static::canAccess())
                ->disabled($off !== null)
                ->tooltip($off)
                ->modalHeading('Khảo sát thị trường (AI)')
                ->modalSubmitActionLabel('Chạy khảo sát')
                ->form([
                    Placeholder::make('note')->label('')->content('AI không biết lượt tìm kiếm. Từ khóa mới sẽ có nhãn \'AI đề xuất\' và cột lượt tìm để trống; muốn có lượt tìm hãy nhập CSV hoặc nối Search Console.'),
                    Textarea::make('seeds')->label('Hạt giống (tùy chọn, mỗi dòng một từ)')->rows(4)->helperText('Để trống: dùng từ khóa đã có và thông tin thương hiệu của website.'),
                    TextInput::make('limit')->label('Số từ khóa tối đa')->numeric()->default(KeywordResearchService::DEFAULT_LIMIT)->minValue(1)->maxValue(KeywordResearchService::HARD_LIMIT)->required(),
                ])
                ->action(function (array $data) use ($site) {
                    $this->guard();
                    $this->runMarketResearch($site, (string) ($data['seeds'] ?? ''), (int) ($data['limit'] ?? 0));
                }),
        ];
    }

    public function runMarketResearch(?Website $site, string $seedText, int $limit): void
    {
        $this->guard();
        if ($site === null) {
            $this->fail('Chưa chạy được', 'Chưa có website đang hoạt động.');

            return;
        }
        $seeds = array_values(array_filter(array_map('trim', preg_split('/\R/u', $seedText) ?: []), fn ($x) => $x !== ''));
        try {
            $r = (new KeywordResearchService)->suggest($site, $seeds === [] ? null : $seeds, $limit > 0 ? $limit : null);
        } catch (KeywordAiUnavailable $e) {
            $this->fail('Chưa có AI', $e->getMessage());

            return;
        }
        if ($r['message'] !== null && $r['added'] === 0) {
            $this->fail('Khảo sát chưa thêm từ khóa', $r['message']);

            return;
        }
        $n = Notification::make()->title('Đã thêm '.$r['added'].' từ khóa (AI đề xuất)')
            ->body($r['skipped_duplicates'].' từ trùng đã bỏ. Chi phí '.$r['cost_vnd'].' đ ('.$r['label'].'). Lượt tìm để trống.'.($r['demo'] ? ' Đây là AI giả lập, không phải kết quả thật.' : ''));
        $r['message'] !== null ? $n->warning()->send() : $n->success()->send();
    }

    public function addKeyword(): void
    {
        $this->guard();
        try {
            KeywordService::make()->addManual($this->newKeyword, $this->newVolume, $this->newIntent);
        } catch (InvalidArgumentException $e) {
            $this->fail('Không thêm được từ khóa', $e->getMessage());

            return;
        }
        $this->newKeyword = '';
        $this->newVolume = '';
        Notification::make()->title('Đã thêm từ khóa')->success()->send();
    }

    /** Nút "Xóa" ở bảng chỉ mở hộp xác nhận; việc xóa thật nằm ở deleteKeyword, chạy sau khi người dùng xác nhận. */
    public function confirmDeleteKeyword(int $id): void
    {
        $this->guard();
        $this->mountAction('deleteKeywordConfirm', ['id' => $id]);
    }

    public function deleteKeywordConfirmAction(): Action
    {
        return Action::make('deleteKeywordConfirm')
            ->requiresConfirmation()
            ->modalHeading('Xóa từ khóa này?')
            ->modalDescription('Bài viết đã tạo từ từ khóa này không bị ảnh hưởng. Việc xóa không hoàn tác được.')
            ->modalSubmitActionLabel('Xóa từ khóa')
            ->color('danger')
            ->action(fn (array $arguments) => $this->deleteKeyword((int) ($arguments['id'] ?? 0)));
    }

    public function deleteKeyword(int $id): void
    {
        $this->guard();
        KeywordService::make()->delete($id);
        Notification::make()->title('Đã xóa từ khóa')->body('Bài viết đã tạo từ từ khóa này không bị ảnh hưởng.')->success()->send();
    }

    public function startRename(string $cluster): void
    {
        $this->renaming = $cluster;
        $this->renameTo = $cluster;
    }

    public function saveRename(): void
    {
        $this->guard();
        try {
            KeywordService::make()->renameCluster((string) $this->renaming, $this->renameTo);
        } catch (InvalidArgumentException $e) {
            $this->fail('Không đổi được tên cụm', $e->getMessage());

            return;
        }
        $this->renaming = null;
    }

    public function propose(): void
    {
        $this->guard();
        $service = KeywordService::make();
        $this->proposalNames = [];
        if (KeywordService::useReal() && KeywordAiResolver::availability('keyword_cluster')['ok']) {
            try {
                $r = $service->proposeWithAi();
            } catch (KeywordAiUnavailable $e) {
                $this->fail('Chưa gom cụm được bằng AI', $e->getMessage());

                return;
            }
            if ($r['proposals'] === 0) {
                Notification::make()->title('Không còn từ khóa chưa gom cụm')->warning()->send();
            } else {
                Notification::make()->title("AI đề xuất {$r['proposals']} cụm")
                    ->body('Chi phí '.number_format($r['cost_vnd'], 0, ',', '.').' đ'.($r['demo'] ? ' (AI giả lập)' : '').($r['stopped_reason'] ? '. '.$r['stopped_reason'] : '').' Bạn xem và nhận từng cụm.')->success()->send();
            }

            return;
        }
        // Chưa có AI thật: dùng bản gom cụm minh họa, nói rõ trên màn (nhãn đề xuất đổi thành "minh họa").
        $n = $service->propose();
        if ($n === 0) {
            Notification::make()->title('Không còn từ khóa chưa gom cụm')->warning()->send();
        } else {
            Notification::make()->title("Đề xuất {$n} cụm bằng bản minh họa")->body('Chưa kết nối AI nên dùng gom cụm theo luật, không phải AI.')->warning()->send();
        }
    }

    private function run(callable $fn): void
    {
        $this->guard();
        try {
            $fn(KeywordService::make());
        } catch (InvalidArgumentException $e) {
            $this->fail('Không thực hiện được', $e->getMessage());
        }
    }

    public function acceptProposal(int $id): void
    {
        $this->run(function (KeywordService $s) use ($id) {
            if (isset($this->proposalNames[$id]) && trim($this->proposalNames[$id]) !== '') {
                $s->renameProposal($id, $this->proposalNames[$id]);
            }
            $s->accept($id);
        });
    }

    public function discardProposal(int $id): void
    {
        $this->run(fn (KeywordService $s) => $s->discard($id));
    }

    public function startMerge(int $id): void
    {
        $this->mergeFrom = $this->mergeFrom === $id ? null : $id;
    }

    /** Gộp đề xuất đang chọn ($mergeFrom) vào đề xuất $into. */
    public function mergeInto(int $into): void
    {
        $from = $this->mergeFrom;
        $this->run(fn (KeywordService $s) => $s->mergeProposals($into, (int) $from));
        $this->mergeFrom = null;
    }

    public function startSplit(int $id): void
    {
        $this->splitting = $id;
        $this->splitKeywords = [];
        $this->splitName = '';
    }

    public function saveSplit(): void
    {
        $this->run(fn (KeywordService $s) => $s->splitProposal((int) $this->splitting, $this->splitKeywords, $this->splitName));
        $this->splitting = null;
    }

    /** Sửa tên cụm theo id dòng trong bảng (vị trí trong danh sách cụm). */
    public function startRenameById(int $id): void
    {
        $this->guard();
        $c = KeywordService::make()->repo()->clusters()[$id] ?? null;
        if ($c !== null) {
            $this->startRename($c['name']);
        }
    }

    /** @return array<string,mixed> */
    public function getViewData(): array
    {
        $demo = DemoMode::state(request()->query('demo'), ['trong'], '');
        $repo = KeywordService::make()->repo();
        $site = KeywordService::defaultSite();
        $all = $demo === 'trong' ? [] : $repo->all();
        $clusters = $demo === 'trong' ? [] : $repo->clusters();

        return [
            'total' => count($all),
            'clustered' => count(array_filter($all, fn ($r) => $r['cluster'] !== null)),
            'kwColumns' => $this->keywordColumns(),
            'kwRows' => $this->keywordRows($all, $site),
            'real' => KeywordService::useReal() && $site !== null,
            'clusterColumns' => $this->clusterColumns(),
            'clusterRows' => $this->clusterRows($clusters),
            'clusters' => $clusters,
            'proposals' => $repo->proposals(),
            'proposalSource' => $repo->proposalSource(),
            'intents' => KeywordIntent::LABELS,
            'demoOn' => DemoMode::enabled(),
        ];
    }

    private const SOURCES = ['csv' => 'CSV', 'tay' => 'Nhập tay', 'ai' => 'AI đề xuất', 'gsc' => 'Search Console', 'crawl' => 'Trang đã quét', 'competitor' => 'Đối thủ'];

    /** @return list<array<string,mixed>> */
    public function keywordColumns(): array
    {
        return [
            ['key' => 'keyword', 'label' => 'Từ khóa', 'type' => 'text', 'sortable' => true],
            ['key' => 'intent', 'label' => 'Ý định', 'type' => 'badge', 'sortable' => true, 'filter' => true, 'tones' => ['Thông tin' => 'info', 'Thương mại' => 'accent', 'Giao dịch' => 'warn', 'Điều hướng' => 'muted']],
            ['key' => 'cluster', 'label' => 'Cụm chủ đề', 'type' => 'text', 'sortable' => true, 'filter' => true],
            ['key' => 'volume', 'label' => 'Lượng tìm', 'type' => 'number', 'sortable' => true],
            ['key' => 'source', 'label' => 'Nguồn', 'type' => 'badge', 'sortable' => true, 'filter' => true, 'tones' => ['CSV' => 'info', 'Nhập tay' => 'muted', 'AI đề xuất' => 'accent', 'Search Console' => 'ok', 'Trang đã quét' => 'muted', 'Đối thủ' => 'warn']],
            ['key' => 'article', 'label' => 'Bài', 'type' => 'link', 'sortable' => true],
            ['key' => 'act', 'label' => '', 'type' => 'actions', 'actions' => [['label' => 'Xóa', 'method' => 'confirmDeleteKeyword', 'tone' => 'muted']]],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $all
     * @return list<array<string,mixed>>
     */
    public function keywordRows(array $all, ?Website $site = null): array
    {
        $links = KeywordArticleLinks::for($site, array_column($all, 'keyword'));

        return array_map(fn ($r) => [
            'article' => ['text' => ($l = $links[Keyword::keyFor($r['keyword'])] ?? KeywordArticleLinks::none())['label'], 'href' => $l['href']],
            'id' => $r['id'],
            'keyword' => $r['keyword'],
            'intent' => KeywordIntent::LABELS[$r['intent']] ?? $r['intent'],
            'cluster' => $r['cluster'] ?? 'Chưa gom cụm',
            'volume' => $r['volume'],
            'source' => self::SOURCES[$r['source']] ?? $r['source'],
        ], $all);
    }

    /** @return list<array<string,mixed>> */
    public function clusterColumns(): array
    {
        return [
            ['key' => 'name', 'label' => 'Cụm', 'type' => 'text', 'sortable' => true],
            ['key' => 'count', 'label' => 'Số từ khóa', 'type' => 'number', 'sortable' => true],
            ['key' => 'intent', 'label' => 'Ý định', 'type' => 'badge', 'sortable' => true, 'filter' => true, 'tones' => ['Thông tin' => 'info', 'Thương mại' => 'accent', 'Giao dịch' => 'warn', 'Điều hướng' => 'muted']],
            ['key' => 'act', 'label' => '', 'type' => 'actions', 'actions' => [['label' => 'Sửa tên', 'method' => 'startRenameById']]],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $clusters
     * @return list<array<string,mixed>>
     */
    public function clusterRows(array $clusters): array
    {
        $out = [];
        foreach (array_values($clusters) as $i => $c) {
            $out[] = ['id' => $i, 'name' => $c['name'], 'count' => count($c['keywords']), 'intent' => KeywordIntent::LABELS[$c['intent']] ?? $c['intent']];
        }

        return $out;
    }
}
