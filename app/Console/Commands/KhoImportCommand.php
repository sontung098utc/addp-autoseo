<?php

namespace App\Console\Commands;

use App\Services\Knowledge\KhoTransfer;
use Illuminate\Console\Command;

class KhoImportCommand extends Command
{
    protected $signature = 'kho:import {file : Tệp JSON do kho:export tạo ra} {--map=* : Nối website thủ công, dạng "https://ten-mien.vn=ID"} {--dry-run : Chỉ đếm, không ghi gì}';

    protected $description = 'Nhập kho Trí tuệ từ tệp JSON: chỉ THÊM, không đè (Trí tuệ trùng slug bị bỏ qua); website nối theo địa chỉ gốc';

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($data)) {
            $this->error('Không đọc được tệp JSON.');

            return self::FAILURE;
        }
        $map = [];
        foreach ((array) $this->option('map') as $m) {
            if (str_contains((string) $m, '=')) {
                [$u, $id] = explode('=', (string) $m, 2);
                $map[$u] = (int) $id;
            }
        }
        $r = KhoTransfer::import($data, $map, (bool) $this->option('dry-run'));
        $this->info(($this->option('dry-run') ? '[CHẠY THỬ, chưa ghi] ' : '').sprintf('Thêm %d Trí tuệ, %d mục, %d nguồn tài liệu, %d chủ đề, %d bản sử; gắn %d website.', $r['brains_added'], $r['items'], $r['sources'], $r['topics'], $r['versions'], $r['attached']));
        if ($r['brains_skipped'] !== []) {
            $this->warn('Bỏ qua vì đã có (cùng slug): '.implode('; ', $r['brains_skipped']));
        }
        if ($r['unmatched_sites'] !== []) {
            $this->warn('Không thấy website khớp địa chỉ (Trí tuệ chưa gắn): '.implode(', ', $r['unmatched_sites']).'. Dùng --map="địa-chỉ=ID".');
        }

        return self::SUCCESS;
    }
}
