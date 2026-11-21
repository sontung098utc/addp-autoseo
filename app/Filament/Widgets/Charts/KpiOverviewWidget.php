<?php

namespace App\Filament\Widgets\Charts;

use App\Filament\Resources\ArticleResource;
use App\Filament\Resources\PublicationResource;
use App\Filament\Support\Dashboard\ChartQueries as Q;
use App\Models\Article;
use App\Models\ArticleVersion;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Hàng thẻ số đầu trang: mỗi thẻ một con số, mũi tên so với kỳ trước và đường xu hướng nhỏ. */
class KpiOverviewWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected static ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $now = now();
        $isApprover = (bool) auth()->user()?->isApprover();
        $week = Q::publishedWeekly($now);
        $delta = Q::deltaPercent($week['thisWeek'], $week['lastWeek']);

        $published = Stat::make('Đã đăng tuần này', $week['thisWeek'])
            ->chart($week['spark'])->color('success')->url(PublicationResource::listUrl());
        if ($delta === null) {
            $published->description($week['lastWeek'] === 0 ? 'Tuần trước chưa có bài để so sánh' : '');
        } else {
            $up = $delta >= 0;
            $published->description(($up ? '+' : '').$delta.'% so với cùng kỳ tuần trước')
                ->descriptionIcon($up ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->descriptionColor($up ? 'success' : 'danger');
        }

        $waiting = Article::query()->where('workflow_status', 'needs_review')->count();
        $overdue = ArticleVersion::query()->where('review_status', 'needs_review')
            ->whereIn('id', ArticleVersion::query()->selectRaw('MAX(id)')->groupBy('article_id'))
            ->where('created_at', '<', $now->copy()->subHours(24))->count();
        $review = Stat::make('Bài chờ duyệt', $waiting)
            ->color($overdue > 0 ? 'danger' : ($waiting > 0 ? 'warning' : 'success'))
            ->description($overdue > 0 ? "{$overdue} bài quá 24 giờ chưa duyệt" : ($waiting > 0 ? 'Chưa có bài nào quá hạn' : 'Không có bài nào đang chờ'))
            ->descriptionIcon($overdue > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
            ->url(ArticleResource::getUrl('index', ['activeTab' => 'needs_review']));

        $pub = Q::publicationsByDay(7, $now);
        $bad = array_sum($pub['failed']) + array_sum($pub['reconcile']);
        $problems = Stat::make('Lỗi đăng 7 ngày', $bad)
            ->chart(array_map(fn ($f, $r) => $f + $r, $pub['failed'], $pub['reconcile']))
            ->color($bad > 0 ? 'danger' : 'success')
            ->description($bad > 0 ? 'Lỗi hoặc cần đối soát, bấm để xem' : 'Không có lỗi đăng')
            ->descriptionIcon($bad > 0 ? 'heroicon-m-exclamation-circle' : 'heroicon-m-check-circle')
            ->url(PublicationResource::listUrl());

        $stats = [$published, $review, $problems];

        if ($isApprover) {
            $cost = Q::costByDay(7, $now);
            $today = (float) end($cost['cost']);
            $stat = Stat::make('Chi phí AI hôm nay', number_format($today, 0, ',', '.').' ₫')
                ->chart($cost['cost'])->color('primary');
            if ($cost['cap'] > 0) {
                $pct = (int) round($today / $cost['cap'] * 100);
                $stat->description("{$pct}% trần ngày ".number_format($cost['cap'], 0, ',', '.').' ₫')
                    ->descriptionIcon($pct >= 100 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-banknotes')
                    ->descriptionColor($pct >= 100 ? 'danger' : ($pct >= 80 ? 'warning' : 'gray'));
            } else {
                $stat->description('Chưa đặt trần chi phí ngày')->descriptionIcon('heroicon-m-banknotes');
            }
            $stats[] = $stat;
        }

        return $stats;
    }

    protected function getColumns(): int
    {
        return (bool) auth()->user()?->isApprover() ? 4 : 3;
    }
}
