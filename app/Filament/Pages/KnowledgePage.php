<?php

namespace App\Filament\Pages;

use App\Models\KnowledgeGap;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeSource;
use App\Models\KnowledgeTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Knowledge\KnowledgeAiIngest;
use App\Services\Knowledge\KnowledgeConflicts;
use App\Services\Knowledge\KnowledgeDedupe;
use App\Services\Knowledge\KnowledgeError;
use App\Services\Knowledge\KnowledgeFreshness;
use App\Services\Knowledge\KnowledgeIngest;
use App\Services\Knowledge\KnowledgeReview;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Livewire\WithFileUploads;

/**
 * AG-B1: màn này giờ là phần "Hiểu biết · Nguồn · Cảnh báo · Khoảng trống" của Kho trí tuệ. Nó vẫn chạy độc lập (xem kiến thức của TẤT CẢ Trí tuệ,
 * không hiện trên menu) và được BrainPage kế thừa, đặt $brainId để lọc mọi truy vấn/ghi theo một Trí tuệ.
 *
 * AG-S20: Kho kiến thức (nhóm Khám phá). Tab: Hộp duyệt · Tất cả · Nguồn · Cảnh báo.
 * Quyền TẠM THỜI: admin + approver thao tác; người viết/biên tập chỉ xem atom đã duyệt ("quyền duyệt tính sau theo yêu cầu người dùng").
 * Nội dung kho và nguồn là DỮ LIỆU: luôn hiển thị qua {{ }} (escape), không bao giờ là HTML thô.
 */
class KnowledgePage extends Page
{
    use WithFileUploads;

    /** Tệp .md/.txt người dùng thả vào thẻ "Dán tài liệu" (thay cho việc dán văn bản). */
    public $pasteFile = null;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Tất cả kiến thức';

    protected static ?string $navigationGroup = 'Khám phá';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Tất cả kiến thức';

    protected static ?string $slug = 'tri-thuc-tat-ca';

    /** Menu chỉ còn "Kho trí tuệ" (BrainLibraryPage). URL cũ /admin/kho-kien-thuc chuyển hướng ở routes/web.php. */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /** AG-B1: khác null = chỉ làm việc trong Trí tuệ này. */
    public ?int $brainId = null;

    protected static string $view = 'filament.pages.knowledge';

    public ?int $selectedId = null;

    public bool $editing = false;

    /** @var array<string,mixed> */
    public array $edit = [];

    /** @var array<string,mixed> */
    public array $new = ['website_id' => '', 'type' => 'fact', 'title' => '', 'body' => '', 'entity' => '', 'field' => '', 'value' => '', 'value_unit' => '', 'tags' => '', 'topic_id' => '', 'valid_until' => ''];

    /** @var array<string,mixed> */
    public array $paste = ['website_id' => '', 'title' => '', 'text' => '', 'split' => true];

    public string $topicName = '';

    public string $topicParent = '';

    public string $q = '';

    public string $fType = '';

    public string $fStatus = '';

    public string $fTopic = '';

    public string $fTag = '';

