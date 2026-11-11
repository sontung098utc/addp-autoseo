<?php

namespace App\Filament\Pages\Concerns;

use App\Models\AgentRoleAssignment;
use App\Models\AgentRunStep;
use App\Models\AgentSetting;
use App\Models\AiModelRoute;
use App\Models\AiProvider;
use App\Services\Agent\Ai\AiModelCatalog;
use App\Services\Agent\Ai\AiModelRouter;
use App\Services\Agent\Ai\AiProviderFactory;
use App\Services\Agent\Roles\RoleAssignments;
use App\Services\Agent\Settings\AgentAi;
use App\Services\Security\UrlSafetyService;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

/**
 * Tab "Cấu hình AI" của trang Chi phí AI: THUẦN cấu hình kết nối. Danh sách tài khoản (nhiều tài khoản cùng nhà cung cấp), khóa API mã hóa chỉ ghi
 * (chỉ hiện 4 ký tự cuối nếu khóa đủ dài), model đã bật theo tài khoản. Gán model cho nhân sự nằm ở Đội AI → Vai trò, không ở đây.
 * Chỉ admin sửa; người duyệt chỉ xem.
 */
trait ManagesAiAccounts
{
    public bool $showPicker = false;

    private function isAdmin(): bool
    {
        return (bool) Auth::user()?->isAdmin();
    }

    private function guardAdmin(): void
    {
        abort_unless($this->isAdmin(), 403);
    }

    public function togglePicker(): void
    {
        $this->showPicker = ! $this->showPicker;
    }

