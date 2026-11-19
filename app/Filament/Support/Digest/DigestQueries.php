<?php

namespace App\Filament\Support\Digest;

use App\Filament\Resources\ArticleResource;
use App\Filament\Resources\PublicationResource;
use App\Filament\Resources\WebsiteResource;
use App\Filament\Support\AiUsage\Vnd;
use App\Filament\Support\AiUsage\RunCost;
use App\Filament\Support\Dashboard\DashboardQueries;
use App\Filament\Support\Review\ReviewQueue;
use App\Filament\Support\Schedule\VnTime;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use App\Services\Publishing\EmergencyStopService;
use Carbon\CarbonImmutable;

/**
 * Bản tin hằng ngày (AG-U19, v1): chỉ dùng dữ liệu THẬT đang có trong hệ thống (bản bài, lượt đăng, website).
 * Phần "Đội AI" lấy từ nhật ký phiên chạy (agent_runs). Chưa kết nối Search Console nên bản tin KHÔNG bịa
 * "hiệu quả SEO" — các ghi chú trung thực được trả về trong `notes`.
 *
 * Chỉ đọc. Nhận $now để test cố định thời gian. Số liệu quản lý (chi phí, lượt đăng) chỉ cho trưởng nhóm duyệt trở lên.
 */
final class DigestQueries
{
    /** Tối đa số nhóm việc hiện trên bản tin, để ngày nhiều việc không quá tải. */
    public const MAX_GROUPS = 4;

    /** Từ ngưỡng này trở lên coi là "ngày nhiều việc". */
    public const BUSY_THRESHOLD = 20;

    /**
     * @return array{
     *   state: string, date_label: string, window_label: string, headline: string, is_approver: bool,
     *   stats: list<array{label: string, value: string, sub: string, n: int}>,
     *   groups: list<array{key: string, title: string, count: int, detail: string, url: string, severity: string}>,
     *   stop: ?array<string, mixed>, notes: list<string>
     * }
     */
    public static function build(User $user, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $vnNow = VnTime::toVn($now) ?? $now;
        $isApprover = $user->isApprover();
        $day = $vnNow->subDay();
        // Hai cách đổi cùng một ngày VN: cột *_utc (publications) lưu UTC thật; cột Eloquent tự ghi (created_at, reviewed_at, started_at)
        // lưu theo app.timezone (mặc định giờ VN) — bind nhầm UTC sẽ lệch 7 giờ.
        $utc = VnTime::dayRangeUtc($day);
        $app = VnTime::dayRangeApp($day);

        $stats = $isApprover ? self::managerStats($utc, $app, $now) : self::writerStats($user, $now);
        $groups = $isApprover ? self::managerGroups($user, $now) : self::writerGroups($user, $now);

        $attention = array_sum(array_column($groups, 'count'));
        $hasActivity = array_sum(array_column($stats, 'n')) > 0;
        $state = match (true) {
            ! $hasActivity && $groups === [] => 'empty',
            $attention >= self::BUSY_THRESHOLD => 'busy',
            default => 'normal',
        };

        return [
            'state' => $state,
            'date_label' => self::dateLabel($vnNow),
            'window_label' => self::windowLabel($day),
            'headline' => self::headline($state, $isApprover, $stats, $groups, $day),
            'is_approver' => $isApprover,
            'stats' => $stats,
            'groups' => array_slice($groups, 0, self::MAX_GROUPS),
            'stop' => self::stopInfo(),
            'notes' => [
                self::agentNote(),
                'Chưa đo được hiệu quả SEO: chưa kết nối Search Console (task 5.1). Các con số chỉ cho biết hệ thống đã làm gì, chưa cho biết làm có tốt không.',
            ],
        ];
    }

