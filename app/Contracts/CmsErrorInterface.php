<?php

namespace App\Contracts;

use Throwable;

/**
 * Hợp đồng chung cho mọi ngoại lệ phát sinh từ các CMS connector.
 * Giúp tầng Job (PublishArticleJob, ReconcilePublicationJob) và UI xử lý đồng nhất mà không phụ thuộc Guzzle/Laravel Http.
 */
interface CmsErrorInterface extends Throwable
{
    /**
     * Mã lỗi chuẩn ERR01..ERR07.
     */
    public function errorCode(): string;

    /**
     * Có an toàn để thử lại (retry) tự động hay không.
     */
    public function isRetryable(): bool;

    /**
     * Yêu cầu ghi đã đi nhưng kết quả mơ hồ (timeout, 5xx khi ghi, phản hồi thiếu ID):
     * TUYỆT ĐỐI KHÔNG retry tạo bài mới — bắt buộc chuyển sang needs_reconcile.
     */
    public function isAmbiguous(): bool;

    /**
     * Mã HTTP đi kèm (nếu có).
     */
    public function httpStatus(): ?int;

    /**
     * Nhóm lỗi để UI hiển thị hướng dẫn (auth, network, rate_limit, server, not_found, payload).
     */
    public function category(): string;
}