    public string $fSite = '';

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return $u instanceof User && $u->is_active && $u->isWriter();
    }

    public static function canManage(): bool
    {
        return KnowledgeReview::canManage(Auth::user());
    }

    private function review(): KnowledgeReview
    {
        return new KnowledgeReview;
    }

    protected function guard(): void
    {
        if (! static::canAccess()) {
            abort(403);
        }
        if (! static::canManage()) {
            abort(403, 'Bạn chưa có quyền thao tác kho kiến thức.');
        }
    }

    private function item(int $id): KnowledgeItem
    {
        return KnowledgeItem::query()->findOrFail($id);
    }

    private function act(string $ok, \Closure $fn): void
    {
        $this->guard();
        try {
            $fn();
            Notification::make()->title($ok)->success()->send();
        } catch (KnowledgeError|\InvalidArgumentException $e) {
            Notification::make()->title('Chưa thực hiện được')->body($e->getMessage())->danger()->send();
        }
    }

    public function approve(int $id): void
    {
        $this->act('Đã duyệt', fn () => $this->review()->approve($this->item($id), Auth::user()));
    }

    public function reject(int $id): void
    {
        $this->act('Đã loại', fn () => $this->review()->reject($this->item($id), Auth::user()));
    }

    public function outdate(int $id): void
    {
        $this->act('Đã đánh dấu cũ', fn () => $this->review()->markOutdated($this->item($id), Auth::user()));
    }

    public function pickCorrect(int $id): void
    {
        $this->act('Đã chọn bản đúng, các bản mâu thuẫn đã được loại/đánh dấu cũ', fn () => $this->review()->resolveConflict($this->item($id), Auth::user()));
    }

    public function mergeInto(int $keepId, int $dupId): void
    {
        $this->act('Đã gộp', fn () => $this->review()->merge($this->item($keepId), $this->item($dupId), Auth::user()));
    }

    public function approveSource(int $sourceId): void
    {
        $this->guard();
        $r = $this->review()->approveSource($sourceId, Auth::user());
        Notification::make()->title("Đã duyệt {$r['approved']} mục".($r['blocked'] ? ", bỏ qua {$r['blocked']} mục còn mâu thuẫn" : ''))->success()->send();
    }

    public function splitSource(int $sourceId): void
    {
        $this->act('Đã tách thành mục đề xuất', function () use ($sourceId) {
            $r = (new KnowledgeIngest)->split(KnowledgeSource::query()->findOrFail($sourceId), Auth::user());
            Notification::make()->title("Tách được {$r['created']} mục".($r['duplicates'] ? ", bỏ {$r['duplicates']} mục trùng y hệt" : ''))->info()->send();
        });
    }

    /** AG-S21: AI ĐỀ XUẤT tách nguồn; mọi mục vào Hộp duyệt ở trạng thái 'Đề xuất'. */
    public function splitSourceAi(int $sourceId): void
    {
        $this->guard();
        try {
            $r = (new KnowledgeAiIngest)->run(KnowledgeSource::query()->findOrFail($sourceId), Auth::user());
        } catch (KnowledgeError $e) {
            Notification::make()->title('Chưa tách được bằng AI')->body($e->getMessage())->danger()->send();

            return;
        }
        $stop = ['item_cap' => 'đạt trần số mục', 'budget' => 'đạt trần chi phí', 'cost_cap' => 'một lần gọi vượt trần chi phí', 'chunk_cap' => 'nguồn dài, mới xử lý phần đầu', 'error' => 'AI báo lỗi'][$r['stopped'] ?? ''] ?? null;
        $n = Notification::make()->title("AI đề xuất {$r['proposed']} mục (chờ bạn duyệt)")
            ->body(trim("Token: {$r['tokens']} · chi phí ≈ ".number_format($r['cost_vnd'], 0, ',', '.').' đ'.($r['duplicates'] ? " · bỏ {$r['duplicates']} mục trùng y hệt" : '').($r['invalid'] + $r['injection_dropped'] ? ' · loại '.($r['invalid'] + $r['injection_dropped']).' mục không hợp lệ' : '').($stop ? " · dừng: {$stop}" : '')));
        ($r['stopped'] === 'error' || ($r['proposed'] === 0 && $r['chunks_failed'] > 0) ? $n->warning() : $n->success())->send();
    }

    /** AG-S21: mở form "Thêm kiến thức" có sẵn tiêu đề từ khoảng trống (chuyển tab do nút phía giao diện). */
    public function prefillFromGap(int $gapId): void
    {
        $this->guard();
        $gap = KnowledgeGap::query()->findOrFail($gapId);
        $this->new = array_merge($this->new, ['website_id' => (string) $gap->website_id, 'title' => mb_substr($gap->query, 0, 200), 'body' => '']);
    }

    public function openItem(int $id): void
    {
        $item = $this->item($id);
        if (! static::canManage() && $item->status !== 'approved') {
            abort(403);
        }
        $this->selectedId = $id;
        $this->editing = false;
    }

    public function closeItem(): void
    {
        $this->selectedId = null;
        $this->editing = false;
    }

    public function startEdit(): void
    {
        $this->guard();
        $i = $this->item((int) $this->selectedId);
        $this->edit = $i->only(['type', 'title', 'body', 'entity', 'field', 'value', 'value_unit', 'topic_id']) + ['tags' => implode(', ', $i->tags ?? []), 'valid_until' => $i->valid_until?->format('Y-m-d')];
        $this->editing = true;
    }

    public function saveEdit(): void
    {
        $this->act('Đã lưu', function () {
            $i = $this->item((int) $this->selectedId);
            $before = $i->version;
            $this->review()->update($i, $this->edit, Auth::user());
            $this->editing = false;
            if ($i->refresh()->version > $before && $i->status === 'proposed') {
                Notification::make()->title('Nội dung đã đổi: tạo phiên bản mới, mục về Đề xuất để duyệt lại')->warning()->send();
            }
        });
    }

    public function addItem(): void
    {
        $this->act('Đã thêm vào Hộp duyệt', function () {
            $site = $this->new['website_id'] !== '' ? Website::query()->findOrFail((int) $this->new['website_id']) : null;
            (new KnowledgeIngest)->manual($this->new + ['brain_id' => $this->brainId], $site, Auth::user());
            $this->new = array_merge($this->new, ['title' => '', 'body' => '', 'entity' => '', 'field' => '', 'value' => '', 'value_unit' => '', 'tags' => '', 'valid_until' => '']);
        });
    }

    public function pasteDoc(): void
    {
        $this->act('Đã lưu nguồn', function () {
            $site = $this->paste['website_id'] !== '' ? Website::query()->findOrFail((int) $this->paste['website_id']) : null;
            $title = (string) $this->paste['title'];
            $text = (string) $this->paste['text'];
            if ($this->pasteFile) {
                $ext = strtolower($this->pasteFile->getClientOriginalExtension());
                if (! in_array($ext, ['md', 'txt'], true) || $this->pasteFile->getSize() > 200 * 1024) {
                    throw new KnowledgeError('Chỉ nhận tệp .md hoặc .txt dưới 200 KB.');
                }
                $text = (string) file_get_contents($this->pasteFile->getRealPath());
                if (! mb_check_encoding($text, 'UTF-8')) {
                    throw new KnowledgeError('Tệp cần mã hóa UTF-8.');
                }
                $title = $title !== '' ? $title : pathinfo($this->pasteFile->getClientOriginalName(), PATHINFO_FILENAME);
            }
            $ingest = new KnowledgeIngest;
            $src = $ingest->paste($title, $text, $site, Auth::user(), 'paste', null, $this->brainId);
            if ($this->paste['split']) {
                $r = $ingest->split($src, Auth::user());
                Notification::make()->title("Tách được {$r['created']} mục đề xuất".($r['duplicates'] ? ", bỏ {$r['duplicates']} mục trùng" : ''))->info()->send();
            }
            $this->paste = ['website_id' => $this->paste['website_id'], 'title' => '', 'text' => '', 'split' => true];
            $this->pasteFile = null;
        });
    }

    public function addTopic(): void
    {
        $this->act('Đã thêm chủ đề', function () {
            $name = trim($this->topicName);
            if ($name === '') {
                throw new KnowledgeError('Nhập tên chủ đề.');
            }
            $parent = $this->topicParent !== '' ? KnowledgeTopic::query()->findOrFail((int) $this->topicParent) : null;
            if ($parent && $parent->parent_id !== null) {
                throw new KnowledgeError('Chủ đề chỉ có tối đa 2 cấp.');
            }
            KnowledgeTopic::create(['name' => mb_substr($name, 0, 120), 'parent_id' => $parent?->id, 'website_id' => $parent?->website_id, 'brain_id' => $this->brainId ?? $parent?->brain_id]);
            $this->topicName = '';
        });
    }

    /** @return array<string,mixed> */
    protected function getViewData(): array
    {
        $manage = static::canManage();
        $conflicts = new KnowledgeConflicts;
        $dedupe = new KnowledgeDedupe;

        $flags = function (KnowledgeItem $i) use ($conflicts, $dedupe): array {
            $f = $dedupe->find($i);

            return ['conflict' => $conflicts->for($i), 'exact' => $f['exact'], 'similar' => $f['similar']];
        };

        $b = $this->brainId;
        $inScope = fn ($q) => $q->when($b !== null, fn ($q2) => $q2->where('brain_id', $b));
        $inbox = $manage ? KnowledgeItem::query()->tap($inScope)->with('source')->where('status', 'proposed')->orderBy('source_id')->orderBy('id')->limit(200)->get() : collect();
        $inboxFlags = $inbox->mapWithKeys(fn ($i) => [$i->id => $flags($i)]);

        $all = KnowledgeItem::query()->tap($inScope)->with(['topic', 'website'])
            ->when(! $manage, fn ($q) => $q->where('status', 'approved'))
            ->when($manage && $this->fStatus !== '', fn ($q) => $q->where('status', $this->fStatus))
            ->when($this->fType !== '', fn ($q) => $q->where('type', $this->fType))
            ->when($this->fTopic !== '', fn ($q) => $q->where('topic_id', (int) $this->fTopic))
            ->when($this->fSite === 'general', fn ($q) => $q->whereNull('website_id'))
            ->when(ctype_digit($this->fSite), fn ($q) => $q->where('website_id', (int) $this->fSite))
            ->when(trim($this->fTag) !== '', fn ($q) => $q->where('tags', 'like', '%"'.addcslashes(trim($this->fTag), '\\%_"').'"%'))
            ->when(trim($this->q) !== '', function ($q) {
                $t = '%'.addcslashes(trim($this->q), '\\%_').'%';
                $q->where(fn ($q2) => $q2->where('title', 'like', $t)->orWhere('body', 'like', $t)->orWhere('entity', 'like', $t));
            })
            ->orderByDesc('updated_at')->limit(200)->get();

        $selected = $this->selectedId ? KnowledgeItem::query()->with(['source', 'topic', 'versions', 'usages.articleVersion.article'])->find($this->selectedId) : null;
        if ($selected && ! $manage && $selected->status !== 'approved') {
            $selected = null;
        }

        $warn = ['pairs' => [], 'expired' => collect(), 'unused' => collect(), 'dups' => []];
        if ($manage) {
            $fresh = new KnowledgeFreshness;
            $same = fn ($i) => $b === null || (int) $i->brain_id === $b;
            $warn = ['pairs' => array_values(array_filter($conflicts->all(), fn ($p) => $same($p['a']))), 'expired' => $fresh->expired()->filter($same)->values(), 'unused' => $fresh->unused()->filter($same)->values(), 'dups' => []];
            foreach (KnowledgeItem::query()->tap($inScope)->whereIn('status', ['proposed', 'approved'])->limit(300)->get() as $i) {
                foreach ($dedupe->find($i)['similar'] as $s) {
                    if ($i->id < $s['item']->id) {
                        $warn['dups'][] = ['a' => $i, 'b' => $s['item'], 'percent' => $s['percent']];
                    }
                }
            }
        }

        return [
            'manage' => $manage, 'brain' => null,
            'inbox' => $inbox, 'inboxFlags' => $inboxFlags,
            'all' => $all,
            'sources' => $manage ? KnowledgeSource::query()->tap($inScope)->withCount(['items', 'items as proposed_count' => fn ($q) => $q->where('status', 'proposed')])->with('website')->orderByDesc('id')->limit(100)->get() : collect(),
            'selected' => $selected, 'selectedFlags' => $selected ? $flags($selected) : null,
            'warn' => $warn,
            'gaps' => $manage ? KnowledgeGap::query()->tap($inScope)->with(['website', 'article', 'filledItem'])->orderByRaw("status = 'open' desc")->orderByDesc('count')->limit(100)->get() : collect(),
            'aiAvail' => $manage ? (new KnowledgeAiIngest)->availability() : ['ok' => false, 'reason' => null, 'label' => null, 'demo' => false],
            'topics' => KnowledgeTopic::query()->tap($inScope)->with('parent')->orderBy('name')->get(),
            'sites' => Website::query()->orderBy('name')->get(['id', 'name']),
            'types' => KnowledgeItem::TYPES, 'statuses' => KnowledgeItem::STATUSES,
        ];
    }
}
