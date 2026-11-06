<?php

namespace App\Filament\Pages;

use App\Filament\Support\Demo\DemoMode;
use App\Filament\Support\Keywords\CsvKeywordParser;
use App\Filament\Support\Keywords\KeywordIntent;
use App\Filament\Support\Keywords\KeywordService;
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

    public string $search = '';

    public string $filterCluster = '';

    public string $filterIntent = '';

    public string $filterSource = '';

    public string $sortBy = 'alpha';

    public int $limit = 50;

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

        return $u instanceof \App\Models\User && $u->is_active && $u->isWriter();
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
        $n = KeywordService::make()->propose();
        $this->proposalNames = [];
        if ($n === 0) {
            Notification::make()->title('Không còn từ khóa chưa gom cụm')->warning()->send();
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

    public function showMore(): void
    {
        $this->limit += 50;
    }

    /** @return array<string,mixed> */
    public function getViewData(): array
    {
        $demo = DemoMode::state(request()->query('demo'), ['trong'], '');
        $repo = KeywordService::make()->repo();
        $all = $demo === 'trong' ? [] : $repo->all();
        $rows = array_values(array_filter($all, function ($r) {
            if ($this->search !== '' && mb_stripos($r['keyword'].' '.($r['cluster'] ?? ''), $this->search) === false) {
                return false;
            }
            if ($this->filterCluster === '__none') {
                if ($r['cluster'] !== null) {
                    return false;
                }
            } elseif ($this->filterCluster !== '' && $r['cluster'] !== $this->filterCluster) {
                return false;
            }

            return ($this->filterIntent === '' || $r['intent'] === $this->filterIntent) && ($this->filterSource === '' || $r['source'] === $this->filterSource);
        }));
        usort($rows, match ($this->sortBy) {
            'volume' => fn ($a, $b) => ($b['volume'] ?? -1) <=> ($a['volume'] ?? -1) ?: $a['keyword'] <=> $b['keyword'],
            'newest' => fn ($a, $b) => $b['id'] <=> $a['id'],
            default => fn ($a, $b) => mb_strtolower($a['keyword']) <=> mb_strtolower($b['keyword']),
        });
        $clusters = $demo === 'trong' ? [] : $repo->clusters();

        return [
            'total' => count($all),
            'clustered' => count(array_filter($all, fn ($r) => $r['cluster'] !== null)),
            'filteredCount' => count($rows),
            'rows' => array_slice($rows, 0, $this->limit),
            'clusters' => $clusters,
            'proposals' => $repo->proposals(),
            'intents' => KeywordIntent::LABELS,
            'demoOn' => DemoMode::enabled(),
        ];
    }
}
