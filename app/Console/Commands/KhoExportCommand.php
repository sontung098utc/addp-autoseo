<?php

namespace App\Console\Commands;

use App\Services\Knowledge\KhoTransfer;
use Illuminate\Console\Command;

class KhoExportCommand extends Command
{
    protected $signature = 'kho:export {file : Đường dẫn tệp JSON đầu ra (để NGOÀI repo)} {--website=* : ID website cần xuất kho (có thể nhiều)}';

    protected $description = 'Xuất kho Trí tuệ của website ra tệp JSON để chuyển sang máy khác (không mang người dùng, khóa AI, thông tin kết nối website)';

    public function handle(): int
    {
        $ids = array_values(array_filter(array_map('intval', (array) $this->option('website'))));
        if ($ids === []) {
            $this->error('Cần --website=ID (ít nhất một).');

            return self::FAILURE;
        }
        $file = (string) $this->argument('file');
        if (str_starts_with(realpath(dirname($file)) ?: '', base_path())) {
            $this->error('Không ghi tệp xuất vào trong thư mục dự án (tránh lỡ commit). Chọn đường dẫn ngoài repo.');

            return self::FAILURE;
        }
        $data = KhoTransfer::export($ids);
        file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        @chmod($file, 0600);
        foreach ($data['brains'] as $b) {
            $this->line(sprintf('• %s: %d mục, %d nguồn tài liệu, %d chủ đề', $b['brain']['name'], count($b['items']), count($b['knowledge_sources']), count($b['topics'])));
        }
        $this->info(count($data['brains']).' Trí tuệ → '.$file);

        return self::SUCCESS;
    }
}
