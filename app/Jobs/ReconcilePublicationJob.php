<?php

namespace App\Jobs;

use App\Contracts\CmsErrorInterface;
use App\Models\Publication;
use App\Services\Publishing\PublisherManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcilePublicationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [15, 60, 180];

    public function __construct(public Publication $publication)
    {
    }

    public function handle(PublisherManager $publisherManager): void
    {
        $publication = $this->publication->fresh();

        if (! $publication || $publication->status !== 'needs_reconcile') {
            return;
        }

        $website = $publication->website;
        if (! $website) {
            $publication->update([
                'status' => 'failed',
                'last_error' => 'Không tìm thấy website để đối soát.',
            ]);
            return;
        }

        try {
            $publisher = $publisherManager->getPublisher($website);
            $remotePost = $publisher->getByPublicationKey($website, $publication->publication_key);

            if ($remotePost && ! empty($remotePost['remote_id'])) {
                // Bài viết thực tế ĐÃ được tạo thành công trên CMS trước khi xảy ra timeout
                $publication->update([
                    'status' => 'published',
                    'cms_post_id' => (string) $remotePost['remote_id'],
                    'published_url' => $remotePost['published_url'] ?? $remotePost['preview_url'] ?? null,
                    'published_at_utc' => now(),
                    'last_error' => null,
                ]);

                Log::info("[ReconcilePublicationJob] Đối soát THÀNH CÔNG cho bài #{$publication->id}. Remote ID: {$remotePost['remote_id']}.");
            } else {
                // Bài viết CHƯA được tạo trên CMS (timeout xảy ra trước khi request đến CMS)
                // Chuyển sang failed để người quản trị hoặc hệ thống có thể lập lịch đăng lại an toàn
                $publication->update([
                    'status' => 'failed',
                    'last_error' => 'Đối soát hoàn tất: Bài viết chưa được ghi nhận trên CMS từ xa. An toàn để lên lịch lại.',
                ]);

                Log::info("[ReconcilePublicationJob] Đối soát xác nhận bài #{$publication->id} chưa tồn tại trên CMS.");
            }
        } catch (CmsErrorInterface $e) {
            $publication->update(['last_error' => $e->getMessage()]);
            Log::error("[ReconcilePublicationJob] Lỗi [{$e->errorCode()}] khi đối soát bài #{$publication->id}: {$e->getMessage()}");
            throw $e; // Cho phép retry theo backoff mà vẫn giữ needs_reconcile & ghi chú lỗi đã che
        } catch (Throwable $e) {
            Log::error("[ReconcilePublicationJob] Thất bại khi đối soát bài #{$publication->id}: {$e->getMessage()}");
            throw $e; // Cho phép retry theo backoff nếu CMS vẫn chưa phản hồi
        }
    }
}
