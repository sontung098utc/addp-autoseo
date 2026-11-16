<?php

namespace App\Filament\Support\Schedule;

use App\Models\Publication;
use App\Services\Publishing\PublicationHold;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action as TableAction;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/** Nút "Tạm dừng bài này" / "Tiếp tục đăng" trên một lượt đăng (bảng Lịch xuất bản). Chỉ tác động đúng lượt đó. */
final class PublicationHoldAction
{
    public static function pause(Publication $p): void
    {
        self::run(fn () => (new PublicationHold)->pause(Auth::user(), $p), 'Đã tạm dừng lượt đăng này', 'Hệ thống sẽ không gửi bài này lên CMS cho đến khi bạn bấm "Tiếp tục đăng". Các bài khác không bị ảnh hưởng.');
    }

    public static function resume(Publication $p): void
    {
        self::run(fn () => (new PublicationHold)->resume(Auth::user(), $p), 'Đã cho bài này đăng tiếp', 'Lượt đăng về "Đã xếp lịch"; đến giờ (hoặc ở lần quét kế tiếp nếu đã quá giờ) hệ thống sẽ gửi bài.');
    }

    private static function run(\Closure $do, string $title, string $body): void
    {
        try {
            $do();
            Notification::make()->title($title)->body($body)->success()->send();
        } catch (InvalidArgumentException $e) {
            Notification::make()->title('Không thực hiện được')->body($e->getMessage())->danger()->send();
        }
    }

    public static function pauseRow(): TableAction
    {
        return TableAction::make('pausePublication')
            ->label('Tạm dừng bài này')->icon('heroicon-o-pause-circle')->color('gray')
            ->visible(fn (Publication $r): bool => $r->status === 'scheduled' && (Auth::user()?->isApprover() ?? false))
            ->requiresConfirmation()->modalHeading('Tạm dừng đăng bài này')
            ->modalDescription('Chỉ lượt đăng của bài này bị dừng; các bài khác trong lịch vẫn đăng bình thường.')
            ->action(fn (Publication $r) => self::pause($r));
    }

    public static function resumeRow(): TableAction
    {
        return TableAction::make('resumePublication')
            ->label(fn (Publication $r): string => $r->status === 'awaiting_confirm' ? 'Xác nhận đăng' : 'Tiếp tục đăng')->icon('heroicon-o-play-circle')->color('success')
            ->visible(fn (Publication $r): bool => in_array($r->status, ['paused', 'awaiting_confirm'], true) && (Auth::user()?->isApprover() ?? false))
            ->action(fn (Publication $r) => self::resume($r));
    }
}
