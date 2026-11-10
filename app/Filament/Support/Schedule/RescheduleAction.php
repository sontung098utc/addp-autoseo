<?php

namespace App\Filament\Support\Schedule;

use App\Models\Publication;
use Carbon\CarbonImmutable;
use Filament\Actions\Action as PageAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action as TableAction;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/** Action "Đổi lịch" dùng chung cho bảng, trang xem và Lịch tuần. */
final class RescheduleAction
{
    /** @return array<int, Forms\Components\Component> */
    public static function form(?callable $record = null): array
    {
        return [
            Forms\Components\DateTimePicker::make('scheduled_at_vn')
                ->label('Giờ đăng mới (giờ Việt Nam)')
                ->seconds(false)->native(false)
                ->displayFormat('d/m/Y H:i')->firstDayOfWeek(1)->minutesStep(5)->closeOnDateSelection(false)
                ->live()->required()
                ->default(fn (): ?string => ($record ? $record() : null) ? VnTime::format(VnTime::fromRaw($record(), 'scheduled_at_utc'), 'Y-m-d H:i:s') : null)
                ->helperText(function (Forms\Get $get): string {
                    $v = $get('scheduled_at_vn');
                    if (! $v) {
                        return 'Giờ UTC tương ứng sẽ hiện ở đây. Phải cách hiện tại ít nhất '.ReschedulePublication::RESCHEDULE_LOCK_MINUTES.' phút.';
                    }

                    return 'Tương ứng '.VnTime::fromVn($v)->format('d/m/Y H:i').' UTC';
                }),
        ];
    }

    public static function description(Publication $p): string
    {
        return trim(ReschedulePublication::lockExplanation().' '.(ReschedulePublication::warning($p) ?? ''));
    }

    public static function run(Publication $record, array $data): void
    {
        try {
            $to = CarbonImmutable::parse($data['scheduled_at_vn'], VnTime::TZ);
            $p = (new ReschedulePublication())->reschedule(Auth::user(), $record, $to);
            Notification::make()->title('Đã đổi lịch')->body('Giờ đăng mới: '.VnTime::format(VnTime::fromRaw($p, 'scheduled_at_utc')).' (giờ VN).')->success()->send();
        } catch (InvalidArgumentException $e) {
            Notification::make()->title('Không đổi được lịch')->body($e->getMessage())->danger()->send();
        }
    }

    public static function row(): TableAction
    {
        return TableAction::make('reschedule')
            ->label('Đổi lịch')->icon('heroicon-o-clock')->color('gray')
            ->modalHeading('Đổi giờ đăng')
            ->modalDescription(fn (Publication $record): string => self::description($record))
            ->form(fn (Publication $record): array => self::form(fn () => $record))
            ->visible(fn (Publication $record): bool => (Auth::user()?->isApprover() ?? false))
            ->disabled(fn (Publication $record): bool => ReschedulePublication::reasonUnavailable(Auth::user(), $record) !== null)
            ->tooltip(fn (Publication $record): ?string => ReschedulePublication::reasonUnavailable(Auth::user(), $record))
            ->action(fn (Publication $record, array $data) => self::run($record, $data));
    }

    public static function page(): PageAction
    {
        return PageAction::make('reschedule')
            ->label('Đổi lịch')->icon('heroicon-o-clock')->color('gray')
            ->modalHeading('Đổi giờ đăng')
            ->modalDescription(fn (Publication $record): string => self::description($record))
            ->form(fn (Publication $record): array => self::form(fn () => $record))
            ->visible(fn (Publication $record): bool => (Auth::user()?->isApprover() ?? false))
            ->disabled(fn (Publication $record): bool => ReschedulePublication::reasonUnavailable(Auth::user(), $record) !== null)
            ->tooltip(fn (Publication $record): ?string => ReschedulePublication::reasonUnavailable(Auth::user(), $record))
            ->action(fn (Publication $record, array $data) => self::run($record, $data));
    }
}
