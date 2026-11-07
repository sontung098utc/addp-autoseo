<?php

namespace App\Filament\Clusters\AgentTeam\Pages;

use App\Filament\Clusters\AgentTeam;
use App\Models\AgentRunStep;
use App\Models\AgentSetting;
use App\Models\AiModelRoute;
use App\Models\AiProvider;
use App\Models\User;
use App\Services\Agent\Ai\AiModelRouter;
use App\Services\Agent\Ai\AiProviderFactory;
use App\Services\Agent\Settings\AgentAi;
use App\Services\Security\UrlSafetyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Pages\SubNavigationPosition;
use Illuminate\Support\Facades\Auth;

/**
 * Tab "Tích hợp AI" (slug cũ ket-noi-ai được giữ): nơi DUY NHẤT gắn AI vào đội — danh sách provider và định tuyến model theo loại việc.
 * Chỉ admin sửa; người duyệt chỉ xem. Khóa API lưu mã hóa, chỉ ghi, không bao giờ hiển thị lại. Tác vụ chưa có khóa thì không chạy (không Mock thay thế).
 */
class AgentConnection extends Page
{
    protected static ?string $cluster = AgentTeam::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationLabel = 'Tích hợp AI';

    protected static ?string $title = 'Tích hợp AI';

    protected static ?string $slug = 'ket-noi-ai';

