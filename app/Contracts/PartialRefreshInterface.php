<?php

namespace App\Contracts;

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Services\Ai\AiSchemaError;

/**
 * G5: làm mới bài MỘT PHẦN (tiết kiệm token so với viết lại cả bài). Tách khỏi AiProviderInterface để nhà cung cấp khác không bị vỡ.
 * AI chỉ nhận phần cần sửa, không nhận cả html_content. Bên gọi phải làm sạch HTML trả về (HtmlSanitizer).
 */
interface PartialRefreshInterface
{
    /**
     * Sinh lại seo_title + meta_description. $issues = chuỗi mô tả lỗi cần khắc phục.
     *
     * @param  list<string>  $issues
     * @return array{seo_title: string, meta_description: string, tokens_used: int, cost_vnd: float, model: string, prompt_version: string}
     *
     * @throws AiSchemaError khi đầu ra sai schema
     */
    public function regenerateMeta(Article $article, ArticleVersion $current, array $issues, array $options = []): array;

    /**
     * Viết lại CHỈ các mục cần sửa.
     *
     * @param  list<array{index:int, heading:string, html:string}>  $sections
     * @param  list<string>  $feedback
     * @return array{sections: array<int,string>, tokens_used: int, cost_vnd: float, model: string, prompt_version: string}
     *                                                                                                                      sections: index => HTML cả mục, bắt đầu bằng <h2>
     *
     * @throws AiSchemaError khi đầu ra sai schema
     */
    public function repairSections(Article $article, ArticleVersion $current, array $sections, array $feedback, array $options = []): array;
}
