<?php

namespace App\Console\Commands;

use App\Services\Agent\Images\ImageRetry;
use Illuminate\Console\Command;

class AgentRetryImagesCommand extends Command
{
    protected $signature = 'agent:retry-images';

    protected $description = 'Tự tạo lại ảnh (chỉ ảnh, giữ nguyên chữ) cho bài đang chờ duyệt mà thiếu ảnh vì nhà cung cấp ảnh lỗi/hết hạn ngạch.';

    public function handle(): int
    {
        $r = ImageRetry::run();
        $this->info($r['skipped'] ?? ('Đã xếp ảnh bù cho '.count($r['started']).' bài'.($r['started'] ? ': #'.implode(', #', $r['started']) : '').'.'));

        return self::SUCCESS;
    }
}
