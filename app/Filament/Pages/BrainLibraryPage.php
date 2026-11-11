<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\KhoTriTue;
use App\Models\Brain;
use App\Models\BrainProfile;
use App\Models\BrainSource;
use App\Models\BrandProfile;
use App\Models\KnowledgeGap;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeSource;
use App\Models\User;
use App\Models\Website;
use App\Services\Brain\BrainService;
use App\Services\Brain\BrainTemplates;
use App\Services\Brand\BrandProfileData;
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
    /** Z2: thành tab "Trí tuệ" (đầu tiên) của cụm Kho trí tuệ; URL mới /admin/kho-tri-tue/tri-tue, URL cũ /admin/kho-tri-tue chuyển hướng ở routes/web.php. */
    protected static ?string $cluster = KhoTriTue::class;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationLabel = 'Trí tuệ';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Kho trí tuệ';

    protected static ?string $slug = 'tri-tue';

    protected static string $view = 'filament.pages.brain-library';

    public bool $showCreate = false;

    public bool $showArchived = false;

    /** AG-B2: lọc theo nhóm (suc_khoe | thuong_hieu | khac | rỗng = tất cả) và tìm theo tên. */
    public string $group = '';

    public string $search = '';

    public bool $showInstall = false;

    /** @var array<int,string> khóa mẫu được tick trong hộp "Cài các trí tuệ mẫu" */
    public array $installKeys = [];

    /** @var array{name:string,template:string,websites:array<int,string>} */
    public array $create = ['name' => '', 'template' => 'trong', 'websites' => []];

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
        $this->create = ['name' => '', 'template' => 'trong', 'websites' => []];
        $this->showCreate = true;
    }

    public static function canInstall(): bool
    {
        $u = Auth::user();

        return $u instanceof User && $u->is_active && $u->isAdmin();
    }

    public function openInstall(): void
    {
        abort_unless(static::canAccess() && static::canInstall(), 403);
        $this->installKeys = $this->notInstalledKeys();
        $this->showInstall = true;
    }

    /** @return array<int,string> các mẫu chưa cài (mẫu đã có không tick sẵn) */
    private function notInstalledKeys(): array
    {
        $have = Brain::query()->whereIn('template_key', BrainTemplates::HEALTH_KEYS)->pluck('template_key')->all();

        return array_values(array_diff(BrainTemplates::HEALTH_KEYS, $have));
    }

    public function selectNotInstalled(): void
    {
        abort_unless(static::canAccess() && static::canInstall(), 403);
        $this->installKeys = $this->notInstalledKeys();
    }

    public function closeInstall(): void
    {
        $this->showInstall = false;
    }

    public function installSelected(): void
    {
        abort_unless(static::canAccess() && static::canInstall(), 403);
        $keys = array_values(array_intersect((array) $this->installKeys, array_keys(BrainTemplates::all())));
        if ($keys === []) {
            Notification::make()->title('Hãy tick ít nhất một mẫu')->warning()->send();

            return;
        }
        try {
            $r = (new BrainService)->installTemplates($keys, null, Auth::user());
        } catch (KnowledgeError $e) {
            Notification::make()->title('Chưa cài được')->body($e->getMessage())->danger()->send();

            return;
        }
        $this->showInstall = false;
        Notification::make()->title('Đã cài '.count($r['created']).' trí tuệ mẫu')->body(count($r['skipped']) ? count($r['skipped']).' mẫu đã có nên bỏ qua (không ghi đè). Chưa gắn website nào: hãy gắn ở tab Cài đặt của từng Trí tuệ.' : 'Chưa gắn website nào: hãy gắn ở tab Cài đặt của từng Trí tuệ.')->success()->send();
    }

    public function closeCreate(): void
    {
        $this->showCreate = false;
    }

    public function createBrain(): mixed
    {
        abort_unless(static::canAccess() && static::canManage(), 403);
        try {
            $brain = (new BrainService)->createFromTemplate((string) $this->create['name'], (string) $this->create['template'] ?: null, array_map('intval', (array) $this->create['websites']), Auth::user());
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
        $brains = Brain::query()->with(['profile', 'websites'])->where('status', $archived ? 'archived' : 'active')
            ->when(trim($this->search) !== '', fn ($q) => $q->where('name', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($this->search)).'%'))
            ->orderBy('name')->get()
            ->filter(fn (Brain $b) => $this->group === '' || BrainTemplates::groupOf($b->template_key) === $this->group)->values();
        $ids = $brains->pluck('id')->all();
        $by = fn ($q) => $q->whereIn('brain_id', $ids)->selectRaw('brain_id, count(*) c')->groupBy('brain_id')->pluck('c', 'brain_id');
        $sources = $by(KnowledgeSource::query());
        $confirmedSrc = BrainSource::query()->whereIn('brain_id', $ids)->where('status', 'confirmed')->whereNotNull('tier')->where('tier', '!=', 'blocked')
            ->selectRaw('brain_id, count(*) c')->groupBy('brain_id')->pluck('c', 'brain_id');
        $items = $by(KnowledgeItem::query());
        $proposed = $by(KnowledgeItem::query()->where('status', 'proposed'));
        $gaps = $by(KnowledgeGap::query()->where('status', 'open'));
        $conflicts = collect((new KnowledgeConflicts)->all())->groupBy(fn ($p) => $p['a']->brain_id)->map->count();
        $expired = (new KnowledgeFreshness)->expired()->groupBy('brain_id')->map->count();

        $cards = $brains->map(function (Brain $b) use ($sources, $confirmedSrc, $items, $proposed, $gaps, $conflicts, $expired) {
            [$f, $t] = ($b->profile ?? new BrainProfile)->completeness();
            $brand = null;
            if ($b->template_key === 'thuong_hieu') { // AG-B2: số trường THẬT của hồ sơ doanh nghiệp (website đầu tiên đã gắn)
                $sid = $b->websites->first()?->id;
                $c = BrandProfileData::completeness($sid ? BrandProfile::query()->where('website_id', $sid)->first() : null);
                $brand = ['filled' => $c['filled'], 'total' => $c['total'], 'has_site' => (bool) $sid];
            }

            return [
                'brain' => $b, 'template' => BrainTemplates::get($b->template_key)['name'] ?? ($b->template_key ? $b->template_key : 'Tự tạo'),
                'sources' => (int) ($sources[$b->id] ?? 0), 'confirmed_sources' => (int) ($confirmedSrc[$b->id] ?? 0), 'needs_source' => $b->reliance() === 'must_cite' && (int) ($confirmedSrc[$b->id] ?? 0) === 0, 'items' => (int) ($items[$b->id] ?? 0), 'proposed' => (int) ($proposed[$b->id] ?? 0),
                'conflicts' => (int) ($conflicts[$b->id] ?? 0), 'expired' => (int) ($expired[$b->id] ?? 0), 'gaps' => (int) ($gaps[$b->id] ?? 0),
                'filled' => $f, 'total' => $t, 'sites' => $b->websites->pluck('name')->all(), 'brand' => $brand,
                'group' => BrainTemplates::groupOf($b->template_key), 'sample' => (string) ($b->settings['sample_label'] ?? '') !== '' && (int) ($sources[$b->id] ?? 0) === 0 && (int) ($items[$b->id] ?? 0) === 0,
            ];
        });

        return [
            'cards' => $cards, 'manage' => static::canManage(), 'archived' => $archived,
            'archivedCount' => Brain::query()->where('status', 'archived')->count(),
            'templates' => BrainTemplates::all(), 'sites' => Website::query()->orderBy('name')->get(['id', 'name']),
            'reliances' => Brain::RELIANCE, 'groups' => BrainTemplates::GROUPS, 'canInstall' => static::canInstall(),
            'healthTemplates' => collect(BrainTemplates::HEALTH_KEYS)->mapWithKeys(fn ($k) => [$k => (string) (BrainTemplates::get($k)['name'] ?? $k)])->all(),
            'installed' => Brain::query()->whereIn('template_key', BrainTemplates::HEALTH_KEYS)->pluck('name', 'template_key')->all(),
        ];
    }
}
