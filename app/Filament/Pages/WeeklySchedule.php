<?php

namespace App\Filament\Pages;

use App\Filament\Resources\PublicationResource;
use App\Filament\Support\Schedule\VnTime;
use App\Models\Publication;
use App\Filament\Support\Schedule\RescheduleAction;
use App\Filament\Support\Schedule\ReschedulePublication;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
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

    /** Kéo thả thẻ sang ngày khác (giữ giờ-phút VN). Kiểm quyền/luật ở ReschedulePublication. */
    public function moveToDay(int $publicationId, string $day): void
    {
        $p = Publication::with('articleVersion.article')->find($publicationId);
        try {
            if (! $p) {
                throw new \InvalidArgumentException('Không tìm thấy lượt đăng, tải lại trang.');
            }
            $user = Auth::user();
            if (! $user instanceof \App\Models\User) {
                throw new \InvalidArgumentException('Chưa đăng nhập.');
            }
            $r = (new ReschedulePublication())->moveToDay($user, $p, $day);
            Notification::make()->title('Đã đổi lịch')
                ->body('Đã đổi lịch sang '.VnTime::format(VnTime::fromRaw($r, 'scheduled_at_utc')).' (giờ VN).')->success()->send();
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title('Không đổi được lịch')->body($e->getMessage())->danger()->send();
        }
        $this->dispatch('ss-sched-refresh');
    }

    /** Nút "Đổi lịch" trên thẻ (thay thế kéo thả cho bàn phím/cảm ứng). */
    public function reschedulePublicationAction(): Action
    {
        return Action::make('reschedulePublication')
            ->label('Đổi lịch')
            ->modalHeading('Đổi giờ đăng')
            ->record(fn (array $arguments): ?Publication => Publication::find($arguments['publication'] ?? 0))
            ->modalDescription(fn (array $arguments): string => ($p = Publication::find($arguments['publication'] ?? 0)) ? RescheduleAction::description($p) : '')
            ->form(function (array $arguments): array {
                $id = $arguments['publication'] ?? 0;

                return RescheduleAction::form(fn () => Publication::find($id));
            })
            ->action(function (array $arguments, array $data): void {
                $p = Publication::find($arguments['publication'] ?? 0);
                if (! $p) {
                    Notification::make()->title('Không đổi được lịch')->body('Không tìm thấy lượt đăng, tải lại trang.')->danger()->send();

                    return;
                }
                RescheduleAction::run($p, $data);
            });
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
            'todayKey' => $today,
            'canDrag' => (bool) (Auth::user()?->isApprover()),
            'reasonFor' => fn (Publication $p): ?string => ReschedulePublication::reasonUnavailable(Auth::user(), $p),
            'viewUrl' => fn (Publication $p): string => PublicationResource::getUrl('view', ['record' => $p]),
        ];
    }
}
