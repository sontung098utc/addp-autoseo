<?php

namespace App\Console\Commands;

use App\Filament\Support\Gsc\GoogleSearchConsoleSource;
use App\Models\Website;
use Carbon\CarbonImmutable;
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
        $src = GoogleSearchConsoleSource::forWebsite($site);
        if (! $src) {
            $this->error('Chưa có cách đăng nhập nào dùng được: không có file service account đọc được và không có kết nối Google (hoặc thiếu GSC_OAUTH_CLIENT_ID/SECRET).');

            return self::FAILURE;
        }
        $this->line('Đăng nhập bằng: '.$src->authLabel());
        $this->line('Thuộc tính: '.$src->property());
        $to = CarbonImmutable::now()->subDays(3);
        $rows = $src->rows('page', $to->subDays(6), $to);
        if ($src->error() !== null) {
            $this->error('Lỗi: '.$src->error());

            return self::FAILURE;
        }
        if ($rows === []) {
            $this->warn('Gọi được nhưng chưa có dòng dữ liệu nào trong 7 ngày ('.$to->subDays(6)->toDateString().' đến '.$to->toDateString().').');

            return self::SUCCESS;
        }
        $clicks = array_sum(array_column($rows, 'clicks'));
        $impr = array_sum(array_column($rows, 'impressions'));
        $this->info('OK: '.count($rows).' dòng (trang x ngày), '.$clicks.' clicks, '.$impr.' impressions.');

        return self::SUCCESS;
    }
}
