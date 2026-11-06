<?php

namespace App\Console\Commands;

use App\Jobs\PublishArticleJob;
use App\Models\Publication;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PublishDuePostsCommand extends Command
{
    /**
     * Tên và chữ ký của lệnh console
     */
    protected $signature = 'autoseo:publish-due-posts {--limit=10 : Số lượng bài viết tối đa mỗi lượt quét}';

    /**
     * Mô tả lệnh
     */
    protected $description = 'Quét các bài viết đến lịch xuất bản và đẩy vào hàng đợi xử lý';

    public function handle(EmergencyStopService $emergencyStop): int
    {
        // 1. Kiểm tra dừng khẩn cấp toàn hệ thống (Task 3.6)
        if ($emergencyStop->isStopped()) {
            $info = $emergencyStop->getStopInfo();
            $reason = $info['reason'] ?? 'Không có lý do';
            $msg = "[DỪNG KHẨN CẤP] Toàn bộ hệ thống đang tạm dừng xuất bản tự động. Lý do: {$reason}";
            $this->warn($msg);
            Log::warning($msg);
            return Command::SUCCESS;
        }

        $limit = (int) $this->option('limit');

        $duePublications = Publication::with(['website', 'articleVersion'])
            ->where('status', 'scheduled')
            ->where('scheduled_at_utc', '<=', now())
            ->orderBy('scheduled_at_utc', 'asc')
            ->limit($limit)
            ->get();

        if ($duePublications->isEmpty()) {
            $this->info('Không có bài viết nào đến hạn xuất bản.');
            return Command::SUCCESS;
        }

        $this->info("Tìm thấy {$duePublications->count()} bài viết đến hạn xuất bản. Đang đẩy vào hàng đợi...");

        $dispatched = 0;
        foreach ($duePublications as $publication) {
            // 2. Kiểm tra dừng khẩn cấp theo từng website
            if ($emergencyStop->isStopped($publication->website_id)) {
                $siteInfo = $emergencyStop->getStopInfo($publication->website_id);
                $siteReason = $siteInfo['reason'] ?? 'Website tạm dừng khẩn cấp';
                $this->warn(" - Bỏ qua bài #{$publication->id} do Website #{$publication->website_id} đang tạm dừng khẩn cấp ({$siteReason}).");
                continue;
            }

            // 3. Kiểm tra tính hợp lệ của website và bài viết
            $website = $publication->website;
            if (! $website || $website->status !== 'active') {
                $this->error(" - Bài #{$publication->id} có website không tồn tại hoặc không active. Bỏ qua.");
                $publication->update([
                    'status' => 'failed',
                    'last_error' => 'Website liên kết không tồn tại hoặc không ở trạng thái active.',
                ]);
                continue;
            }

            // 4. Đánh dấu sang trạng thái publishing và đưa vào hàng đợi
            $publication->update(['status' => 'publishing']);
            PublishArticleJob::dispatch($publication);
            $dispatched++;
            $this->line(" - Đã đưa bài #{$publication->id} (Key: {$publication->publication_key}, CMS: {$website->cms_type}) vào hàng đợi.");
        }

        $this->info("Hoàn tất đẩy {$dispatched}/{$duePublications->count()} bài vào hàng đợi.");
        return Command::SUCCESS;
    }
}
