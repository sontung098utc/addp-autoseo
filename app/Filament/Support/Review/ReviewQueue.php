<?php

namespace App\Filament\Support\Review;

use App\Models\AgentRun;
use App\Models\Article;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * MỘT nơi định nghĩa các số "chờ duyệt" và "phiên lỗi" để Tổng quan, Đội AI và Kế hoạch không lệch nhau.
 *
 *  - "Bài chờ duyệt" = số BÀI (không phải số phiên, không phải số phiên bản) đang ở trạng thái Chờ duyệt.
 *  - "Chờ người có chuyên môn" = tập con của chúng: bài chờ duyệt mà phiên đội AI mới đưa tới có quyết định "chuyên môn". Luôn ≤ bài chờ duyệt.
 *  - "Phiên bị chặn hoặc lỗi" = phiên có trạng thái blocked hoặc failed, trong khoảng thời gian nêu rõ cạnh số.
 */
final class ReviewQueue
{
    public const PROBLEM_STATUSES = ['blocked', 'failed'];

    /** @return Builder<Article> */
    public static function articlesWaiting(): Builder
    {
        return Article::query()->where('workflow_status', 'needs_review');
    }

    public static function waitingCount(): int
    {
        return self::articlesWaiting()->count();
    }

    /** Bài đang chờ duyệt mà phiên chạy gần đây của đội AI đã dặn cần người có chuyên môn xem. Đếm theo BÀI, không theo phiên. */
    public static function expertCount(CarbonInterface $since): int
    {
        $ids = AgentRun::query()->whereNotNull('article_id')
            ->where('created_at', '>=', $since)->where('status', 'ready_for_review')
            ->whereHas('steps', fn ($s) => $s->where('stage', 'decision')->where('title', 'like', '%chuyên môn%'))
            ->select('article_id');

        return self::articlesWaiting()->whereIn('id', $ids)->count();
    }

    /** Phiên bị chặn hoặc lỗi từ mốc $since (nhãn giao diện phải nêu khoảng thời gian này). */
    public static function problemRunCount(CarbonInterface $since): int
    {
        return AgentRun::query()->whereIn('status', self::PROBLEM_STATUSES)->where('created_at', '>=', $since)->count();
    }
}