    /**
     * @param  array{0: string, 1: string}  $utc  cửa sổ [từ, đến) theo UTC, cho cột *_utc
     * @param  array{0: string, 1: string}  $app  cùng cửa sổ theo app.timezone, cho cột Eloquent tự ghi
     * @return list<array{label: string, value: string, sub: string, n: int}>
     */
    private static function managerStats(array $utc, array $app, CarbonImmutable $now): array
    {
        [$from, $to] = $app;
        $created = ArticleVersion::query()->where('created_at', '>=', $from)->where('created_at', '<', $to)->count();
        $approved = ArticleVersion::query()->where('review_status', 'approved')->where('reviewed_at', '>=', $from)->where('reviewed_at', '<', $to)->count();
        $rejected = ArticleVersion::query()->where('review_status', 'rejected')->where('reviewed_at', '>=', $from)->where('reviewed_at', '<', $to)->count();
        // Đã đăng = lượt đăng trạng thái published có published_at_utc (giờ đăng thật) trong cửa sổ — không dùng giờ xếp lịch.
        $published = Publication::query()->where('status', 'published')->where('published_at_utc', '>=', $utc[0])->where('published_at_utc', '<', $utc[1])->count();
        $writeCost = (float) ArticleVersion::query()->where('created_at', '>=', $from)->where('created_at', '<', $to)->sum('cost_vnd');
        $upcoming = self::upcomingCount($now);

        $team = self::agentStats($from, $to);

        return [
            ['label' => 'Bản bài mới', 'value' => (string) $created, 'sub' => '', 'n' => $created],
            ['label' => 'Đã duyệt / từ chối', 'value' => $approved.' / '.$rejected, 'sub' => '', 'n' => $approved + $rejected],
            ['label' => 'Đã đăng', 'value' => (string) $published, 'sub' => $upcoming.' lượt sắp đăng trong 24 giờ tới', 'n' => $published],
            ['label' => 'Chi phí viết bài', 'value' => Vnd::format($writeCost), 'sub' => 'chỉ bước viết của các bản bài mới; chi phí cả luồng đội AI ở mục dưới', 'n' => (int) round($writeCost)],
            ...$team,
        ];
    }

    /** Lượt đăng đã xếp lịch (chưa đăng) có giờ đăng trong 24 giờ tới kể từ $now. */
    public static function upcomingCount(CarbonImmutable $now): int
    {
        return Publication::query()->where('status', 'scheduled')
            ->where('scheduled_at_utc', '>=', VnTime::dbString($now))
            ->where('scheduled_at_utc', '<', VnTime::dbString($now->addDay()))->count();
    }

    /**
     * Số liệu đội AI hôm qua, đọc từ agent_runs (không suy đoán). "Tự thử lại/tự sửa" = số bước thử lại + tổng vòng sửa.
     *
     * @return list<array{label: string, value: string, sub: string, n: int}>
     */
    private static function agentStats(string $from, string $to): array
    {
        $runs = AgentRun::query()->where('started_at', '>=', $from)->where('started_at', '<', $to);
        $total = (clone $runs)->count();
        $ready = (clone $runs)->where('status', 'ready_for_review')->count();
        $held = (clone $runs)->where('status', 'held')->count();
        $failed = (clone $runs)->where('status', 'failed')->count();
        $mock = (clone $runs)->where('ai_mode', 'mock')->count();
        $repairs = (int) (clone $runs)->sum('repair_rounds');
        $retries = AgentRunStep::query()->where('stage', 'retry')->whereIn('agent_run_id', (clone $runs)->select('id'))->count();
        $cost = RunCost::between($from, $to, 'started_at');

        return [
            ['label' => 'Phiên chạy của đội AI', 'value' => (string) $total, 'sub' => ltrim(($failed > 0 ? ", {$failed} phiên lỗi" : '').($mock > 0 ? ", {$mock} phiên dùng AI giả lập" : ''), ', '), 'n' => $total],
            ['label' => 'Đội AI đưa tới chờ duyệt', 'value' => (string) $ready, 'sub' => 'bản nháp đạt mọi luật bắt buộc', 'n' => $ready],
            ['label' => 'Đội AI giữ nháp', 'value' => (string) $held, 'sub' => 'còn lỗi chặn, chưa vào hàng chờ duyệt', 'n' => $held],
            ['label' => 'Tự thử lại / tự sửa', 'value' => $retries.' / '.$repairs, 'sub' => 'lần thử lại khi AI lỗi tạm thời / vòng sửa', 'n' => $retries + $repairs],
            ['label' => 'Chi phí đội AI', 'value' => Vnd::format($cost), 'sub' => 'cả luồng: dàn ý, nghiên cứu, viết, chấm', 'n' => (int) round($cost)],
        ];
    }

    private static function agentNote(): string
    {
        return AgentRun::query()->exists()
            ? 'Phần "Đội AI" lấy từ nhật ký phiên chạy; chỉ tính các phiên của đội AI, không gồm lượt dùng AI ở nơi khác. Chưa có mục để người kiểm mẫu hay gỡ việc AI đã làm.'
            : 'Đội AI chưa chạy phiên nào nên chưa có gì để báo về việc AI tự xử lý.';
    }

