<?php

namespace App\Filament\Pages;

use App\Filament\Resources\PublicationResource;
use App\Filament\Support\Schedule\VnTime;
use App\Models\Publication;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class WeeklySchedule extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-calendar';

    protected static ?string $navigationLabel = 'Lịch tuần';

    protected static ?string $navigationGroup = 'Lịch đăng';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Lịch tuần';

    protected static ?string $slug = 'lich-tuan';

    protected static string $view = 'filament.pages.weekly-schedule';

    /** Độ lệch so với tuần hiện tại (0 = tuần này). */
    public int $weekOffset = 0;

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return $u instanceof \App\Models\User && $u->is_active && $u->isApprover();
    }

    public function previousWeek(): void
    {
        $this->weekOffset--;
    }

    public function nextWeek(): void
    {
        $this->weekOffset++;
    }

    public function currentWeek(): void
    {
        $this->weekOffset = 0;
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $start = VnTime::weekStart()->addWeeks($this->weekOffset);
        $end = $start->addWeek();
        $today = VnTime::now()->format('Y-m-d');

        $items = Publication::query()
            ->with(['website', 'articleVersion.article'])
            ->where('scheduled_at_utc', '>=', VnTime::dbString($start))
            ->where('scheduled_at_utc', '<', VnTime::dbString($end))
            ->orderBy('scheduled_at_utc')
            ->get()
            ->map(fn (Publication $p) => [$p, VnTime::fromRaw($p, 'scheduled_at_utc')])
            ->groupBy(fn ($pair) => VnTime::toVn($pair[1])->format('Y-m-d'));

        $dayNames = ['Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy', 'Chủ nhật'];
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $start->addDays($i);
            $key = $d->format('Y-m-d');
            $days[] = ['key' => $key, 'name' => $dayNames[$i], 'label' => $d->format('d/m'), 'isToday' => $key === $today, 'items' => $items->get($key, collect())];
        }

        [$ts, $te] = VnTime::todayRangeUtc();

        return [
            'days' => $days,
            'rangeLabel' => $start->format('d/m/Y').' – '.$end->subDay()->format('d/m/Y'),
            'todayQueue' => Publication::with(['website', 'articleVersion.article'])->where('scheduled_at_utc', '>=', $ts)->where('scheduled_at_utc', '<', $te)->orderBy('scheduled_at_utc')->get(),
            'reconcile' => Publication::with(['website', 'articleVersion.article'])->where('status', 'needs_reconcile')->orderBy('scheduled_at_utc')->limit(20)->get(),
            'viewUrl' => fn (Publication $p): string => PublicationResource::getUrl('view', ['record' => $p]),
        ];
    }
}
