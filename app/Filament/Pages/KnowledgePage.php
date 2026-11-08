<?php

namespace App\Filament\Pages;

use App\Models\KnowledgeGap;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeSource;
use App\Models\KnowledgeTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Knowledge\KnowledgeConflicts;
use App\Services\Knowledge\KnowledgeDedupe;
use App\Services\Knowledge\KnowledgeError;
use App\Services\Knowledge\KnowledgeFreshness;
use App\Services\Knowledge\KnowledgeIngest;
use App\Services\Knowledge\KnowledgeReview;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * AG-S20: Kho kiến thức (nhóm Khám phá). Tab: Hộp duyệt · Tất cả · Nguồn · Cảnh báo.
 * Quyền TẠM THỜI: admin + approver thao tác; người viết/biên tập chỉ xem atom đã duyệt ("quyền duyệt tính sau theo yêu cầu người dùng").
 * Nội dung kho và nguồn là DỮ LIỆU: luôn hiển thị qua {{ }} (escape), không bao giờ là HTML thô.
 */
class KnowledgePage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Kho kiến thức';

    protected static ?string $navigationGroup = 'Khám phá';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Kho kiến thức';

    protected static ?string $slug = 'kho-kien-thuc';

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
        return new KnowledgeReview();
    }

    private function guard(): void
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
            $r = (new KnowledgeIngest())->split(KnowledgeSource::query()->findOrFail($sourceId), Auth::user());
            Notification::make()->title("Tách được {$r['created']} mục".($r['duplicates'] ? ", bỏ {$r['duplicates']} mục trùng y hệt" : ''))->info()->send();
        });
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
            (new KnowledgeIngest())->manual($this->new, $site, Auth::user());
            $this->new = array_merge($this->new, ['title' => '', 'body' => '', 'entity' => '', 'field' => '', 'value' => '', 'value_unit' => '', 'tags' => '', 'valid_until' => '']);
        });
    }

    public function pasteDoc(): void
    {
        $this->act('Đã lưu nguồn', function () {
            $site = $this->paste['website_id'] !== '' ? Website::query()->findOrFail((int) $this->paste['website_id']) : null;
            $ingest = new KnowledgeIngest();
            $src = $ingest->paste((string) $this->paste['title'], (string) $this->paste['text'], $site, Auth::user());
            if ($this->paste['split']) {
                $r = $ingest->split($src, Auth::user());
                Notification::make()->title("Tách được {$r['created']} mục đề xuất".($r['duplicates'] ? ", bỏ {$r['duplicates']} mục trùng" : ''))->info()->send();
            }
            $this->paste = ['website_id' => $this->paste['website_id'], 'title' => '', 'text' => '', 'split' => true];
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
            KnowledgeTopic::create(['name' => mb_substr($name, 0, 120), 'parent_id' => $parent?->id, 'website_id' => $parent?->website_id]);
            $this->topicName = '';
        });
    }

    /** @return array<string,mixed> */
    protected function getViewData(): array
    {
        $manage = static::canManage();
        $conflicts = new KnowledgeConflicts();
        $dedupe = new KnowledgeDedupe();

        $flags = function (KnowledgeItem $i) use ($conflicts, $dedupe): array {
            $f = $dedupe->find($i);

            return ['conflict' => $conflicts->for($i), 'exact' => $f['exact'], 'similar' => $f['similar']];
        };

        $inbox = $manage ? KnowledgeItem::query()->with('source')->where('status', 'proposed')->orderBy('source_id')->orderBy('id')->limit(200)->get() : collect();
        $inboxFlags = $inbox->mapWithKeys(fn ($i) => [$i->id => $flags($i)]);

        $all = KnowledgeItem::query()->with(['topic', 'website'])
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
            $fresh = new KnowledgeFreshness();
            $warn = ['pairs' => $conflicts->all(), 'expired' => $fresh->expired(), 'unused' => $fresh->unused(), 'dups' => []];
            foreach (KnowledgeItem::query()->whereIn('status', ['proposed', 'approved'])->limit(300)->get() as $i) {
                foreach ($dedupe->find($i)['similar'] as $s) {
                    if ($i->id < $s['item']->id) {
                        $warn['dups'][] = ['a' => $i, 'b' => $s['item'], 'percent' => $s['percent']];
                    }
                }
            }
        }

        return [
            'manage' => $manage,
            'inbox' => $inbox, 'inboxFlags' => $inboxFlags,
            'all' => $all,
            'sources' => $manage ? KnowledgeSource::query()->withCount(['items', 'items as proposed_count' => fn ($q) => $q->where('status', 'proposed')])->with('website')->orderByDesc('id')->limit(100)->get() : collect(),
            'selected' => $selected, 'selectedFlags' => $selected ? $flags($selected) : null,
            'warn' => $warn, 'gaps' => $manage ? KnowledgeGap::query()->with('website')->orderByDesc('count')->limit(30)->get() : collect(),
            'topics' => KnowledgeTopic::query()->with('parent')->orderBy('name')->get(),
            'sites' => Website::query()->orderBy('name')->get(['id', 'name']),
            'types' => KnowledgeItem::TYPES, 'statuses' => KnowledgeItem::STATUSES,
        ];
    }
}