    protected static ?int $navigationSort = 4;

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    protected static string $view = 'filament.clusters.agent-team.connection';

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return $u instanceof User && $u->is_active && $u->isApprover();
    }

    private function isAdmin(): bool
    {
        return (bool) Auth::user()?->isAdmin();
    }

    private function guardAdmin(): void
    {
        abort_unless($this->isAdmin(), 403);
    }

    // ---------------- Provider ----------------

    public function addProviderAction(): Action
    {
        return Action::make('addProvider')->label('Thêm provider')->icon('heroicon-o-plus')->hidden(fn () => ! $this->isAdmin())
            ->modalHeading('Thêm provider AI')->modalSubmitActionLabel('Thêm')->modalWidth('lg')
            ->form([
                TextInput::make('name')->label('Tên hiển thị')->required()->maxLength(80),
                Select::make('kind')->label('Kiểu')->options(AiProvider::KINDS)->required()->live()->default('anthropic'),
                TextInput::make('base_url')->label('Base URL')->url()->maxLength(255)->rules([fn () => $this->baseUrlRule()])
                    ->required(fn (Get $get) => $get('kind') === 'openai_compat')
                    ->visible(fn (Get $get) => in_array($get('kind'), ['openai_compat', 'anthropic'], true))
                    ->helperText('Bắt buộc với OpenAI-tương thích (ví dụ …/v1). Địa chỉ nội bộ bị chặn.'),
                TextInput::make('api_key')->label('Khóa API')->password()->revealable()->required()->autocomplete(false)->helperText('Lưu mã hóa, chỉ ghi, không hiển thị lại.'),
                TextInput::make('monthly_budget_vnd')->label('Ngân sách tháng (₫)')->numeric()->minValue(0),
                Toggle::make('suggest')->label('Gợi ý định tuyến cho các loại việc chưa cấu hình')->default(true)->helperText('Điền model gợi ý theo bậc; bạn vẫn sửa được ở bảng bên dưới. Kiểu OpenAI-tương thích cần tự nhập model.'),
            ])
            ->action(function (array $data) {
                $this->guardAdmin();
                $p = AiProvider::create([
                    'name' => $data['name'], 'kind' => $data['kind'], 'base_url' => $data['base_url'] ?? null, 'api_key' => $data['api_key'],
                    'monthly_budget_vnd' => $data['monthly_budget_vnd'] ?? null, 'enabled' => true, 'updated_by' => Auth::id(),
                ]);
                $n = ! empty($data['suggest']) ? $this->suggestRoutes($p) : 0;
                Notification::make()->title('Đã thêm provider')->body('Khóa đã lưu mã hóa.'.($n ? " Đã gợi ý định tuyến cho {$n} loại việc." : '').' Bấm "Kiểm tra kết nối" để chắc chắn dùng được.')->success()->send();
            });
    }

    public function editProviderAction(): Action
    {
        return Action::make('editProvider')->label('Sửa')->icon('heroicon-o-pencil-square')->color('gray')->size('sm')->hidden(fn () => ! $this->isAdmin())
            ->modalHeading('Sửa provider')->modalWidth('lg')
            ->fillForm(function (array $arguments) {
                $p = AiProvider::findOrFail($arguments['id']);

                return ['name' => $p->name, 'base_url' => $p->base_url, 'monthly_budget_vnd' => $p->monthly_budget_vnd, 'enabled' => $p->enabled];
            })
            ->form([
                TextInput::make('name')->label('Tên hiển thị')->required()->maxLength(80),
                TextInput::make('base_url')->label('Base URL')->url()->maxLength(255)->rules([fn () => $this->baseUrlRule()]),
                TextInput::make('monthly_budget_vnd')->label('Ngân sách tháng (₫)')->numeric()->minValue(0),
                Toggle::make('enabled')->label('Đang bật')->helperText('Tắt thì các loại việc dùng provider này không chạy.'),
            ])
            ->action(function (array $data, array $arguments) {
                $this->guardAdmin();
                $p = AiProvider::findOrFail($arguments['id']);
                if ($p->kind === 'openai_compat' && empty($data['base_url'])) {
                    Notification::make()->title('Thiếu Base URL')->body('Provider OpenAI-tương thích cần base URL.')->danger()->send();

                    return;
                }
                $p->update(['name' => $data['name'], 'base_url' => $data['base_url'] ?: null, 'monthly_budget_vnd' => $data['monthly_budget_vnd'] ?? null, 'enabled' => (bool) $data['enabled'], 'updated_by' => Auth::id()]);
                Notification::make()->title('Đã lưu provider')->success()->send();
            });
    }

    public function updateKeyAction(): Action
    {
        return Action::make('updateKey')->label('Cập nhật khóa')->color('gray')->size('sm')->hidden(fn () => ! $this->isAdmin())
            ->modalHeading('Cập nhật khóa API')->modalSubmitActionLabel('Lưu khóa')
            ->form([TextInput::make('api_key')->label('Khóa API mới')->password()->revealable()->required()->autocomplete(false)->helperText('Khóa cũ sẽ bị thay thế. Khóa chỉ ghi, không hiển thị lại.')])
            ->action(function (array $data, array $arguments) {
                $this->guardAdmin();
                AiProvider::findOrFail($arguments['id'])->update(['api_key' => $data['api_key'], 'last_test_ok' => null, 'last_test_at' => null, 'last_test_message' => null, 'updated_by' => Auth::id()]);
                Notification::make()->title('Đã lưu khóa mới')->body('Khóa lưu mã hóa. Bấm "Kiểm tra kết nối" để chắc chắn dùng được.')->success()->send();
            });
    }

    public function testProviderAction(): Action
    {
        return Action::make('testProvider')->label('Kiểm tra kết nối')->icon('heroicon-o-bolt')->color('gray')->size('sm')->hidden(fn () => ! $this->isAdmin())
            ->action(function (array $arguments) {
                $this->guardAdmin();
                $p = AiProvider::findOrFail($arguments['id']);
                $model = AiModelRoute::query()->where('primary_provider_id', $p->id)->value('primary_model')
                    ?? AiModelRoute::query()->where('fallback_provider_id', $p->id)->value('fallback_model');
                if (! $p->hasKey() || ! $model) {
                    Notification::make()->title('Chưa kiểm tra được')->body($p->hasKey() ? 'Chưa loại việc nào dùng provider này; hãy chọn model ở bảng định tuyến trước.' : 'Provider chưa có khóa API.')->warning()->send();

                    return;
                }
                $r = AiProviderFactory::make($p, $model)->ping();
                $p->update(['last_test_at' => now(), 'last_test_ok' => $r['ok'], 'last_test_message' => $r['message'], 'updated_by' => Auth::id()]);
                $n = Notification::make()->title($r['ok'] ? 'Kết nối thành công' : 'Kết nối không được')->body($r['message']);
                $r['ok'] ? $n->success() : $n->danger();
                $n->send();
            });
    }

    // ---------------- Định tuyến ----------------

    public function editRouteAction(): Action
    {
        return Action::make('editRoute')->label('Sửa')->icon('heroicon-o-pencil-square')->color('gray')->size('sm')->hidden(fn () => ! $this->isAdmin())
            ->modalHeading(fn (array $arguments) => 'Định tuyến: '.(AiModelRoute::TASKS[$arguments['task'] ?? ''][0] ?? ''))->modalWidth('lg')
            ->fillForm(function (array $arguments) {
                $r = AiModelRoute::query()->where('task', $arguments['task'])->first();

                return [
                    'tier' => $r?->tier ?? (AiModelRoute::TASKS[$arguments['task']][1] ?? 'standard'),
                    'primary_provider_id' => $r?->primary_provider_id, 'primary_model' => $r?->primary_model,
                    'fallback_provider_id' => $r?->fallback_provider_id, 'fallback_model' => $r?->fallback_model,
                    'cost_cap_vnd_per_call' => $r?->cost_cap_vnd_per_call,
                ];
            })
            ->form([
                Select::make('tier')->label('Bậc')->options(AiModelRoute::TIERS)->required(),
                Select::make('primary_provider_id')->label('Provider chính')->options(fn () => AiProvider::query()->orderBy('name')->pluck('name', 'id'))->required(),
                TextInput::make('primary_model')->label('Model chính')->required()->maxLength(80)->helperText('Ví dụ: '.implode(', ', array_filter(AiProvider::SUGGESTED_MODELS['anthropic']))),
                Select::make('fallback_provider_id')->label('Provider dự phòng')->options(fn () => AiProvider::query()->orderBy('name')->pluck('name', 'id'))->placeholder('Không có dự phòng')->live(),
                TextInput::make('fallback_model')->label('Model dự phòng')->maxLength(80)->required(fn (Get $get) => filled($get('fallback_provider_id')))
                    ->helperText('Chỉ chuyển sang đây khi model chính lỗi tạm thời liên tục; mỗi lần chuyển được ghi vào nhật ký run.'),
                TextInput::make('cost_cap_vnd_per_call')->label('Trần chi phí mỗi lần gọi (₫)')->numeric()->minValue(1)->helperText('Để trống = không giới hạn. Vượt trần thì phiên dừng và ghi quyết định.'),
            ])
            ->action(function (array $data, array $arguments) {
                $this->guardAdmin();
                $task = $arguments['task'];
                abort_unless(isset(AiModelRoute::TASKS[$task]), 404);
                if (blank($data['fallback_provider_id'] ?? null)) {
                    $data['fallback_provider_id'] = null;
                    $data['fallback_model'] = null;
                } elseif ((int) $data['fallback_provider_id'] === (int) $data['primary_provider_id'] && $data['fallback_model'] === $data['primary_model']) {
                    Notification::make()->title('Dự phòng trùng model chính')->body('Hãy chọn model hoặc provider khác để dự phòng có tác dụng.')->danger()->send();

                    return;
                }
                AiModelRoute::updateOrCreate(['task' => $task], [
                    'tier' => $data['tier'], 'primary_provider_id' => $data['primary_provider_id'], 'primary_model' => trim($data['primary_model']),
                    'fallback_provider_id' => $data['fallback_provider_id'], 'fallback_model' => $data['fallback_model'] ? trim($data['fallback_model']) : null,
                    'cost_cap_vnd_per_call' => $data['cost_cap_vnd_per_call'] ?? null,
                ]);
                Notification::make()->title('Đã lưu định tuyến')->success()->send();
            });
    }

    public function editLimitsAction(): Action
    {
        return Action::make('editLimits')->label('Giới hạn mỗi phiên')->icon('heroicon-o-adjustments-horizontal')->color('gray')->hidden(fn () => ! $this->isAdmin())
            ->modalHeading('Giới hạn an toàn cho mỗi phiên chạy')
            ->fillForm(fn () => ['run_budget_vnd' => AgentSetting::current()->run_budget_vnd, 'max_repair_rounds' => AgentSetting::current()->max_repair_rounds])
            ->form([
                TextInput::make('run_budget_vnd')->label('Ngân sách tối đa mỗi phiên (₫)')->numeric()->minValue(1000)->required()->helperText('Vượt thì đội AI dừng sửa và giữ nháp.'),
                TextInput::make('max_repair_rounds')->label('Số vòng tự sửa tối đa')->numeric()->minValue(0)->maxValue(5)->required(),
            ])
            ->action(function (array $data) {
                $this->guardAdmin();
                $s = AgentSetting::query()->first() ?? new AgentSetting(['provider' => 'gemini']);
                $s->fill(['run_budget_vnd' => $data['run_budget_vnd'], 'max_repair_rounds' => $data['max_repair_rounds'], 'updated_by' => Auth::id()])->save();
                Notification::make()->title('Đã lưu giới hạn')->success()->send();
            });
    }

    /** Route gợi ý cho các loại việc CHƯA có route, theo bậc và model gợi ý của kiểu provider. @return int số route đã tạo */
    private function suggestRoutes(AiProvider $p): int
    {
        $n = 0;
        foreach (AiModelRoute::TASKS as $task => [, $tier]) {
            $model = AiProvider::SUGGESTED_MODELS[$p->kind][$tier] ?? null;
            if ($model && ! AiModelRoute::query()->where('task', $task)->exists()) {
                AiModelRoute::create(['task' => $task, 'tier' => $tier, 'primary_provider_id' => $p->id, 'primary_model' => $model]);
                $n++;
            }
        }

        return $n;
    }

    private function baseUrlRule(): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) {
            if (blank($value)) {
                return;
            }
            try {
                app(UrlSafetyService::class)->validateUrl((string) $value);
            } catch (\InvalidArgumentException $e) {
                $fail('Base URL không an toàn hoặc không hợp lệ.');
            }
        };
    }

    protected function getHeaderActions(): array
    {
        return $this->isAdmin() ? [$this->editLimitsAction(), $this->addProviderAction()] : [];
    }

    protected function getViewData(): array
    {
        $monthStart = now()->startOfMonth();
        $providers = AiProvider::query()->orderBy('id')->get()->map(function (AiProvider $p) use ($monthStart) {
            $p->used_month = (float) AgentRunStep::query()->where('created_at', '>=', $monthStart)->where('data->provider_id', $p->id)->sum('cost_vnd');

            return $p;
        });
        $routes = AiModelRoute::query()->with(['primaryProvider', 'fallbackProvider'])->get()->keyBy('task');
        $legacy = AiModelRouter::isConfigured() ? null : AgentAi::status();

        return [
            'ai' => AgentAi::status(), 'isAdmin' => $this->isAdmin(), 'providers' => $providers, 'routes' => $routes, 'tasks' => AiModelRoute::TASKS,
            'tiers' => AiModelRoute::TIERS, 'legacy' => $legacy && $legacy['connected'] ? $legacy : null,
            'limits' => ['budget' => AgentAi::policy()->budgetVnd, 'rounds' => AgentAi::policy()->maxRepairRounds],
        ];
    }
}
