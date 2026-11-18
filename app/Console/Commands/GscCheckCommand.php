<?php

namespace App\Console\Commands;

use App\Filament\Support\Gsc\GscConnectionCheck;
use App\Models\Website;
use Illuminate\Console\Command;

/**
 * Kiểm tra kết nối Search Console THẬT cho một website: cách đăng nhập đang dùng, thuộc tính, và một lượt gọi 7 ngày.
 * Không in token hay khóa. Thoát mã khác 0 khi không lấy được số liệu, kèm lý do cụ thể (quyền, loại thuộc tính, khóa hỏng).
 */
class GscCheckCommand extends Command
{
    protected $signature = 'gsc:check {website? : id hoặc tên miền của website (mặc định: website đang hoạt động đầu tiên)}';

    protected $description = 'Gọi thử Search Console thật cho một website và báo kết quả hoặc lý do lỗi.';

    public function handle(): int
    {
        $arg = $this->argument('website');
        $site = $arg === null
            ? Website::where('status', 'active')->orderBy('id')->first()
            : (ctype_digit((string) $arg) ? Website::find((int) $arg) : Website::where('base_url', 'like', '%'.$arg.'%')->first());
        if (! $site) {
            $this->error('Không tìm thấy website.');

            return self::FAILURE;
        }
        $this->line('Website: '.$site->name.' ('.$site->base_url.')');
        $this->line('GSC_AUTH='.config('gsc.auth', 'auto'));
        $r = GscConnectionCheck::run($site);
        if ($r['label']) {
            $this->line('Đăng nhập bằng: '.$r['label']);
        }
        $this->line('Thuộc tính: '.($r['property'] ?? '(không xác định được)'));
        match ($r['level']) {
            'ok' => $this->info('OK: '.$r['message']),
            'empty' => $this->warn($r['message']),
            default => $this->error($r['message']),
        };

        return in_array($r['level'], ['ok', 'empty'], true) ? self::SUCCESS : self::FAILURE;
    }
}
