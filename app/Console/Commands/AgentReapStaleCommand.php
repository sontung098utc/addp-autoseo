<?php

namespace App\Console\Commands;

use App\Services\Agent\Run\StaleRunReaper;
use Illuminate\Console\Command;

class AgentReapStaleCommand extends Command
{
    protected $signature = 'agent:reap-stale {--minutes=60 : Quá bấy nhiêu phút không có bước mới thì đóng phiên}';

    protected $description = 'Đóng phiên đội AI bị bỏ dở và trả bài kẹt "Đang viết" về trạng thái hợp lý.';

    public function handle(): int
    {
        $r = StaleRunReaper::reap(max(15, (int) $this->option('minutes')));
        $this->info("Đã đóng {$r['runs']} phiên treo, trả trạng thái {$r['articles']} bài.");

        return self::SUCCESS;
    }
}
