<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Services\Agent\Autopilot\AutopilotRunner;
use Illuminate\Console\Command;

class AutopilotTickCommand extends Command
{
    protected $signature = 'autopilot:tick {--campaign= : Chỉ chạy một chiến dịch}';

    protected $description = 'Autopilot cho chiến dịch Toàn quyền: khảo sát từ khóa và lập kế hoạch để hàng chờ viết không cạn (không gọi AI viết bài).';

    public function handle(): int
    {
        $q = Campaign::query()->with('website')->where('status', 'active')->where('mode', 'autonomous');
        if ($this->option('campaign')) {
            $q->whereKey($this->option('campaign'));
        }
        foreach ($q->get() as $c) {
            $r = (new AutopilotRunner)->tick($c);
            $this->line("#{$c->id} {$c->name}: {$r['result']}".($r['message'] !== '' ? " — {$r['message']}" : ''));
        }

        return self::SUCCESS;
    }
}
