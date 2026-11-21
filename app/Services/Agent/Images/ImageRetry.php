<?php

namespace App\Services\Agent\Images;

use App\Filament\Support\Review\ArticleRows;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Article;
use App\Services\Agent\Run\BackgroundRun;
use App\Services\Images\ImageGeneratorResolver;
use App\Services\Publishing\EmergencyStopService;

/**
 * ẢNH BÙ: bài đã giao cho người duyệt nhưng thiếu ảnh vì nhà cung cấp ảnh lỗi (phần lớn là HTTP 429 hết hạn ngạch) thì tự xếp một lượt "chỉ tạo lại ảnh, giữ nguyên bài viết"
 * sau một khoảng nghỉ, thay vì để người duyệt phải tự bấm Giao làm lại. Chữ của bài không bị đụng tới (chế độ "chỉ ảnh"), nên rẻ và không phá bài đã đạt.
 *
 * Giới hạn cứng: tối đa MAX_ATTEMPTS lượt cho một bài, cách nhau COOLDOWN_MIN phút, chỉ bài còn chờ duyệt (chưa duyệt/đăng), chỉ khi nhà cung cấp ảnh đã cấu hình,
 * không đang dừng khẩn cấp, không có phiên đang chạy; tối đa BATCH bài mỗi nhịp. Lượt thử lại gắn nhãn MARK trong lời nhắn để đếm.
 */
final class ImageRetry
{
    public const MARK = '[auto-image-retry]';

    public const MESSAGE = self::MARK.' Chỉ tạo lại ảnh, giữ nguyên bài viết.';

    public const MAX_ATTEMPTS = 3;

    public const COOLDOWN_MIN = 20;

    public const BATCH = 3;

    /** Chỉ xét phiên trong khoảng này (giờ). */
    public const WINDOW_HOURS = 48;

    /** Phiên (mới nhất của bài) có bước ảnh lỗi tạm thời, chưa có ảnh nào? */
    public static function needsRetry(AgentRun $run): bool
    {
        $step = $run->steps()->where('stage', 'images')->latest('id')->first();
        if (! $step instanceof AgentRunStep || $step->status !== 'warn') {
            return false;
        }
        $d = (array) $step->data;

        return (int) ($d['generated'] ?? 0) === 0 && in_array((string) ($d['skip_code'] ?? ''), ['provider_error', 'not_finished'], true);
    }

    /** Số lượt ảnh bù đã chạy cho bài. */
    public static function attempts(Article $article): int
    {
        return AgentRunStep::query()->where('stage', 'decision')->where('data', 'like', '%'.self::MARK.'%')
            ->whereIn('agent_run_id', AgentRun::query()->where('article_id', $article->id)->select('id'))->count();
    }

    /**
     * Chọn các bài cần ảnh bù. @return list<Article>
     */
    public static function candidates(?\Carbon\CarbonInterface $now = null): array
    {
        $now ??= now();
        $out = [];
        $ids = AgentRun::query()->where('job_type', 'article_write')->where('created_at', '>=', $now->copy()->subHours(self::WINDOW_HOURS))->select('article_id')->distinct()->pluck('article_id');
        foreach (Article::query()->whereIn('id', $ids)->where('workflow_status', 'needs_review')->orderBy('id')->get() as $a) {
            $last = AgentRun::query()->where('article_id', $a->id)->latest('id')->first();
            if (! $last || $last->status === 'running' || $last->created_at->gt($now->copy()->subMinutes(self::COOLDOWN_MIN)) || ! self::needsRetry($last)) {
                continue;
            }
            if (self::attempts($a) >= self::MAX_ATTEMPTS || ArticleRows::liveRun($a) !== null) {
                continue;
            }
            $out[] = $a;
            if (count($out) >= self::BATCH) {
                break;
            }
        }

        return $out;
    }

    /**
     * Khởi chạy ảnh bù cho các bài đủ điều kiện. @return array{started: list<int>, skipped: string|null}
     */
    public static function run(?\Carbon\CarbonInterface $now = null): array
    {
        if (ImageGeneratorResolver::why() !== null) {
            return ['started' => [], 'skipped' => 'Chưa cấu hình nhà cung cấp ảnh.'];
        }
        if (app(EmergencyStopService::class)->isStopped(null)) {
            return ['started' => [], 'skipped' => 'Đang dừng khẩn cấp.'];
        }
        $started = [];
        foreach (self::candidates($now) as $a) {
            if (app(EmergencyStopService::class)->isStopped($a->website_id)) {
                continue;
            }
            BackgroundRun::start($a, null, 10, self::MESSAGE);
            $started[] = (int) $a->id;
        }

        return ['started' => $started, 'skipped' => null];
    }
}
