<?php

namespace App\Services\Agent\Run;

use App\Models\AgentRun;
use App\Models\Article;
use Carbon\CarbonImmutable;

/**
 * Dọn phiên chạy bị bỏ dở: tiến trình chết giữa chừng nên phiên mãi ghi "đang chạy" và bài mãi kẹt ở "Đang viết".
 *  - Phiên 'running' quá $minutes phút không có bước mới thì đóng thành 'failed' (ghi lý do); AI đang chậm nhưng còn thử lại thì có bước 'retry' nên không bị đóng.
 *  - Bài ở 'Đang viết' mà không còn phiên đang chạy thì trả về trạng thái hợp lý: đã có bản nháp thì 'Chờ duyệt', chưa có thì 'Kế hoạch'.
 * Không xóa dữ liệu, không đăng gì.
 */
final class StaleRunReaper
{
    public const DEFAULT_MINUTES = 60;

    /** @return array{runs:int,articles:int} số phiên đã đóng và số bài đã trả trạng thái */
    public static function reap(int $minutes = self::DEFAULT_MINUTES): array
    {
        $cut = CarbonImmutable::now('UTC')->subMinutes($minutes);
        $runs = 0;
        foreach (AgentRun::query()->where('status', 'running')->with('steps')->get() as $run) {
            $last = \App\Filament\Support\Agent\RunActivity::lastActivityAt($run);
            if ($last !== null && $last->lt($cut)) {
                $run->update(['status' => 'failed', 'finished_at' => now(), 'outcome_reason' => "Tiến trình bị dừng giữa chừng (quá {$minutes} phút không có bước mới), hệ thống tự đóng phiên. Chưa có gì được đăng."]);
                $runs++;
            }
        }

        $articles = 0;
        foreach (Article::query()->where('workflow_status', 'drafting')->get() as $a) {
            if (AgentRun::query()->where('article_id', $a->id)->where('status', 'running')->exists()) {
                continue;
            }
            $a->update(['workflow_status' => $a->versions()->exists() ? 'needs_review' : 'planned']);
            $articles++;
        }

        return ['runs' => $runs, 'articles' => $articles];
    }
}
