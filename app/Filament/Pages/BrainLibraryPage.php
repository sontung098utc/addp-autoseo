<?php

namespace App\Filament\Pages;

use App\Models\Brain;
use App\Models\KnowledgeGap;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeSource;
use App\Models\User;
use App\Models\Website;
use App\Services\Brain\BrainService;
use App\Services\Brain\BrainTemplates;
use App\Services\Knowledge\KnowledgeConflicts;
use App\Services\Knowledge\KnowledgeError;
use App\Services\Knowledge\KnowledgeFreshness;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * AG-B1: Kho trí tuệ — Thư viện (nhóm Khám phá, thay mục "Kho kiến thức"). Lưới thẻ Trí tuệ với số đếm và độ khỏe lấy từ dữ liệu thật.
 * Quyền TẠM THỜI: admin + approver tạo/sửa; biên tập viên chỉ xem (quyền duyệt tính sau theo yêu cầu người dùng).
 */
class BrainLibraryPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationLabel = 'Kho trí tuệ';

    protected static ?string $navigationGroup = 'Khám phá';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Kho trí tuệ';

    protected static ?string $slug = 'kho-tri-tue';

    protected static string $view = 'filament.pages.brain-library';

    public bool $showCreate = false;

    public bool $showArchived = false;

    /** @var array{name:string,template:string,websites:array<int,string>} */
    public array $create = ['name' => '', 'template' => 'thuong_hieu', 'websites' => []];

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return $u instanceof User && $u->is_active && $u->isWriter();
    }

    public static function canManage(): bool
    {
        return BrainService::canManage(Auth::user());
    }

    public function openCreate(): void
    {
        abort_unless(static::canAccess() && static::canManage(), 403);
        $this->create = ['name' => '', 'template' => 'thuong_hieu', 'websites' => []];
        $this->showCreate = true;
    }

    public function closeCreate(): void
    {
        $this->showCreate = false;
    }

    public function createBrain(): mixed
    {
        abort_unless(static::canAccess() && static::canManage(), 403);
        try {
            $brain = (new BrainService())->createFromTemplate((string) $this->create['name'], (string) $this->create['template'] ?: null, array_map('intval', (array) $this->create['websites']), Auth::user());
        } catch (KnowledgeError|\InvalidArgumentException $e) {
            Notification::make()->title('Chưa tạo được')->body($e->getMessage())->danger()->send();

            return null;
        }
        $this->showCreate = false;
        Notification::make()->title('Đã tạo Trí tuệ "'.$brain->name.'"')->body('Hồ sơ lĩnh vực mới chỉ có khung gợi ý. Hãy điền giọng, thuật ngữ và quy tắc lập luận.')->success()->send();

        return $this->redirect(BrainPage::getUrl(['brain' => $brain->id]));
    }

    /** @return array<string,mixed> */
    protected function getViewData(): array
    {
        $archived = $this->showArchived;
        $brains = Brain::query()->with(['profile', 'websites'])->where('status', $archived ? 'archived' : 'active')->orderBy('name')->get();
        $ids = $brains->pluck('id')->all();
        $by = fn ($q) => $q->whereIn('brain_id', $ids)->selectRaw('brain_id, count(*) c')->groupBy('brain_id')->pluck('c', 'brain_id');
        $sources = $by(KnowledgeSource::query());
        $items = $by(KnowledgeItem::query());
        $proposed = $by(KnowledgeItem::query()->where('status', 'proposed'));
        $gaps = $by(KnowledgeGap::query()->where('status', 'open'));
        $conflicts = collect((new KnowledgeConflicts())->all())->groupBy(fn ($p) => $p['a']->brain_id)->map->count();
        $expired = (new KnowledgeFreshness())->expired()->groupBy('brain_id')->map->count();

        $cards = $brains->map(function (Brain $b) use ($sources, $items, $proposed, $gaps, $conflicts, $expired) {
            [$f, $t] = ($b->profile ?? new \App\Models\BrainProfile())->completeness();

            return [
                'brain' => $b, 'template' => BrainTemplates::get($b->template_key)['name'] ?? ($b->template_key ? $b->template_key : 'Tự tạo'),
                'sources' => (int) ($sources[$b->id] ?? 0), 'items' => (int) ($items[$b->id] ?? 0), 'proposed' => (int) ($proposed[$b->id] ?? 0),
                'conflicts' => (int) ($conflicts[$b->id] ?? 0), 'expired' => (int) ($expired[$b->id] ?? 0), 'gaps' => (int) ($gaps[$b->id] ?? 0),
                'filled' => $f, 'total' => $t, 'sites' => $b->websites->pluck('name')->all(),
            ];
        });

        return [
            'cards' => $cards, 'manage' => static::canManage(), 'archived' => $archived,
            'archivedCount' => Brain::query()->where('status', 'archived')->count(),
            'templates' => BrainTemplates::all(), 'sites' => Website::query()->orderBy('name')->get(['id', 'name']),
            'reliances' => Brain::RELIANCE,
        ];
    }
}
