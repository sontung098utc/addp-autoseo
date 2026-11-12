<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Services\Agent\Run\CampaignRunner;
use App\Services\Ai\DemoScriptedAiProvider;
use Illuminate\Console\Command;

/** AG-S01: một nhịp cho mọi chiến dịch đang chạy. Điểm vào tạm theo lịch; Dev1 thay bằng job hàng đợi (AG-B10). */
class AgentTickCommand extends Command
{
    protected $signature = 'agent:tick {--campaign= : Chỉ chạy một chiến dịch} {--demo : Dùng AI giả lập theo kịch bản (không phải AI thật)}';

    protected $description = 'Giao từ khóa kế tiếp của các chiến dịch đang chạy cho đội AI, trong giới hạn bài/ngày.';

    public function handle(): int
    {
        \App\Services\Agent\Run\StaleRunReaper::reap(); // đóng phiên treo trước khi giao việc mới
        $demo = (bool) $this->option('demo');
        $runner = new CampaignRunner(function () use ($demo) {
            if ($demo) {
                return new DemoScriptedAiProvider();
            }
            return \App\Services\Agent\Settings\AgentAi::real();
        });

        $q = Campaign::query()->with('website')->where('status', 'active');
        if ($this->option('campaign')) {
            $q->whereKey($this->option('campaign'));
        }
        foreach ($q->get() as $c) {
            $r = $runner->tick($c);
            $this->line("[{$c->name}] {$r['result']}: {$r['message']}");
        }

        return self::SUCCESS;
    }
}
