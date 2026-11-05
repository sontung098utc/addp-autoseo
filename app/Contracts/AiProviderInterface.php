<?php

namespace App\Contracts;

use App\Models\Article;

interface AiProviderInterface
{
    /**
     * Sinh dàn ý bài viết chuẩn SEO dựa trên từ khóa và tài liệu công ty
     * @return array [ 'outline' => [...], 'tokens_used' => int ]
     */
    public function generateOutline(Article $article, array $options = []): array;

    /**
     * Sinh bài viết hoàn chỉnh kèm metadata SEO theo dàn ý đã duyệt
     * @return array [
     *   'title' => string,
     *   'slug' => string,
     *   'html_content' => string,
     *   'seo_title' => string,
     *   'meta_description' => string,
     *   'faq_schema' => array,
     *   'sources_cited' => array,
     *   'tokens_used' => int,
     *   'cost_vnd' => float,
     *   'model' => string,
     *   'prompt_version' => string
     * ]
     */
    public function generateArticle(Article $article, array $outline, array $options = []): array;

    /**
     * Ước tính chi phí theo số lượng token và model (VND)
     */
    public function calculateCostVnd(int $promptTokens, int $completionTokens, string $model): float;
}
