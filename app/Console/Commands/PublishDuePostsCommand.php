<?php

namespace App\Console\Commands;

use App\Jobs\PublishArticleJob;
use App\Models\Publication;
use Illuminate\Console\Command;

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

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $duePublications = Publication::where('status', 'scheduled')
            ->where('scheduled_at_utc', '<=', now())
            ->orderBy('scheduled_at_utc', 'asc')
            ->limit($limit)
            ->get();

        if ($duePublications->isEmpty()) {
            $this->info('Không có bài viết nào đến hạn xuất bản.');
            return Command::SUCCESS;
        }

        $this->info("Tìm thấy {$duePublications->count()} bài viết đến hạn xuất bản. Đang đẩy vào hàng đợi...");

        foreach ($duePublications as $publication) {
            // Đánh dấu sang trạng thái publishing để tránh quét trùng lặp ở lượt tiếp theo
            $publication->update(['status' => 'publishing']);
            PublishArticleJob::dispatch($publication);
            $this->line(" - Đã đưa bài #{$publication->id} (Key: {$publication->publication_key}) vào hàng đợi.");
        }

        $this->info('Hoàn tất đẩy bài vào hàng đợi.');
        return Command::SUCCESS;
    }
}