    /** Model nhập tay: bỏ trùng, chỉ giữ tên hợp lệ. @return list<string> */
    private function cleanCustom(array $names): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($n) => trim((string) $n), $names), fn ($n) => $n !== '' && AiModelCatalog::validCustomName($n))));
    }

    private function modelFields(string $brandExpr = 'preset'): array
    {
        return [
            CheckboxList::make('models')->label('Model dùng được với tài khoản này')->options(fn (Get $get) => AiModelCatalog::options((string) ($get($brandExpr) ?: 'custom')))
                ->columns(2)->bulkToggleable()->visible(fn (Get $get) => AiModelCatalog::options((string) ($get($brandExpr) ?: 'custom')) !== [])
                ->helperText('Chỉ model được bật mới hiện ở danh sách chọn model cho nhân sự.'),
            TagsInput::make('custom_models')->label('Thêm model theo tên')->placeholder('Gõ tên model rồi Enter')
                ->helperText('Chữ, số và . _ : / - (tối đa 80 ký tự). Dùng khi hãng ra model mới chưa có trong danh sách.')
                ->nestedRecursiveRules(['regex:'.config('ai_models.custom_model_pattern')]),
        ];
    }

    // ---------------- Tài khoản ----------------

    public function addProviderAction(): Action
    {
        return Action::make('addProvider')->label('Thêm tài khoản')->icon('heroicon-o-plus')->hidden(fn () => ! $this->isAdmin())
            ->modalHeading('Thêm tài khoản AI')->modalSubmitActionLabel('Thêm')->modalWidth('2xl')
            ->mountUsing(function ($form, array $arguments) {
                $preset = isset(AiModelCatalog::presets()[$arguments['preset'] ?? '']) ? $arguments['preset'] : 'gemini';
                $form->fill($this->newAccountDefaults($preset));
            })
            ->form(fn () => [
                Select::make('preset')->label('Nhà cung cấp')->required()->live()
                    ->options(collect(AiModelCatalog::presets())->map(fn ($p) => $p['label'])->all())
                    ->afterStateUpdated(function (Set $set, ?string $state) {
                        foreach ($this->newAccountDefaults((string) ($state ?: 'custom')) as $k => $v) {
                            if ($k !== 'preset') {
                                $set($k, $v);
                            }
                        }
                    }),
                TextInput::make('name')->label('Tên tài khoản')->required()->maxLength(80)->helperText('Tự đặt, ví dụ "Gemini – tài khoản công ty". Thêm được nhiều tài khoản cùng nhà cung cấp.'),
                TextInput::make('base_url')->label('Base URL')->url()->maxLength(255)->rules([fn () => $this->baseUrlRule()])
                    ->required(fn (Get $get) => ($get('preset') ?? '') === 'custom')
                    ->visible(fn (Get $get) => in_array($get('preset'), ['custom', 'openai', 'openrouter', 'anthropic'], true))
                    ->helperText('Điền sẵn với OpenAI và OpenRouter. Máy chủ tùy chỉnh cần địa chỉ dạng …/v1. Địa chỉ nội bộ bị chặn.'),
                TextInput::make('api_key')->label('Khóa API')->password()->revealable()->required()->autocomplete(false)->helperText('Lưu mã hóa, chỉ ghi. Sau khi lưu chỉ hiện 4 ký tự cuối để nhận diện.'),
                TextInput::make('monthly_budget_vnd')->label('Ngân sách tháng (₫)')->numeric()->minValue(0),
                ...$this->modelFields(),
                Toggle::make('suggest')->label('Gợi ý model cho các loại việc chưa có cấu hình')->default(true)
                    ->helperText('Chỉ điền cho loại việc còn trống, theo bậc. Việc gán model cho nhân sự làm ở Đội AI → Vai trò.'),
            ])
            ->action(function (array $data) {
                $this->guardAdmin();
                [$kind, $baseUrl, $brand] = $this->resolvePreset($data);
                if ($kind === 'openai_compat' && blank($baseUrl)) {
                    Notification::make()->title('Thiếu Base URL')->body('Tài khoản OpenAI-tương thích cần Base URL.')->danger()->send();

                    return;
                }
                $p = AiProvider::create([
                    'name' => $data['name'], 'kind' => $kind, 'base_url' => $baseUrl, 'api_key' => $data['api_key'],
                    'monthly_budget_vnd' => $data['monthly_budget_vnd'] ?? null, 'enabled' => true, 'updated_by' => Auth::id(),
                    'models' => $this->mergeModels((array) ($data['models'] ?? []), (array) ($data['custom_models'] ?? [])),
                ]);
                $n = ! empty($data['suggest']) ? $this->suggestRoutes($p, $brand) : 0;
                Notification::make()->title('Đã thêm tài khoản')->body('Khóa đã lưu mã hóa.'.($n ? " Đã gợi ý model cho {$n} loại việc." : '').' Bấm "Kiểm tra kết nối" để chắc chắn dùng được.')->success()->send();
                $this->showPicker = false;
            });
    }

    /** @return array<string, mixed> */
    private function newAccountDefaults(string $preset): array
    {
        $def = AiModelCatalog::presets()[$preset] ?? AiModelCatalog::presets()['custom'];
        $same = AiProvider::query()->get()->filter(fn (AiProvider $p) => AiModelCatalog::brandOf($p) === $preset)->count();

        return ['preset' => $preset, 'name' => $same === 0 ? $def['label'] : $def['label'].' – tài khoản '.($same + 1), 'base_url' => $def['base_url'], 'models' => AiModelCatalog::defaults($preset), 'custom_models' => []];
    }

    /** @return array{0: string, 1: ?string, 2: string} [kind, base_url, brand] */
    private function resolvePreset(array $data): array
    {
        $presets = AiModelCatalog::presets();
        $preset = $data['preset'] ?? null;
        if ($preset === null || ! isset($presets[$preset])) {
            // tương thích kiểu cũ: chỉ có `kind`
            $kind = (string) ($data['kind'] ?? 'anthropic');
            abort_unless(isset(AiProvider::KINDS[$kind]), 422);

            return [$kind, ($data['base_url'] ?? null) ?: null, $kind === 'openai_compat' ? 'custom' : $kind];
        }

        return [$presets[$preset]['kind'], ($data['base_url'] ?? null) ?: ($presets[$preset]['base_url'] ?? null), $preset];
    }

    /** @return list<string> */
    private function mergeModels(array $picked, array $custom): array
    {
        return array_values(array_unique([...array_map('strval', $picked), ...$this->cleanCustom($custom)]));
    }

    public function editProviderAction(): Action
    {
        return Action::make('editProvider')->label('Sửa')->icon('heroicon-o-pencil-square')->color('gray')->size('sm')->hidden(fn () => ! $this->isAdmin())
            ->modalHeading('Sửa tài khoản')->modalWidth('2xl')
            ->fillForm(function (array $arguments) {
                $p = AiProvider::findOrFail($arguments['id']);
                $brand = AiModelCatalog::brandOf($p);
                $catalogIds = array_keys(AiModelCatalog::options($brand));
                $enabled = array_column(AiModelCatalog::enabledModels($p), 'id');

                return ['name' => $p->name, 'base_url' => $p->base_url, 'monthly_budget_vnd' => $p->monthly_budget_vnd, 'enabled' => $p->enabled, 'preset' => $brand,
                    'models' => array_values(array_intersect($enabled, $catalogIds)), 'custom_models' => array_values(array_diff($enabled, $catalogIds))];
            })
            ->form([
                TextInput::make('name')->label('Tên tài khoản')->required()->maxLength(80),
                TextInput::make('base_url')->label('Base URL')->url()->maxLength(255)->rules([fn () => $this->baseUrlRule()]),
                TextInput::make('monthly_budget_vnd')->label('Ngân sách tháng (₫)')->numeric()->minValue(0),
                Toggle::make('enabled')->label('Đang bật')->helperText('Tắt thì model của tài khoản này không hiện để chọn và các việc dùng nó không chạy.'),
                ...$this->modelFields(),
            ])
            ->action(function (array $data, array $arguments) {
                $this->guardAdmin();
                $p = AiProvider::findOrFail($arguments['id']);
                if ($p->kind === 'openai_compat' && empty($data['base_url'])) {
                    Notification::make()->title('Thiếu Base URL')->body('Tài khoản OpenAI-tương thích cần Base URL.')->danger()->send();

                    return;
                }
                $p->update(['name' => $data['name'], 'base_url' => $data['base_url'] ?: null, 'monthly_budget_vnd' => $data['monthly_budget_vnd'] ?? null, 'enabled' => (bool) $data['enabled'],
                    'models' => $this->mergeModels((array) ($data['models'] ?? []), (array) ($data['custom_models'] ?? [])), 'updated_by' => Auth::id()]);
                Notification::make()->title('Đã lưu tài khoản')->success()->send();
            });
    }

    public function updateKeyAction(): Action
    {
        return Action::make('updateKey')->label('Sửa khóa')->icon('heroicon-o-key')->color('gray')->size('sm')->hidden(fn () => ! $this->isAdmin())
            ->modalHeading('Cập nhật khóa API')->modalSubmitActionLabel('Lưu khóa')
            ->form([TextInput::make('api_key')->label('Khóa API mới')->password()->revealable()->required()->autocomplete(false)->helperText('Khóa cũ sẽ bị thay thế. Khóa chỉ ghi, không hiển thị lại.')])
            ->action(function (array $data, array $arguments) {
                $this->guardAdmin();
                AiProvider::findOrFail($arguments['id'])->update(['api_key' => $data['api_key'], 'last_test_ok' => null, 'last_test_at' => null, 'last_test_message' => null, 'updated_by' => Auth::id()]);
                Notification::make()->title('Đã lưu khóa mới')->body('Khóa lưu mã hóa. Bấm "Kiểm tra kết nối" để chắc chắn dùng được.')->success()->send();
            });
    }

    public function deleteProviderAction(): Action
    {
        return Action::make('deleteProvider')->label('Xóa')->icon('heroicon-o-trash')->color('danger')->outlined()->size('sm')->hidden(fn () => ! $this->isAdmin())
            ->requiresConfirmation()->modalHeading('Xóa tài khoản AI')
            ->modalDescription(function (array $arguments) {
                $id = (int) ($arguments['id'] ?? 0);
                $r = AiModelRoute::query()->where('primary_provider_id', $id)->orWhere('fallback_provider_id', $id)->count();
                $a = AgentRoleAssignment::query()->where('provider_id', $id)->orWhere('fallback_provider_id', $id)->count();

                return 'Khóa API bị xóa vĩnh viễn.'.($r + $a > 0 ? " Đang có {$r} loại việc và {$a} nhân sự dùng tài khoản này: các mục đó sẽ trở về trạng thái chưa cấu hình và không chạy tới khi chọn tài khoản khác." : '');
            })
            ->action(function (array $arguments) {
                $this->guardAdmin();
                AiProvider::findOrFail($arguments['id'])->delete();
                Notification::make()->title('Đã xóa tài khoản')->success()->send();
            });
    }

    public function testProviderAction(): Action
    {
        return Action::make('testProvider')->label('Kiểm tra kết nối')->icon('heroicon-o-bolt')->color('gray')->size('sm')->hidden(fn () => ! $this->isAdmin())
            ->action(function (array $arguments) {
                $this->guardAdmin();
                $p = AiProvider::findOrFail($arguments['id']);
                $models = AiModelCatalog::enabledModels($p);
                $pick = collect($models)->firstWhere('tier', 'cheap') ?? collect($models)->firstWhere('tier', 'standard') ?? ($models[0] ?? null);
                $model = $pick['id'] ?? AiModelRoute::query()->where('primary_provider_id', $p->id)->value('primary_model');
                if (! $p->hasKey() || ! $model) {
                    Notification::make()->title('Chưa kiểm tra được')->body($p->hasKey() ? 'Tài khoản chưa bật model nào; hãy bật hoặc thêm một model rồi thử lại.' : 'Tài khoản chưa có khóa API.')->warning()->send();

                    return;
                }
                $r = AiProviderFactory::make($p, $model)->ping();
                $p->update(['last_test_at' => now(), 'last_test_ok' => $r['ok'], 'last_test_message' => $r['message'], 'updated_by' => Auth::id()]);
                $n = Notification::make()->title($r['ok'] ? 'Kết nối thành công' : 'Kết nối không được')->body($r['message']);
                $r['ok'] ? $n->success() : $n->danger();
                $n->send();
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

    /** Route gợi ý cho các loại việc CHƯA có route, theo bậc và model gợi ý của loại tài khoản. @return int số route đã tạo */
    private function suggestRoutes(AiProvider $p, string $brand): int
    {
        $enabled = AiModelCatalog::enabledModels($p);
        $n = 0;
        foreach (AiModelRoute::TASKS as $task => [, $tier]) {
            $model = AiProvider::SUGGESTED_MODELS[$p->kind][$tier] ?? null;
            if ($p->kind === 'openai_compat') {
                $model = collect($enabled)->firstWhere('tier', $tier)['id'] ?? null;
            }
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

    /** @return array<string, mixed> */
    protected function configViewData(): array
    {
        $monthStart = now()->startOfMonth();
        $providers = AiProvider::query()->orderBy('id')->get()->map(function (AiProvider $p) use ($monthStart) {
            $p->used_month = (float) AgentRunStep::query()->where('created_at', '>=', $monthStart)->where('data->provider_id', $p->id)->sum('cost_vnd');
            $p->brand = AiModelCatalog::brandOf($p);
            $p->logo = AiModelCatalog::logoOf($p->brand);
            $p->enabled_models = AiModelCatalog::enabledModels($p);
            $roles = RoleAssignments::ROLE_TASKS;
            $p->staff_count = AgentRoleAssignment::query()->where('enabled', true)->where(fn ($q) => $q->where('provider_id', $p->id)->orWhere('fallback_provider_id', $p->id))->whereIn('role_key', array_keys($roles))->count();

            return $p;
        });
        $legacy = AiModelRouter::isConfigured() ? null : AgentAi::status();

        return [
            'isAdmin' => $this->isAdmin(), 'providers' => $providers, 'presets' => AiModelCatalog::presets(),
            'legacy' => $legacy && $legacy['connected'] ? $legacy : null,
            'limits' => ['budget' => AgentAi::policy()->budgetVnd, 'rounds' => AgentAi::policy()->maxRepairRounds],
            'tierLabels' => AiProvider::TIER_LABELS,
        ];
    }
}
