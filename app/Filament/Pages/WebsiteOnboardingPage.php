<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Models\Website;
use App\Services\Onboarding\WebsiteOnboarding;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/** Khởi tạo website một nút: kho + hồ sơ + Trí tuệ + từ khóa + gợi ý kế hoạch. Chạy đồng bộ trong trang. Approver trở lên. */
class WebsiteOnboardingPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-rocket-launch';

    protected static ?string $navigationLabel = 'Khởi tạo website';

    protected static ?string $navigationGroup = 'Cài đặt';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Khởi tạo website';

    protected static ?string $slug = 'khoi-tao-website';

    protected static string $view = 'filament.pages.website-onboarding';

    public ?int $websiteId = null;

    /** @var array<string,mixed>|null */
    public ?array $result = null;

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return $u instanceof User && $u->is_active && $u->isApprover();
    }

    public function mount(): void
    {
        $this->websiteId = Website::query()->where('status', 'active')->orderBy('name')->value('id');
    }

    public function start(): void
    {
        abort_unless(static::canAccess(), 403);
        $site = Website::query()->where('status', 'active')->find($this->websiteId);
        if (! $site) {
            Notification::make()->title('Chọn một website đang hoạt động')->warning()->send();

            return;
        }
        $this->result = (new WebsiteOnboarding)->run($site, Auth::user());
        $ok = count(array_filter($this->result['steps'], fn ($s) => $s['status'] === 'ok'));
        Notification::make()->title("Khởi tạo xong: {$ok}/".count($this->result['steps']).' bước hoàn tất')->success()->send();
    }

    protected function getViewData(): array
    {
        return [
            'websites' => Website::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'links' => [
                'kb' => BrainBuildPage::getUrl(),
                'keywords' => KeywordsPage::getUrl(),
                'plan' => ContentPlanPage::getUrl(),
            ],
        ];
    }
}
