<?php

namespace App\Filament\Clusters\AgentTeam\Pages;

use App\Filament\Clusters\AgentTeam;
use App\Models\AgentSetting;
use App\Models\User;
use App\Services\Agent\Settings\AgentAi;
use App\Services\Ai\GeminiAiProvider;
use Filament\Actions\Action;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Pages\SubNavigationPosition;
use Illuminate\Support\Facades\Auth;

/** Tab "Kết nối AI": nơi DUY NHẤT gắn AI vào đội. Chỉ admin được nhập khóa; khóa lưu mã hóa, không hiển thị lại. */
class AgentConnection extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $cluster = AgentTeam::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationLabel = 'Kết nối AI';

    protected static ?string $title = 'Kết nối AI';

    protected static ?string $slug = 'ket-noi-ai';

    protected static ?int $navigationSort = 4;

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    protected static string $view = 'filament.clusters.agent-team.connection';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return $u instanceof User && $u->is_active && $u->isApprover();
    }

    private function isAdmin(): bool
    {
        return (bool) Auth::user()?->isAdmin();
    }

    public function mount(): void
    {
        $s = AgentSetting::current();
        $this->form->fill(['model' => $s->model ?: AgentAi::DEFAULT_MODEL, 'run_budget_vnd' => $s->run_budget_vnd, 'max_repair_rounds' => $s->max_repair_rounds, 'api_key' => null]);
    }

    public function form(Form $form): Form
    {
        $ro = ! $this->isAdmin();

        return $form->statePath('data')->schema([
            Section::make('Nhà cung cấp AI')->description('Hiện hỗ trợ Google Gemini. Chọn model theo từng vai trò (dàn ý, viết, biên tập) là việc của Dev1 (AG-B06).')->schema([
                TextInput::make('api_key')->label('Khóa API Gemini')->password()->revealable()->disabled($ro)->autocomplete(false)
                    ->placeholder(AgentAi::status()['connected'] ? 'Đã có khóa ('.AgentAi::status()['key_hint'].'). Để trống nếu không đổi.' : 'Dán khóa API')
                    ->helperText('Lưu mã hóa trong cơ sở dữ liệu, không hiển thị lại. Chỉ quản trị viên được nhập.'),
                TextInput::make('model')->label('Model')->disabled($ro)->required()->maxLength(80)->helperText('Ví dụ: '.AgentAi::DEFAULT_MODEL),
            ])->columns(2),
            Section::make('Giới hạn an toàn cho mỗi phiên chạy')->schema([
                TextInput::make('run_budget_vnd')->label('Ngân sách tối đa mỗi phiên (₫)')->numeric()->minValue(1000)->disabled($ro)->required()
                    ->helperText('Vượt thì đội AI dừng sửa và giữ nháp.'),
                TextInput::make('max_repair_rounds')->label('Số vòng tự sửa tối đa')->numeric()->minValue(0)->maxValue(5)->disabled($ro)->required(),
            ])->columns(2),
        ]);
    }

    public function save(): void
    {
        abort_unless($this->isAdmin(), 403);
        $d = $this->form->getState();
        $s = AgentSetting::query()->first() ?? new AgentSetting();
        $s->fill(['provider' => 'gemini', 'model' => $d['model'], 'run_budget_vnd' => $d['run_budget_vnd'], 'max_repair_rounds' => $d['max_repair_rounds'], 'updated_by' => Auth::id()]);
        if (! empty($d['api_key'])) {
            $s->api_key = $d['api_key'];
            $s->last_test_ok = null;
            $s->last_test_at = null;
            $s->last_test_message = null;
        }
        $s->save();
        $this->data['api_key'] = null;
        Notification::make()->title('Đã lưu cấu hình AI')->body(! empty($d['api_key']) ? 'Khóa mới đã lưu mã hóa. Bấm "Kiểm tra kết nối" để chắc chắn dùng được.' : null)->success()->send();
    }

    public function testConnection(): void
    {
        abort_unless($this->isAdmin(), 403);
        $ai = AgentAi::real();
        if (! $ai instanceof GeminiAiProvider) {
            Notification::make()->title('Chưa có khóa AI để kiểm tra')->warning()->send();

            return;
        }
        $r = $ai->ping();
        $s = AgentSetting::query()->first() ?? new AgentSetting(['provider' => 'gemini']);
        $s->fill(['last_test_at' => now(), 'last_test_ok' => $r['ok'], 'last_test_message' => $r['message'], 'updated_by' => Auth::id()])->save();
        $n = Notification::make()->title($r['ok'] ? 'Kết nối thành công' : 'Kết nối không được')->body($r['message']);
        $r['ok'] ? $n->success() : $n->danger();
        $n->send();
    }

    protected function getHeaderActions(): array
    {
        return $this->isAdmin() ? [
            Action::make('test')->label('Kiểm tra kết nối')->icon('heroicon-o-bolt')->color('gray')->action('testConnection'),
            Action::make('save')->label('Lưu')->action('save'),
        ] : [];
    }

    protected function getViewData(): array
    {
        return ['ai' => AgentAi::status(), 'isAdmin' => $this->isAdmin()];
    }
}
