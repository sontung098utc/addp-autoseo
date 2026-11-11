<?php

namespace App\Filament\Support\Explain;

use App\Filament\Support\Schedule\ErrorMasker;
use App\Filament\Support\Schedule\ScheduleExplainer;
use App\Filament\Support\Schedule\VnTime;
use App\Filament\Support\StatusPresenter;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Services\Content\Quality\ContentQualityChecker;
use App\Services\Publishing\EmergencyStopService;
use Carbon\CarbonImmutable;

/**
 * "Vì sao bài này chưa được đăng?" — giải thích bằng CHÍNH các luật đang chạy trong hệ thống
 * (dừng khẩn cấp, trạng thái lượt đăng, website hoạt động, giờ đăng, đã duyệt, kiểm chất lượng),
 * theo thứ tự các cổng của PublishDuePostsCommand/PublishArticleJob. KHÔNG bịa luật: những luật chưa
 * có trong hệ thống (ví dụ nhóm chủ đề nhạy cảm, chế độ chiến dịch) chưa được đưa vào đây.
 *
 * Chỉ đọc dữ liệu; không đổi bất kỳ trạng thái nào.
 */
final class PublishExplainer
{
    public const OK = 'ok';
    public const BLOCKING = 'blocking';
    public const WAITING = 'waiting';
    public const INFO = 'info';

    /**
     * @return array{subject: string, verdict: string, headline: string, next: string, decisive: ?int, checks: list<array{label: string, value: string, state: string, detail: string}>}
     */
    public static function forPublication(Publication $p, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $p->loadMissing(['website', 'articleVersion.article']);
        $version = $p->articleVersion;
        $site = $p->website;
        $status = (string) $p->status;
        $statusInfo = ScheduleExplainer::explain($status);

        $checks = [];

        // 1. Dừng xuất bản (cổng đầu tiên của lệnh quét và của job).
        $stop = app(EmergencyStopService::class);
        $stopInfo = $stop->isStopped($p->website_id) ? $stop->getStopInfo($p->website_id) : null;
        $checks[] = $stopInfo
            ? self::check('Dừng xuất bản', 'Đang dừng', self::BLOCKING, 'Lý do: '.($stopInfo['reason'] ?? 'không ghi').(isset($stopInfo['stopped_by_name']) ? ' · bởi '.$stopInfo['stopped_by_name'] : '').'. Mọi lượt đăng đang tạm hoãn cho tới khi khôi phục.')
            : self::check('Dừng xuất bản', 'Đang tắt', self::OK, 'Không chặn lượt đăng.');

        // 2. Trạng thái của lượt đăng.
        $statusState = match ($status) {
            'published' => self::OK,
            'failed', 'needs_reconcile', 'held', 'paused', 'awaiting_confirm' => self::BLOCKING,
            default => self::WAITING,
        };
        $checks[] = self::check('Trạng thái lượt đăng', StatusPresenter::label($status, 'publication'), $statusState, $statusInfo['why']);

        // 3. Website hoạt động (nếu không, lượt đăng sẽ báo lỗi khi tới giờ).
        $siteActive = $site && $site->status === 'active';
        $checks[] = $siteActive
            ? self::check('Website', $site->name, self::OK, 'Đang hoạt động.')
            : self::check('Website', $site?->name ?? 'Không tìm thấy', self::BLOCKING, 'Website không ở trạng thái hoạt động: khi tới giờ lượt đăng sẽ chuyển sang "Lỗi".');

        // 4. Giờ đăng.
        $at = VnTime::fromRaw($p, 'scheduled_at_utc');
        if ($status === 'scheduled' && $at) {
            $future = $at->greaterThan($now);
            $checks[] = self::check(
                'Giờ đăng',
                VnTime::format($at).' (giờ VN)',
                self::WAITING,
                $future ? 'Chưa tới giờ. Đến giờ, hệ thống tự quét và gửi bài.' : 'Đã tới giờ; đang chờ lần quét kế tiếp của hệ thống.'
            );
        } else {
            $checks[] = self::check('Giờ đăng', $at ? VnTime::format($at).' (giờ VN)' : 'Chưa đặt', self::INFO, 'Chỉ chặn khi lượt đăng đang chờ.');
        }

        // 5. Bản đã duyệt.
        if ($version) {
            $approved = $version->review_status === 'approved';
            $checks[] = self::check('Bản bài', 'v'.$version->version.' · '.StatusPresenter::label((string) $version->review_status, 'article'), $approved ? self::OK : self::BLOCKING, $approved ? 'Bản này đã được duyệt.' : 'Bản này chưa được duyệt nên không nên đăng.');

            // 6. Kiểm chất lượng — chỉ thông tin: hệ thống KHÔNG kiểm lại khi đăng (đã chặn ở bước duyệt).
            $codes = (new ContentQualityChecker())->check($version, $version->article)->blockingCodes();
            $checks[] = $codes === []
                ? self::check('Kiểm chất lượng', 'Không còn lỗi chặn', self::OK, 'Bản bài đạt các luật chặn hiện có.')
                : self::check('Kiểm chất lượng', 'Còn lỗi chặn: '.implode(', ', $codes), self::INFO, 'Lỗi này chặn lúc duyệt. Bản đã duyệt (có thể là duyệt ngoại lệ có lý do) nên không bị kiểm lại khi đăng.');
        }

        // 7. Thông báo lỗi gần nhất (đã che thông tin nhạy cảm).
        $err = ErrorMasker::mask($p->last_error);
        if ($err !== '') {
            $checks[] = self::check('Thông báo gần nhất', ErrorMasker::short($p->last_error, 60), self::INFO, $err);
        }

        [$verdict, $headline, $decisive] = self::decide($status, $checks, $stopInfo !== null, $siteActive, $at, $now);

        return [
            'subject' => $version?->title ?: 'Lượt đăng #'.$p->id,
            'verdict' => $verdict,
            'headline' => $headline,
            'next' => $statusInfo['next'],
            'decisive' => $decisive,
            'checks' => $checks,
        ];
    }

