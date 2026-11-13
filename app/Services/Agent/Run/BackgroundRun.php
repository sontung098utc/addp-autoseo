<?php

namespace App\Services\Agent\Run;

use App\Models\AgentRun;
use App\Models\Article;
use App\Services\Images\ImageJob;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Khởi chạy phiên đội AI ở NỀN, tách khỏi yêu cầu web: bấm "Giao việc" không bị treo hàng phút mà chuyển ngay sang trang luồng đang chạy.
 * Chỉ khi CSDL dùng chung được giữa hai tiến trình (không phải SQLite :memory:, nên test vẫn chạy đồng bộ).
 * Tiến trình con nhận đúng cấu hình CSDL của tiến trình cha (xem ImageJob::childEnv) và sống sau khi yêu cầu web kết thúc (nohup, ghi log vào storage/logs).
 */
final class BackgroundRun
{
    public static function available(): bool
    {
        return ImageJob::dbShareable() && (bool) config('agent.background_runs', true);
    }

    /** Chờ tối đa $waitSeconds để phiên vừa khởi chạy ghi hàng của nó. @return ?AgentRun null nếu không khởi chạy được hoặc chưa thấy phiên */
    public static function start(Article $article, ?int $actorId, int $waitSeconds = 10, ?string $redoMessage = null): ?AgentRun
    {
        $before = (int) AgentRun::query()->where('article_id', $article->id)->max('id');
        $php = (new PhpExecutableFinder)->find(false) ?: PHP_BINARY;
        $log = storage_path('logs/agent-run-article.log');
        $cmd = 'nohup '.escapeshellarg($php).' artisan agent:run-article '.(int) $article->id.($actorId ? ' --actor='.$actorId : '').($redoMessage !== null && trim($redoMessage) !== '' ? ' --redo='.escapeshellarg(mb_substr($redoMessage, 0, 1500)) : '').' >> '.escapeshellarg($log).' 2>&1 &';
        try {
            (new Process(['sh', '-c', $cmd], base_path(), ImageJob::childEnv()))->run();
        } catch (\Throwable) {
            return null;
        }
        for ($i = 0; $i < $waitSeconds * 4; $i++) {
            $run = AgentRun::query()->where('article_id', $article->id)->where('id', '>', $before)->latest('id')->first();
            if ($run) {
                return $run;
            }
            usleep(250_000);
        }

        return null;
    }
}
