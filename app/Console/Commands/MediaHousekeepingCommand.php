<?php

namespace App\Console\Commands;

use App\Services\Media\MediaHousekeeping;
use Illuminate\Console\Command;

/** Báo cáo dọn dẹp thư viện ảnh: chỉ gợi ý, KHÔNG xóa gì. */
class MediaHousekeepingCommand extends Command
{
    protected $signature = 'media:housekeeping {--days=90 : Ảnh chưa dùng trong bài quá số ngày này sẽ được gợi ý lưu trữ} {--json : In báo cáo dạng JSON}';

    protected $description = 'Báo cáo ảnh mồ côi, thiếu file, trùng file, chưa dùng (chỉ gợi ý, không xóa)';

    public function handle(MediaHousekeeping $hk): int
    {
        $r = $hk->report(max(1, (int) $this->option('days')));
        if ($this->option('json')) {
            $this->line(json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }
        $this->info("Tổng {$r['total']} ảnh. Thiếu file: ".count($r['missing']).'; file mồ côi (không bản ghi nào dùng): '.count($r['orphan_files']).'; nhóm file trùng nội dung: '.count($r['duplicates']).'; chưa dùng quá '.$this->option('days').' ngày: '.count($r['unused']).'.');
        foreach (array_slice($r['missing'], 0, 20) as $m) {
            $this->warn("  thiếu file #{$m['id']}: {$m['file_path']}");
        }
        foreach (array_slice($r['orphan_files'], 0, 20) as $f) {
            $this->line("  mồ côi: {$f}");
        }
        $this->line('Lệnh này không xóa gì. Hãy xem lại rồi tự quyết định; ảnh chưa dùng nên được "Lưu trữ" trong giao diện.');

        return self::SUCCESS;
    }
}
