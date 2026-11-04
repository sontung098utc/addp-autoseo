<?php

namespace App\Contracts;

use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\Website;

interface PublisherInterface
{
    /**
     * Kiểm tra kết nối và tính hợp lệ của credential
     */
    public function testConnection(Website $website): bool;

    /**
     * Tải media/ảnh lên CMS
     * @return array [ 'id' => ..., 'source_url' => ... ]
     */
    public function uploadMedia(Website $website, string $filePath, string $altText): array;

    /**
     * Tạo bài viết bản nháp (Draft) trên CMS
     * @return array [ 'remote_id' => ..., 'preview_url' => ... ]
     */
    public function createDraft(Website $website, ArticleVersion $version): array;

    /**
     * Xuất bản bài viết chính thức lên CMS theo publication_key
     * @return array [ 'remote_id' => ..., 'published_url' => ... ]
     */
    public function publish(Publication $publication): array;

    /**
     * Tra cứu trạng thái từ xa theo publication_key (chống đăng trùng khi timeout)
     */
    public function getByPublicationKey(Website $website, string $publicationKey): ?array;
}