    /** @return list<array{label: string, value: string, sub: string, n: int}> */
    private static function writerStats(User $user, CarbonImmutable $now): array
    {
        $rows = DashboardQueries::decisions($user, $now);

        return [
            ['label' => 'Bài chờ duyệt của bạn', 'value' => (string) $rows->where('kind', 'review')->count(), 'sub' => 'đang chờ người duyệt', 'n' => $rows->where('kind', 'review')->count()],
            ['label' => 'Bài cần sửa', 'value' => (string) $rows->whereNotIn('kind', ['review', 'checkpoint'])->count(), 'sub' => 'bị từ chối, cần chỉnh', 'n' => $rows->whereNotIn('kind', ['review', 'checkpoint'])->count()],
        ];
    }

    /** @return list<array{key: string, title: string, count: int, detail: string, url: string, severity: string}> */
    private static function managerGroups(User $user, CarbonImmutable $now): array
    {
        $overdue = DashboardQueries::decisions($user, $now)->where('kind', 'review')->where('overdue', true)->count();
        $waiting = ReviewQueue::waitingCount(); // cùng hàm với Tổng quan, Đội AI, Kế hoạch
        $problems = DashboardQueries::publicationProblems($user)->count();
        $badSites = Website::query()->where('status', '!=', 'active')->count();

        $groups = [];
        if ($problems > 0) {
            $groups[] = self::group('publication_problems', 'Lượt đăng lỗi hoặc chờ đối soát', $problems, 'Cần kiểm tra trước khi đăng lại; hệ thống không tự đăng lại.', PublicationResource::listUrl(), 'danger');
        }
        if ($badSites > 0) {
            $groups[] = self::group('website_issue', 'Website không hoạt động', $badSites, 'Lượt đăng vào website này sẽ báo lỗi khi tới giờ.', WebsiteResource::getUrl('index'), 'warning');
        }
        if ($waiting > 0) {
            $detail = $overdue > 0 ? "Trong đó {$overdue} bài đã chờ quá ".DashboardQueries::OVERDUE_AFTER_HOURS.' giờ, đang giữ cả chuỗi phía sau.' : '';
            $groups[] = self::group('review_queue', 'Bài đang chờ duyệt', $waiting, $detail, ArticleResource::getUrl('index', ['activeTab' => 'needs_review']), $overdue > 0 ? 'warning' : 'info');
        }

        return $groups;
    }

    /** @return list<array{key: string, title: string, count: int, detail: string, url: string, severity: string}> */
    private static function writerGroups(User $user, CarbonImmutable $now): array
    {
        $needFix = DashboardQueries::decisions($user, $now)->whereNotIn('kind', ['review', 'checkpoint'])->count();

        return $needFix > 0
            ? [self::group('needs_fix', 'Bài cần bạn sửa', $needFix, 'Đã bị từ chối, xem lý do và chỉnh lại.', ArticleResource::getUrl('index', ['activeTab' => 'rejected']), 'warning')]
            : [];
    }

    /** @return array{key: string, title: string, count: int, detail: string, url: string, severity: string} */
    private static function group(string $key, string $title, int $count, string $detail, string $url, string $severity): array
    {
        return compact('key', 'title', 'count', 'detail', 'url', 'severity');
    }

    private static function stopInfo(): ?array
    {
        $stop = app(EmergencyStopService::class);

        return $stop->isStopped() ? $stop->getStopInfo() : null;
    }

    private static function headline(string $state, bool $isApprover, array $stats, array $groups, CarbonImmutable $day): string
    {
        if ($state === 'empty') {
            return 'Chưa có hoạt động nào để báo';
        }
        if (! $isApprover) {
            return $groups === [] ? 'Không có việc nào cần bạn' : 'Có việc cần bạn xem';
        }
        $published = (int) explode(' ', $stats[2]['value'])[0];
        $created = (int) $stats[0]['value'];
        $base = "Hôm qua ({$day->format('d/m')}): {$created} bản bài mới, {$published} bài đã đăng";

        return $base; // số nhóm việc nằm ở tiêu đề khối bên dưới, không nhắc lại ở đây
    }

    /** Cửa sổ thời gian mà mọi số liệu "hôm qua" của bản tin dùng; hiện ngay dưới tiêu đề. */
    private static function windowLabel(CarbonImmutable $day): string
    {
        return 'Số liệu tính từ 00:00 đến 24:00 ngày '.$day->format('d/m/Y').' (giờ Việt Nam), tức ngày hôm qua; ngày ở đầu trang là hôm nay.';
    }

    private static function dateLabel(CarbonImmutable $vn): string
    {
        $days = ['Chủ Nhật', 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy'];

        return $days[(int) $vn->dayOfWeek].', '.$vn->format('d/m/Y');
    }
}
