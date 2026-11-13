<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Services\Ai\DemoScriptedAiProvider;
use App\Services\Ai\MockAiProvider;
use Illuminate\Console\Command;

/** AG-S01: chạy một phiên đội AI cho một bài từ dòng lệnh. Điểm vào tạm; Dev1 thay bằng job hàng đợi (AG-B10). */
class AgentRunCommand extends Command
{
    protected $signature = 'agent:run {article : ID bài viết} {--demo : Dùng AI giả lập theo kịch bản để xem vòng sửa hội tụ (không phải AI thật)}';

    protected $description = 'Chạy một phiên đội AI (viết → chấm → sửa → bản nháp) cho một bài; không bao giờ xuất bản.';

    public function handle(): int
    {
        $article = Article::find($this->argument('article'));
        if (! $article) {
            $this->error('Không tìm thấy bài viết.');

            return self::FAILURE;
        }
        $demoOk = \App\Filament\Support\Demo\DemoMode::enabled();
        $ai = ($this->option('demo') && $demoOk) ? new DemoScriptedAiProvider() : (\App\Services\Agent\Settings\AgentAi::real() ?? ($demoOk ? new MockAiProvider() : null));
        if ($ai === null) {
            $this->error('Chưa kết nối AI. Cấu hình ở Cấu hình AI → Tích hợp AI (AI giả lập chỉ dùng khi bật STUDIO_DEMO ở môi trường phát triển).');

            return self::FAILURE;
        }
        $run = \App\Services\Agent\Refresh\AgentRunDispatcher::run($article, $ai, null, 'manual', policy: \App\Services\Agent\Settings\AgentAi::policy());
        $this->info("Phiên #{$run->id}: {$run->status}".($run->outcome_reason ? " — {$run->outcome_reason}" : ''));

        return self::SUCCESS;
    }
}
