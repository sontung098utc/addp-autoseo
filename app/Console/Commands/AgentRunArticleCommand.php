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
    protected $signature = 'agent:run-article {article : ID bài viết} {--actor= : ID người giao việc} {--redo= : Lời nhắn làm lại của người duyệt (điều phối đọc và quyết định làm lại phần nào)} {--campaign= : ID chiến dịch giao bài này (chạy theo cấu hình chiến dịch, xong thì tự giao bài kế tiếp vào chỗ trống)}';

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
        $campaign = $this->option('campaign') ? \App\Models\Campaign::query()->with('website')->find((int) $this->option('campaign')) : null;
        if ($campaign !== null) { // bài do chiến dịch giao: chạy đúng cấu hình chiến dịch, hoàn tất như một nhịp, rồi nối tiếp
            $run = AgentRunDispatcher::run($article, $ai, null, 'schedule', $campaign->id, stop: app(\App\Services\Publishing\EmergencyStopService::class), policy: AgentAi::policy(),
                handoff: app(\App\Services\Agent\Handoff\CmsDraftHandoff::class), checkpoints: (array) $campaign->checkpoints);
            $runner = new \App\Services\Agent\Run\CampaignRunner(fn () => $ai);
            $res = $runner->completeRun($campaign, $run, (string) $article->primary_keyword, \Carbon\CarbonImmutable::now());
            $this->info("Phiên #{$run->id}: {$run->status} — {$res['message']}");
            if (($next = $runner->continueAfter($campaign->refresh(), $res)) !== null) {
                $this->line('Nối tiếp: '.$next['message']);
            }

            return self::SUCCESS;
        }
        $run = AgentRunDispatcher::run($article, $ai, $actor, policy: AgentAi::policy(), redo: $redo);
        $this->info("Phiên #{$run->id}: {$run->status}");

        return self::SUCCESS;
    }
}