    /** Bản bài chưa có lượt đăng: giải thích vì sao chưa đến bước xuất bản. */
    public static function forVersion(ArticleVersion $v, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $pub = Publication::query()->where('article_version_id', $v->id)->latest('id')->first();
        if ($pub) {
            return self::forPublication($pub, $now);
        }

        $v->loadMissing('article');
        $review = (string) $v->review_status;
        $codes = (new ContentQualityChecker())->check($v, $v->article)->blockingCodes();

        $checks = [
            self::check('Bản bài', 'v'.$v->version.' · '.StatusPresenter::label($review, 'article'), $review === 'approved' ? self::OK : self::BLOCKING, match ($review) {
                'approved' => 'Đã duyệt nhưng chưa được xếp lịch.',
                'needs_review' => 'Đang chờ người duyệt. Chỉ bản đã duyệt mới xếp lịch được.',
                'rejected' => 'Đã bị từ chối; cần sửa và gửi duyệt lại.',
                default => 'Chưa gửi duyệt.',
            }),
            $codes === []
                ? self::check('Kiểm chất lượng', 'Không còn lỗi chặn', self::OK, 'Có thể duyệt bình thường.')
                : self::check('Kiểm chất lượng', 'Còn lỗi chặn: '.implode(', ', $codes), $review === 'approved' ? self::INFO : self::BLOCKING, $review === 'approved' ? 'Đã duyệt (có thể là duyệt ngoại lệ).' : 'Duyệt cần sửa các lỗi này, hoặc duyệt ngoại lệ có lý do.'),
            self::check('Lượt đăng', 'Chưa có', self::BLOCKING, 'Chưa xếp lịch đăng.'),
        ];

        [$headline, $verdict, $decisive] = match ($review) {
            'approved' => ['Đã duyệt nhưng chưa được xếp lịch đăng', 'waiting', 2],
            'needs_review' => ['Đang chờ người duyệt', 'blocked', 0],
            'rejected' => ['Bản này đã bị từ chối', 'blocked', 0],
            default => ['Bản này chưa gửi duyệt', 'blocked', 0],
        };

        return [
            'subject' => $v->title ?: 'Bản bài #'.$v->id,
            'verdict' => $verdict,
            'headline' => $headline,
            'next' => $review === 'approved' ? 'Bấm "Xếp lịch" để đặt giờ đăng.' : 'Hoàn tất duyệt để có thể xếp lịch.',
            'decisive' => $decisive,
            'checks' => $checks,
        ];
    }

    /**
     * @param  list<array{label: string, value: string, state: string, detail: string}>  $checks
     * @return array{0: string, 1: string, 2: ?int}
     */
    private static function decide(string $status, array $checks, bool $stopped, bool $siteActive, ?CarbonImmutable $at, CarbonImmutable $now): array
    {
        return match (true) {
            $status === 'published' => ['done', 'Bài đã được đăng', 1],
            $stopped => ['blocked', 'Đang dừng xuất bản nên chưa đăng', 0],
            $status === 'needs_reconcile' => ['blocked', 'Chờ đối soát với CMS (không đăng lại để tránh trùng bài)', 1],
            $status === 'failed' => ['failed', 'Lần gửi trước bị lỗi', 1],
            $status === 'held' => ['blocked', 'Lượt đăng đang được tạm giữ', 1],
            $status === 'paused' => ['blocked', 'Lượt đăng đã được tạm dừng, chưa gửi lên CMS', 1],
            $status === 'awaiting_confirm' => ['blocked', 'Chờ bạn xác nhận trước khi đăng (điểm dừng của chiến dịch)', 1],
            ! $siteActive => ['blocked', 'Website không hoạt động nên sẽ không đăng được', 2],
            $status === 'publishing' => ['waiting', 'Hệ thống đang gửi bài lên CMS', 1],
            $at && $at->greaterThan($now) => ['waiting', 'Chưa tới giờ đăng', 3],
            default => ['waiting', 'Đã tới giờ, đang chờ hệ thống quét và gửi', 3],
        };
    }

    /** @return array{label: string, value: string, state: string, detail: string} */
    private static function check(string $label, string $value, string $state, string $detail): array
    {
        return ['label' => $label, 'value' => $value, 'state' => $state, 'detail' => $detail];
    }
}
