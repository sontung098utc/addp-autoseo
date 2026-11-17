<?php

namespace App\Jobs;

use App\Filament\Support\Review\ArticleRows;
use App\Models\Article;
use App\Services\Agent\Run\BackgroundRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Khởi chạy phiên đội AI cho một bài khi PHP của máy chủ WEB không mở được tiến trình nền (hosting tắt proc_open cho web, mà vẫn bật cho PHP dòng lệnh).
 * Yêu cầu web chỉ ghi một dòng vào hàng đợi (nhanh, không cần proc_open); tiến trình xử lý hàng đợi (php dòng lệnh, chạy mỗi phút dưới cron) nhận việc
 * và mở tiến trình nền thật. Job rất ngắn (chỉ khởi chạy rồi thoát); bài viết do tiến trình nền đó chạy, không do job.
 */
class StartAgentRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Chỉ một lần: lỗi khởi chạy không tự lặp lại để khỏi tạo hai phiên cho một bài. */
    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $articleId, public ?int $actorId = null, public ?string $redoMessage = null, public ?int $campaignId = null) {}

    public function handle(): void
    {
        $article = Article::query()->find($this->articleId);
        if ($article === null) {
            return;
        }
        if (ArticleRows::liveRun($article) !== null) {
            return; // đã có phiên đang chạy (bấm hai lần, hoặc đã được khởi chạy bằng đường khác)
        }
        $run = BackgroundRun::start($article, $this->actorId, 10, $this->redoMessage, $this->campaignId, viaQueue: true);
        if ($run === null) {
            Log::warning('StartAgentRunJob: chưa thấy phiên sau khi khởi chạy từ hàng đợi', ['article' => $this->articleId]);
        }
    }
}
