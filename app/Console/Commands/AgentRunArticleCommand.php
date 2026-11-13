<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\User;
use App\Services\Agent\Refresh\AgentRunDispatcher;
use App\Services\Agent\Settings\AgentAi;
use Illuminate\Console\Command;

/** Chạy phiên đội AI cho một bài ở NỀN (nút "Giao đội AI" khởi chạy lệnh này rồi chuyển người dùng sang trang luồng đang chạy). Không dùng tay. */
class AgentRunArticleCommand extends Command
{
    protected $signature = 'agent:run-article {article : ID bài viết} {--actor= : ID người giao việc} {--redo= : Lời nhắn làm lại của người duyệt (điều phối đọc và quyết định làm lại phần nào)}';

    protected $description = 'Chạy phiên đội AI cho một bài ở nền (nội bộ: nút Giao đội AI)';

    protected $hidden = true;

    public function handle(): int
    {
        $article = Article::find($this->argument('article'));
        $ai = AgentAi::forInteractiveUse();
        if (! $article || $ai === null) {
            $this->error(! $article ? 'Không tìm thấy bài viết.' : 'Chưa kết nối AI.');

            return self::FAILURE;
        }
        $actor = $this->option('actor') ? User::find((int) $this->option('actor')) : null;
        $redo = trim((string) $this->option('redo')) !== '' ? ['message' => mb_substr((string) $this->option('redo'), 0, 1500)] : null;
        $run = AgentRunDispatcher::run($article, $ai, $actor, policy: AgentAi::policy(), redo: $redo);
        $this->info("Phiên #{$run->id}: {$run->status}");

        return self::SUCCESS;
    }
}
