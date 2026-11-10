<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Website;
use App\Services\Knowledge\Build\BrainBuildService;
use App\Services\Knowledge\KnowledgeError;
use Illuminate\Console\Command;

/** G2: xây kho trí tuệ từ chính website công ty. Chạy đồng bộ; chưa tải website thật khi viết (kiểm bằng Http giả). */
class BrainBuildCommand extends Command
{
    protected $signature = 'brain:build {website : ID hoặc tên miền của website}
                            {--max-pages=30 : Số trang tối đa (trần 50)}
                            {--url=* : URL thêm vào, chỉ tên miền của website (lặp lại được)}
                            {--user= : ID người duyệt thực hiện (mặc định: quản trị viên đầu tiên đang hoạt động)}';

    protected $description = 'Đọc các trang của chính website, rút kiến thức vào Kho trí tuệ (thông tin thường tự nhận, còn lại chờ duyệt)';

    public function handle(): int
    {
        $arg = (string) $this->argument('website');
        $site = ctype_digit($arg) ? Website::find((int) $arg) : Website::query()->where('base_url', 'like', '%'.$arg.'%')->first();
        if (! $site) {
            $this->error("Không tìm thấy website: {$arg}");

            return self::FAILURE;
        }
        $by = $this->option('user')
            ? User::find((int) $this->option('user'))
            : User::query()->where('is_active', true)->whereIn('role', ['admin', 'approver'])->orderBy('id')->first();
        if (! $by) {
            $this->error('Không có người duyệt nào để ghi nhận. Dùng --user=ID.');

            return self::FAILURE;
        }
        $this->info("Xây kho từ {$site->base_url} (tối đa ".BrainBuildService::clampPages((int) $this->option('max-pages')).' trang, trần '.number_format(BrainBuildService::totalBudgetVnd(), 0, ',', '.').' ₫)...');
        try {
            $r = (new BrainBuildService)->run($site, $by, ['max_pages' => (int) $this->option('max-pages'), 'urls' => (array) $this->option('url')]);
        } catch (KnowledgeError $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->table(['Trang đọc', 'Trang bỏ qua', 'Trang lỗi', 'Mục tự nhận', 'Mục chờ duyệt', 'Chi phí (ước lượng)', 'Dừng vì'], [[
            $r['pages_read'], $r['pages_skipped'], $r['pages_failed'], $r['items_auto'], $r['items_proposed'], number_format($r['cost_vnd'], 0, ',', '.').' ₫', $r['stopped_reason'] ?? 'hết danh sách',
        ]]);
        foreach ($r['rejected_urls'] as $u) {
            $this->warn("Bỏ URL không hợp lệ: {$u}");
        }
        if ($r['message']) {
            $this->warn($r['message']);
        }
        if ($r['demo']) {
            $this->warn('Đây là AI giả lập (môi trường local), không phải kết quả thật.');
        }

        return self::SUCCESS;
    }
}
