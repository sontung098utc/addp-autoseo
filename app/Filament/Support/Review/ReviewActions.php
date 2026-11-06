<?php

namespace App\Filament\Support\Review;

use App\Models\User;
use App\Models\Website;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/** Phần dùng chung giữa action ở bảng bài và trang xem phiên bản. */
class ReviewActions
{
    public static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_active ? $user : null;
    }

    public static function canApprove(): bool
    {
        return self::user()?->isApprover() ?? false;
    }

    public static function canSubmit(): bool
    {
        return self::user()?->isWriter() ?? false;
    }

    /** Chạy hành động; lỗi nghiệp vụ hiện thành Notification lỗi, không nuốt im. */
    public static function run(callable $fn, string $successTitle): void
    {
        try {
            $fn();
        } catch (InvalidArgumentException $e) {
            Notification::make()->title('Không thực hiện được')->body($e->getMessage())->danger()->send();

            return;
        }
        Notification::make()->title($successTitle)->success()->send();
    }

    /** @return array<int, Forms\Components\Component> */
    public static function approveForm(bool $selfApproval): array
    {
        return [
            Forms\Components\Textarea::make('note')
                ->label($selfApproval ? 'Ghi chú tự duyệt (bắt buộc)' : 'Ghi chú (không bắt buộc)')
                ->helperText($selfApproval ? 'Bạn đang duyệt bài do chính mình tạo; hệ thống ghi log tự duyệt.' : null)
                ->required($selfApproval),
        ];
    }

    /** @return array<int, Forms\Components\Component> */
    public static function rejectForm(): array
    {
        return [Forms\Components\Textarea::make('reason')->label('Lý do từ chối')->required()];
    }

    /** @return array<int, Forms\Components\Component> */
    public static function scheduleForm(): array
    {
        return [
            Forms\Components\Select::make('website_id')->label('Website đích')
                ->options(fn () => Website::where('status', 'active')->orderBy('name')->pluck('name', 'id'))
                ->required(),
            Forms\Components\DateTimePicker::make('scheduled_at_vn')
                ->label('Thời điểm xuất bản (giờ Việt Nam)')
                ->seconds(false)->native(false)
                ->live()->required()
                ->helperText(function (Forms\Get $get): string {
                    $v = $get('scheduled_at_vn');
                    if (! $v) {
                        return 'Giờ UTC tương ứng sẽ hiện ở đây.';
                    }

                    return 'Tương ứng ' . Carbon::parse($v, ReviewWorkflow::TIMEZONE)->utc()->format('d/m/Y H:i') . ' UTC';
                }),
        ];
    }

    /** Đổi giá trị form (đã là chuỗi giờ VN) sang Carbon wall-clock giờ VN. */
    public static function parseVn(string $value): Carbon
    {
        return Carbon::parse($value, ReviewWorkflow::TIMEZONE);
    }
}
