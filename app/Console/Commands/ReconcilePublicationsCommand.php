<?php

namespace App\Console\Commands;

use App\Jobs\ReconcilePublicationJob;
use App\Models\Publication;
use Illuminate\Console\Command;

class ReconcilePublicationsCommand extends Command
{
    /**
     * Tên và chữ ký của lệnh console
     */
    protected $signature = 'autoseo:reconcile-publications {--limit=20 : Số lượng bài viết đối soát tối đa}';

    /**
     * Mô tả lệnh
     */
    protected $description = 'Quét và đối soát các bài viết bị timeout hoặc mất kết nối mạng (needs_reconcile)';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $pending = Publication::where('status', 'needs_reconcile')
            ->limit($limit)
            ->get();

        if ($pending->isEmpty()) {
            $this->info('Không có bài viết nào cần đối soát.');
            return Command::SUCCESS;
        }

        $this->info("Tìm thấy {$pending->count()} bài viết cần đối soát trạng thái với CMS từ xa...");

        foreach ($pending as $publication) {
            ReconcilePublicationJob::dispatch($publication);
            $this->line(" - Đã đưa bài #{$publication->id} (Key: {$publication->publication_key}) vào hàng đợi đối soát.");
        }

        $this->info('Hoàn tất đẩy các bài cần đối soát vào hàng đợi.');
        return Command::SUCCESS;
    }
}
