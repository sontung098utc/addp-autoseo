<?php

namespace App\Filament\Resources\PublicationResource\Pages;

use App\Filament\Resources\PublicationResource;
use App\Filament\Support\Schedule\VnTime;
use App\Models\Publication;
use Filament\Actions\Action;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ListPublications extends ListRecords
{
    protected static string $resource = PublicationResource::class;

    protected static ?string $title = 'Lịch đăng bài';

    public function getSubheading(): string|Htmlable|null
    {
        $n = PublicationResource::needsAttentionCount();
        $head = $n > 0
            ? "Có {$n} lượt đăng cần xử lý (lỗi hoặc chờ đối soát). Xem tab \"Cần xử lý\"."
            : 'Không có lượt đăng nào cần xử lý.';

        return $head.' Giờ hiển thị theo giờ Việt Nam; hệ thống lưu UTC. Trạng thái do hệ thống quản lý nên không sửa/xóa tay.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pause')
                ->label('Tạm dừng lịch đăng')
                ->icon('heroicon-o-pause-circle')
                ->color('gray')
                ->disabled()
                ->tooltip('Chưa hỗ trợ - chờ task 3.6 (dừng khẩn cấp).'),
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
