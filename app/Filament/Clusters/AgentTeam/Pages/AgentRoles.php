<?php

namespace App\Filament\Clusters\AgentTeam\Pages;

use App\Filament\Clusters\AgentTeam;
use App\Filament\Pages\AgentConnection;
use App\Models\AiProvider;
use App\Models\User;
use App\Services\Agent\Roles\RoleAssignments;
use App\Services\Agent\Roles\RoleRegistry;
use App\Services\Agent\Roles\RoleSwitches;
use App\Services\Ai\Playbook\PlaybookLoader;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Illuminate\Support\HtmlString;
use Filament\Notifications\Notification;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Pages\SubNavigationPosition;
use Illuminate\Support\Facades\Auth;

/**
 * Tab "Vai trò" của Đội AI (AG-S09): xem sổ vai trò, mức sẵn sàng THẬT, rubric, rào chắn, tự động hóa, xuất xứ và kỹ năng.
 * AG-S17: mỗi thẻ có khu "Gán" (chỉ admin thao tác; người duyệt chỉ xem): model chính + dự phòng, bật/tắt và ghim phiên bản kỹ năng, bật/tắt vai trò.
 * Xem là chính (nút "Sửa rubric" của bản mẫu chưa làm). AG-S14: công tắc bật/tắt vai trò KHÔNG bắt buộc (hiện chỉ Chiến lược), chỉ admin đổi, ghi audit. Chi tiết dài nằm trong cửa sổ trượt, không nhồi trang chính.
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

    public function toggleRole(string $key): void
    {
        $user = Auth::user();
        $switches = new RoleSwitches();
        try {
            abort_unless($user instanceof User, 403);
            $switches->set($key, ! $switches->isEnabled($key), $user);
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }
        Notification::make()->title($switches->isEnabled($key) ? 'Đã bật vai trò' : 'Đã tắt vai trò')->success()->send();
    }

    private function isAdmin(): bool
    {
        $u = Auth::user();

        return $u instanceof User && $u->is_active && $u->isAdmin();
    }

    /** AG-S17: cửa sổ trượt "Gán" cho một vai trò. Ẩn với người không phải admin (không mở được); dịch vụ vẫn kiểm quyền lần nữa khi lưu. */
    public function assignAction(): Action
    {
        return Action::make('assign')
            ->visible(fn () => $this->isAdmin())
            ->modalHeading(fn (array $arguments) => 'Gán: '.((new RoleRegistry())->find((string) ($arguments['role'] ?? ''))['name'] ?? 'Vai trò'))
            ->slideOver()->modalWidth('2xl')
            ->modalSubmitActionLabel('Lưu')
            ->fillForm(function (array $arguments): array {
                $role = (string) ($arguments['role'] ?? '');
                $a = (new RoleAssignments())->find($role);
                $data = [
                    'provider_id' => $a?->provider_id, 'model' => $a?->model, 'fallback_provider_id' => $a?->fallback_provider_id, 'fallback_model' => $a?->fallback_model,
                    'model_enabled' => $a?->enabled ?? true, 'role_enabled' => (new RoleSwitches())->isEnabled($role),
                ];
                $reg = new RoleRegistry();
                $assign = new RoleAssignments($reg);
                foreach (array_values($reg->find($role)['skills'] ?? []) as $i => $ref) {
                    $st = $assign->skillState($ref);
                    $data["sk_{$i}_on"] = $st['enabled'];
                    $data["sk_{$i}_pin"] = $st['pin'] === null ? '' : (string) $st['pin'];
                }

                return $data;
            })
            ->form(fn (array $arguments) => $this->assignForm((string) ($arguments['role'] ?? '')))
            ->action(function (array $data, array $arguments, Action $action): void {
                $user = Auth::user();
                $role = (string) ($arguments['role'] ?? '');
                $reg = new RoleRegistry();
                $def = $reg->find($role);
                abort_unless($user instanceof User && $def !== null, 404);
                $assign = new RoleAssignments($reg);
                try {
                    if (isset(RoleAssignments::ROLE_TASKS[$role])) {
                        $assign->saveModel($role, $this->intOrNull($data['provider_id'] ?? null), $data['model'] ?? null, $this->intOrNull($data['fallback_provider_id'] ?? null), $data['fallback_model'] ?? null, (bool) ($data['model_enabled'] ?? true), $user);
                    }
                    $skills = [];
                    foreach (array_values($def['skills']) as $i => $ref) {
                        $skills[$ref] = ['enabled' => (bool) ($data["sk_{$i}_on"] ?? true), 'pin' => $data["sk_{$i}_pin"] ?? null];
                    }
                    if ($skills !== []) {
                        $assign->saveSkills($role, $skills, $user);
                    }
                    $sw = new RoleSwitches($reg);
                    if ($reg->isSwitchable($role) && array_key_exists('role_enabled', $data) && $sw->isEnabled($role) !== (bool) $data['role_enabled']) {
                        $sw->set($role, (bool) $data['role_enabled'], $user);
                    }
                } catch (\InvalidArgumentException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }
                Notification::make()->title('Đã lưu phần gán cho '.$def['name'])->success()->send();
            });
    }

    private function intOrNull(mixed $v): ?int
    {
        return $v === null || $v === '' ? null : (int) $v;
    }

    /** @return list<\Filament\Forms\Components\Component> */
    private function assignForm(string $role): array
    {
        $reg = new RoleRegistry();
        $def = $reg->find($role);
        abort_if($def === null, 404);
        $providers = AiProvider::query()->orderBy('name')->get();
        $options = $providers->mapWithKeys(fn (AiProvider $p) => [$p->id => $p->name.' ('.$p->kindLabel().')'.($p->enabled ? '' : ' · đang tắt').($p->hasKey() ? '' : ' · chưa có khóa')])->all();
        $suggest = fn (Get $get, string $field): array => array_values(array_unique(AiProvider::SUGGESTED_MODELS[$providers->firstWhere('id', (int) $get($field))?->kind ?? ''] ?? []));

        $schema = [];
        if (isset(RoleAssignments::ROLE_TASKS[$role])) {
            $tasks = implode(', ', RoleAssignments::ROLE_TASKS[$role]);
            $model = [];
            if ($options === []) {
                $model[] = Placeholder::make('no_provider')->label('')->content(new HtmlString('<span style="color:#d97706">(!) Chưa có provider — vào <a href="'.e(AgentConnection::getUrl()).'" style="text-decoration:underline">Cài đặt → Tích hợp AI</a> để thêm.</span>'));
            }
            $model[] = Select::make('provider_id')->label('Nhà cung cấp')->options($options)->placeholder('Không gán (dùng định tuyến theo loại việc)')->live();
            $model[] = TextInput::make('model')->label('Model')->maxLength(80)->datalist(fn (Get $get) => $suggest($get, 'provider_id'))->requiredWith('provider_id')->helperText(null);
            $model[] = Select::make('fallback_provider_id')->label('Nhà cung cấp dự phòng')->options($options)->placeholder('Không có dự phòng')->live();
            $model[] = TextInput::make('fallback_model')->label('Model dự phòng')->maxLength(80)->datalist(fn (Get $get) => $suggest($get, 'fallback_provider_id'))->requiredWith('fallback_provider_id');
            $model[] = Toggle::make('model_enabled')->label('Dùng bản gán này')->helperText("Tắt thì quay về định tuyến theo loại việc ({$tasks}).");
            $schema[] = Section::make('Model')->schema($model)->columns(2);
        } else {
            $schema[] = Section::make('Model')->schema([Placeholder::make('unwired')->label('')->content('(i) Chưa nối vào luồng: vai trò này chưa gọi AI trong phiên chạy nên chưa gán model.')]);
        }

        if ($def['skills'] !== []) {
            $loader = new PlaybookLoader();
            $versions = [];
            foreach ($def['skills'] as $ref) {
                [$r, $k] = explode('/', $ref, 2);
                try {
                    foreach ($loader->all() as $pb) {
                        if ($pb->role === $r && $pb->key === $k && $pb->status !== 'retired') {
                            $versions[$ref][(string) $pb->version] = 'v'.$pb->version.' · '.($pb->status === 'active' ? 'đang dùng' : 'bản nháp');
                        }
                    }
                } catch (\Throwable) {
                }
            }
            $fields = [];
            foreach (array_values($def['skills']) as $i => $ref) {
                $fields[] = Toggle::make("sk_{$i}_on")->label($ref);
                $fields[] = Select::make("sk_{$i}_pin")->label('Phiên bản')->options(['' => 'Mới nhất (không ghim)'] + ($versions[$ref] ?? []))->selectablePlaceholder(false);
            }
            $schema[] = Section::make('Kỹ năng')->description('Tắt kỹ năng thì bước đó dùng chỉ dẫn mặc định. Ghim chỉ chọn được playbook hợp lệ, chưa ngừng dùng.')->schema($fields)->columns(2);
        }

        if ($reg->isSwitchable($role)) {
            $schema[] = Section::make('Vai trò')->schema([Toggle::make('role_enabled')->label('Bật vai trò')->helperText('Tắt thì bước của vai trò này bị bỏ qua, cổng chấm luật vẫn chạy đủ.')]);
        }

        return $schema;
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
        $off = (new RoleSwitches($reg))->disabled();
        $user = Auth::user();

        $assign = new RoleAssignments($reg);
        $hasProviders = AiProvider::query()->exists();

        return [
            'cards' => array_map(function (array $r) use ($reg, $off, $assign) {
                $a = $assign->find($r['key']);

                return $r + [
                    'skill_counts' => $reg->skillCounts($r), 'switchable' => $reg->isSwitchable($r['key']), 'is_off' => in_array($r['key'], $off, true),
                    'model_wired' => isset(RoleAssignments::ROLE_TASKS[$r['key']]), 'assigned' => $assign->summary($r['key']),
                    'skill_changes' => count(array_filter($reg->skills($r), fn ($s) => ! $s['enabled'] || $s['pin'] !== null)),
                    'updated' => $a ? trim(($a->updater?->name ?? '').' · '.$a->updated_at?->format('d/m/Y H:i'), ' ·') : null,
                ];
            }, $reg->all()),
            'skillsUrl' => self::skillsTabUrl(), 'canSwitch' => $user instanceof User && $user->isAdmin(),
            'hasProviders' => $hasProviders, 'connectionUrl' => AgentConnection::getUrl(),
        ];
    }
}
