<?php

namespace App\Contracts;

use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\Website;

/**
 * Hợp đồng chuẩn FINAL giữa hệ thống và các CMS connector (PR-D).
 *
 * Quy tắc chung:
 * 1. `publication_key` do hệ thống cấp (theo `ReviewWorkflow::publicationKey`), connector không tự sinh khác quy ước.
 * 2. Mọi lỗi từ tầng HTTP phải được map thành `CmsError` / `CmsErrorInterface` (mã ERR01..ERR07), KHÔNG ném Guzzle exception thô.
 * 3. Lỗi ghi mơ hồ (ERR01, 5xx khi ghi, phản hồi ghi thiếu ID) phải đặt `isAmbiguous() === true` để job chuyển `needs_reconcile` thay vì retry tạo bài trùng.
 */
interface PublisherInterface
{
    /**
     * Kiểm tra kết nối và tính hợp lệ của credential.
     * Trả về false nếu xác thực thất bại / không kết nối được, hoặc ném CmsErrorInterface khi cần truyền mã lỗi chi tiết.
     *
     * @throws CmsErrorInterface
     */
    public function testConnection(Website $website): bool;

    /**
     * Tải media/ảnh lên CMS.
     *
     * @return array{id: int|string, source_url: string}
     * @throws CmsErrorInterface
     */
    public function uploadMedia(Website $website, string $filePath, string $altText = ''): array;

    /**
     * Tạo bài viết bản nháp (Draft) trên CMS.
     * Khi truyền `$publicationKey` (do hệ thống cấp), connector gắn khóa này để hỗ trợ đối soát/idempotency.
     *
     * @return array{remote_id: string, preview_url: string, publication_key?: string}
     * @throws CmsErrorInterface
     */
    public function createDraft(Website $website, ArticleVersion $version, ?string $publicationKey = null): array;

    /**
     * Xuất bản bài viết chính thức lên CMS theo `$publication->publication_key`.
     *
     * @return array{remote_id: string, published_url: string, already_existed?: bool}
     * @throws CmsErrorInterface
     */
    public function publish(Publication $publication): array;

    /**
     * Tra cứu trạng thái từ xa theo `publication_key` (chống đăng trùng khi timeout).
     * Chỉ trả về `null` khi chắc chắn bài chưa tồn tại trên CMS (vd HTTP 404 / tìm rỗng hợp lệ).
     * PHẢI ném `CmsErrorInterface` khi gặp lỗi mạng, 5xx, 401/403 (KHÔNG được trả `null` khi lỗi).
     *
     * @return array{remote_id: string, status: int|string, title?: string, published_url?: string, preview_url?: string}|null
     * @throws CmsErrorInterface
     */
    public function getByPublicationKey(Website $website, string $publicationKey): ?array;
}
