<?php

namespace App\Console\Commands;

use App\Services\Publishing\PublicationStaleReaper;
use Illuminate\Console\Command;

class ReapStalePublicationsCommand extends Command
{
    protected $signature = 'autoseo:reap-stale-publications {--minutes=15 : Quá bấy nhiêu phút ở "Đang đăng" thì xử lý}';

    protected $description = 'Chuyển lượt đăng kẹt "Đang đăng" sang needs_reconcile/failed kèm lý do rõ.';

    public function handle(): int
    {
        $n = PublicationStaleReaper::reap(max(5, (int) $this->option('minutes')));
        $this->info("Đã xử lý {$n} lượt đăng kẹt.");

        return self::SUCCESS;
    }
}
