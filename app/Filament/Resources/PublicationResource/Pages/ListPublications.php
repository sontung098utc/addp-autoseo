<?php

namespace App\Filament\Resources\PublicationResource\Pages;

use App\Filament\Resources\PublicationResource;
use App\Filament\Support\Schedule\VnTime;
use App\Models\Publication;
use App\Services\Publishing\EmergencyStopService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListPublications extends ListRecords
{
    protected static string $resource = PublicationResource::class;

    protected static ?string $title = 'Lịch xuất bản';

    public function getSubheading(): ?string
    {
        $emergencyService = app(EmergencyStopService::class);
        if ($emergencyService->isStopped()) {
            $info = $emergencyService->getStopInfo();
            $reason = $info['reason'] ?? 'Tạm dừng toàn hệ thống';
            return "⛔ CẢNH BÁO: Lịch xuất bản tự động đang DỪNG KHẨN CẤP ({$reason}). Toàn bộ lượt đăng đang tạm hoãn.";
        }

        return null;
    }

    protected function getHeaderActions(): array
    {
        $emergencyService = app(EmergencyStopService::class);
        $isStopped = $emergencyService->isStopped();

        if ($isStopped) {
            return [
                Action::make('resume')
                    ->label('Khôi phục lịch đăng')
                    ->icon('heroicon-o-play-circle')
                    ->color('warning')
                    ->visible(fn () => auth()->user()?->isApprover() ?? false)
                    ->requiresConfirmation()
                    ->modalHeading('Khôi phục hoạt động xuất bản tự động')
                    ->modalDescription('Lịch xuất bản tự động sẽ tiếp tục quét và đăng các bài viết đã lên lịch theo đúng thời gian. Bạn có chắc chắn muốn khôi phục?')
                    ->modalSubmitActionLabel('Khôi phục hoạt động')
                    ->action(function () use ($emergencyService) {
                        $user = auth()->user();
                        $emergencyService->resume($user, 'Khôi phục qua giao diện quản trị');
                        Notification::make()
                            ->title('Đã khôi phục lịch xuất bản tự động')
                            ->success()
                            ->send();
                    }),
            ];
        }

        return [
            Action::make('pause')
                ->label('Tạm dừng lịch đăng')
                ->icon('heroicon-o-pause-circle')
                ->color('danger')
                ->visible(fn () => auth()->user()?->isApprover() ?? false)
                ->form([
                    Textarea::make('reason')
                        ->label('Lý do dừng khẩn cấp')
                        ->placeholder('Nhập lý do cụ thể (bắt buộc: ví dụ sự cố CMS, sai thông tin chiến dịch...)')
                        ->required()
                        ->rows(3),
                ])
                ->modalHeading('Kích hoạt Dừng khẩn cấp Lịch xuất bản')
                ->modalDescription('Toàn bộ lượt xuất bản tự động của hệ thống sẽ lập tức tạm dừng. Các bài đến hạn sẽ không được gửi sang CMS cho đến khi được khôi phục.')
                ->modalSubmitActionLabel('Kích hoạt dừng khẩn cấp')
                ->action(function (array $data) use ($emergencyService) {
                    $user = auth()->user();
                    $emergencyService->stop($user, $data['reason']);
                    Notification::make()
                        ->title('Đã kích hoạt Dừng khẩn cấp lịch đăng')
                        ->body("Lý do: {$data['reason']}")
                        ->danger()
                        ->send();
                }),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'all';
    }

    public function getTabs(): array
    {
        [$start, $end] = VnTime::todayRangeUtc();
        $nowUtc = VnTime::dbString(now());
        $count = fn (callable $scope): int => $scope(Publication::query())->count();

        $today = fn (Builder $query) => $query->where('scheduled_at_utc', '>=', $start)->where('scheduled_at_utc', '<', $end);
        $upcoming = fn (Builder $query) => $query->where('status', 'scheduled')->where('scheduled_at_utc', '>=', $nowUtc);
        $attention = fn (Builder $query) => $query->whereIn('status', ['failed', 'needs_reconcile']);
        $done = fn (Builder $query) => $query->where('status', 'published');

        return [
            'today' => Tab::make('Hôm nay')->badge($count($today))->modifyQueryUsing($today),
            'upcoming' => Tab::make('Sắp tới')->badge($count($upcoming))->modifyQueryUsing($upcoming),
            'attention' => Tab::make('Cần xử lý')->badge($count($attention))->badgeColor('danger')->modifyQueryUsing($attention),
            'published' => Tab::make('Đã đăng')->badge($count($done))->badgeColor('success')->modifyQueryUsing($done),
            'all' => Tab::make('Tất cả')->badge(Publication::query()->count()),
        ];
    }
}
