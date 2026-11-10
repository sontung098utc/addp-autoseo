<?php

namespace App\Filament\Support\Review;

use App\Models\ArticleVersion;
use App\Models\User;
use App\Models\Website;
use Filament\Forms;
use Filament\Notifications\Notification;
use App\Services\Content\Quality\ContentQualityChecker;
use App\Services\Content\Quality\QualityReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
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

    /** Kiểm chất lượng tại thời điểm gọi (chưa lưu lịch sử). */
    protected static array $qualityCache = [];

    public static function clearCache(): void
    {
        self::$qualityCache = [];
    }

    /** Kiểm chất lượng tại thời điểm gọi (chưa lưu lịch sử). */
    public static function qualityFor(ArticleVersion $version): QualityReport
    {
        if (app()->environment('testing')) {
            return (new ContentQualityChecker)->check($version, $version->article);
        }

        $key = ($version->id ?? spl_object_hash($version)) . ':' . ($version->updated_at?->timestamp ?? 0);
        if (! isset(self::$qualityCache[$key])) {
            self::$qualityCache[$key] = (new ContentQualityChecker)->check($version, $version->article);
        }

        return self::$qualityCache[$key];
    }

    /** Lời giải thích cho nút Duyệt khi còn lỗi P0; null nếu không bị chặn. */
    public static function blockedHint(?QualityReport $quality): ?string
    {
        return $quality?->hasBlocking()
            ? 'Còn lỗi chặn duyệt (' . implode(', ', $quality->blockingCodes()) . '). Duyệt cần nhập lý do ngoại lệ, việc này được ghi log.'
            : null;
    }

    /** @return array<int, Forms\Components\Component> */
    public static function approveForm(bool $selfApproval, ?QualityReport $quality = null): array
    {
        $fields = [];
        if ($quality?->hasBlocking()) {
            $items = '';
            foreach ($quality->blocking() as $r) {
                $items .= '<li><strong>' . e($r->code) . '</strong> — ' . e($r->message) . ($r->measured ? ' (' . e($r->measured) . ', yêu cầu ' . e((string) $r->threshold) . ')' : '') . '</li>';
            }
            $fields[] = Forms\Components\Placeholder::make('quality_blockers')
                ->label('Bài còn lỗi chặn duyệt (P0)')
                ->content(new HtmlString('<ul style="list-style:disc;padding-left:1.25rem">' . $items . '</ul>'));
            $fields[] = Forms\Components\Textarea::make('override_reason')
                ->label('Lý do duyệt ngoại lệ (bắt buộc)')
                ->helperText('Chỉ người có quyền duyệt được bỏ qua lỗi P0. Lý do và mã lỗi được ghi vào log audit.')
                ->required()->minLength(ReviewWorkflow::MIN_OVERRIDE_REASON);
        }
        $fields[] = Forms\Components\Textarea::make('note')
            ->label($selfApproval ? 'Ghi chú tự duyệt (bắt buộc)' : 'Ghi chú (không bắt buộc)')
            ->helperText($selfApproval ? 'Bạn đang duyệt bài do chính mình tạo; hệ thống ghi log tự duyệt.' : null)
            ->required($selfApproval);

        return $fields;
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
                ->live()
                ->helperText(function (Forms\Get $get): ?string {
                    $site = $get('website_id') ? Website::find($get('website_id')) : null;

                    return $site && ! static::connectorAvailable($site)
                        ? "Cảnh báo: connector {$site->cms_type} chưa được cài đặt (WordPress: task 1.2). Vẫn xếp lịch được, nhưng đến giờ lượt đăng sẽ báo Lỗi vì chưa có cách gửi bài."
                        : null;
                })
                ->required(),
            Forms\Components\DateTimePicker::make('scheduled_at_vn')
                ->label('Thời điểm xuất bản (giờ Việt Nam)')
                ->seconds(false)->native(false)
                // Định dạng Việt (ngày/tháng/năm giờ:phút), tuần bắt đầu thứ Hai, bước 5 phút, mặc định 9 giờ sáng ngày mai (giờ VN) thay cho 00:00.
                ->displayFormat('d/m/Y H:i')->firstDayOfWeek(1)->minutesStep(5)->closeOnDateSelection(false)
                ->minDate(fn () => Carbon::now(ReviewWorkflow::TIMEZONE)->startOfDay())
                ->default(fn (): string => Carbon::now(ReviewWorkflow::TIMEZONE)->addDay()->setTime(9, 0)->format('Y-m-d H:i:s'))
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

    /** Connector của CMS đã được đăng ký chưa (không gọi mạng). */
    public static function connectorAvailable(Website $site): bool
    {
        try {
            app(\App\Services\Publishing\PublisherManager::class)->getPublisher($site);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Đổi giá trị form (đã là chuỗi giờ VN) sang Carbon wall-clock giờ VN. */
    public static function parseVn(string $value): Carbon
    {
        return Carbon::parse($value, ReviewWorkflow::TIMEZONE);
    }
}
