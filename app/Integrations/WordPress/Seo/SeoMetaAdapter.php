<?php

namespace App\Integrations\WordPress\Seo;

use App\Models\ArticleVersion;

interface SeoMetaAdapter
{
    /** Trường `meta` đưa vào payload tạo bài (rỗng nếu không hỗ trợ). */
    public function metaFor(ArticleVersion $version): array;

    /** So sánh meta đọc lại từ WP: true=đã lưu, false=không lưu, null=không áp dụng. */
    public function isPersisted(array $remoteMeta, ArticleVersion $version): ?bool;
}
