<?php

namespace App\Jobs;

use App\Models\Publication;
use App\Services\Publishing\PublisherManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class PublishArticleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Số lần thử lại tối đa của job
     */
    public int $tries = 3;

    /**
     * Thời gian chờ giữa các lần thử lại (giây) - Exponential backoff
     */
    public array $backoff = [10, 30, 60];

    /**
     * Thời gian tối đa job được chạy trước khi timeout (giây)
     */
    public int $timeout = 120;

    public function __construct(public Publication $publication)
    {
    }

    public function handle(PublisherManager $publisherManager): void
    {
        $publication = $this->publication->fresh();

        if (! $publication) {
            Log::warning("[PublishArticleJob] Publication ID {$this->publication->id} không tồn tại.");
            return;
        }

        // Chống đăng trùng: Nếu bài đã ở trạng thái xuất bản thành công
        if ($publication->status === 'published') {
            Log::info("[PublishArticleJob] Publication #{$publication->id} (Key: {$publication->publication_key}) đã xuất bản trước đó, bỏ qua.");
            return;
        }

        // Nếu bài đang ở trạng thái cần đối soát, không xuất bản mới mà chuyển sang job đối soát
        if ($publication->status === 'needs_reconcile') {
            Log::warning("[PublishArticleJob] Publication #{$publication->id} đang ở trạng thái needs_reconcile. Chuyển sang ReconcilePublicationJob.");
            ReconcilePublicationJob::dispatch($publication);
            return;
        }

        $website = $publication->website;
        if (! $website || $website->status !== 'active') {
            $publication->update([
                'status' => 'failed',
                'last_error' => 'Website liên kết không tồn tại hoặc không ở trạng thái active.',
            ]);
            return;
        }

        // Atomic lock theo website: Tránh bắn đồng thời nhiều bài lên cùng 1 CMS gây quá tải
        $lockKey = "publish_lock_site_{$website->id}";
        $lock = Cache::lock($lockKey, 60);

        if (! $lock->get()) {
            Log::info("[PublishArticleJob] Website #{$website->id} đang bận xuất bản bài khác. Trả job lại hàng đợi sau 15s.");
            $this->release(15);
            return;
        }

        try {
            $publication->update([
                'status' => 'publishing',
                'attempt_count' => $publication->attempt_count + 1,
            ]);

            $publisher = $publisherManager->getPublisher($website);
            $result = $publisher->publish($publication);

            $publication->update([
                'status' => 'published',
                'cms_post_id' => isset($result['remote_id']) ? (string) $result['remote_id'] : null,
                'published_url' => $result['published_url'] ?? null,
                'published_at_utc' => now(),
                'last_error' => null,
            ]);

            Log::info("[PublishArticleJob] Xuất bản thành công Publication #{$publication->id} lên {$website->cms_type} (Remote ID: " . ($result['remote_id'] ?? 'N/A') . ").");
        } catch (ConnectionException $e) {
            // Lỗi mạng hoặc timeout từ CMS sau khi gửi bài -> Căn cứ Quy tắc 4 AGENTS.md:
            // Phải chuyển sang needs_reconcile để đối soát theo publication_key, TUYỆT ĐỐI không tự động retry tạo bài mới
            Log::error("[PublishArticleJob] Lỗi mạng/timeout khi gửi bài #{$publication->id}: {$e->getMessage()}. Chuyển trạng thái sang needs_reconcile.");
            $publication->update([
                'status' => 'needs_reconcile',
                'last_error' => 'Mất kết nối hoặc timeout từ CMS: ' . $e->getMessage(),
            ]);
        } catch (RequestException $e) {
            // Trường hợp response từ server là 504 Gateway Timeout hoặc 502 Bad Gateway
            if (in_array($e->response->status(), [502, 503, 504])) {
                Log::error("[PublishArticleJob] CMS trả về mã HTTP {$e->response->status()} cho bài #{$publication->id}. Chuyển sang needs_reconcile.");
                $publication->update([
                    'status' => 'needs_reconcile',
                    'last_error' => "CMS server error ({$e->response->status()}): {$e->getMessage()}",
                ]);
            } else {
                Log::error("[PublishArticleJob] Lỗi client từ CMS cho bài #{$publication->id}: {$e->getMessage()}");
                $publication->update([
                    'status' => 'failed',
                    'last_error' => $e->getMessage(),
                ]);
            }
        } catch (Throwable $e) {
            Log::error("[PublishArticleJob] Lỗi không xác định khi xuất bản bài #{$publication->id}: {$e->getMessage()}");
            $publication->update([
                'status' => 'failed',
                'last_error' => $e->getMessage(),
            ]);
        } finally {
            $lock->release();
        }
    }
}
