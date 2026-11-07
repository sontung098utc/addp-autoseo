<?php

namespace App\Filament\Clusters\AgentTeam\Pages;

use App\Filament\Clusters\AgentTeam;
use App\Models\User;
use App\Services\Agent\Roles\RoleRegistry;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Pages\SubNavigationPosition;
use Illuminate\Support\Facades\Auth;

/**
 * Tab "Vai trò" của Đội AI (AG-S09): xem sổ vai trò, mức sẵn sàng THẬT, rubric, rào chắn, tự động hóa, xuất xứ và kỹ năng.
 * v1 CHỈ XEM (nút "Sửa rubric" của bản mẫu chưa làm; sau này chỉ admin). Chi tiết dài nằm trong cửa sổ trượt, không nhồi trang chính.
 */
class AgentRoles extends Page
{
    protected static ?string $cluster = AgentTeam::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Vai trò';

    protected static ?string $title = 'Đội & Vai trò';

    protected static ?string $slug = 'vai-tro';

    protected static ?int $navigationSort = 5;

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    protected static string $view = 'filament.clusters.agent-team.roles';

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return $u instanceof User && $u->is_active && $u->isApprover();
    }

    public function detailAction(): Action
    {
        return Action::make('detail')
            ->modalHeading(fn (array $arguments) => (new RoleRegistry())->find((string) ($arguments['role'] ?? ''))['name'] ?? 'Vai trò')
            ->slideOver()->modalWidth('3xl')
            ->modalContent(function (array $arguments) {
                $reg = new RoleRegistry();
                $role = $reg->find((string) ($arguments['role'] ?? ''));
                abort_if($role === null, 404);

                return view('filament.clusters.agent-team.role-detail', [
                    'role' => $role, 'skills' => $reg->skills($role), 'rubric' => $reg->rubric($role), 'skillsUrl' => self::skillsTabUrl(),
                ]);
            })
            ->modalSubmitAction(false)->modalCancelActionLabel('Đóng');
    }

    /** URL tab "Kỹ năng" (slug ky-nang) nếu trang đó đã tồn tại trong panel; chưa có thì null (không dẫn tới trang 404). */
    public static function skillsTabUrl(): ?string
    {
        try {
            foreach (Filament::getCurrentPanel()?->getPages() ?? [] as $page) {
                if (method_exists($page, 'getSlug') && $page::getSlug() === 'ky-nang' && $page::canAccess()) {
                    return $page::getUrl();
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    protected function getViewData(): array
    {
        $reg = new RoleRegistry();

        return ['cards' => array_map(fn (array $r) => $r + ['skill_counts' => $reg->skillCounts($r)], $reg->all()), 'skillsUrl' => self::skillsTabUrl()];
    }
}
