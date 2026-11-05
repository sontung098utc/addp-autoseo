<?php

namespace App\Services\Content;

use App\Contracts\AiProviderInterface;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Services\Ai\GeminiAiProvider;
use App\Services\Ai\MockAiProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContentPipelineService
{
    protected AiProviderInterface $aiProvider;

    public function __construct(?AiProviderInterface $aiProvider = null)
    {
        if ($aiProvider) {
            $this->aiProvider = $aiProvider;
        } else {
            $apiKey = env('GOOGLE_AI_API_KEY');
            $this->aiProvider = !empty($apiKey) ? new GeminiAiProvider($apiKey) : new MockAiProvider();
        }
    }

    /**
     * Chạy toàn bộ pipeline: Lập dàn ý -> Sinh nội dung -> Làm sạch XSS -> Lưu Version mới
     */
    public function generateArticleVersion(Article $article, array $options = []): ArticleVersion
    {
        return DB::transaction(function () use ($article, $options) {
            // 1. Cập nhật trạng thái đang sinh bản thảo
            $article->update(['workflow_status' => 'drafting']);

            // 2. Sinh dàn ý
            $outlineResult = $this->aiProvider->generateOutline($article, $options);
            $outline = $outlineResult['outline'] ?? [];

            // 3. Sinh nội dung bài viết & metadata
            $articleResult = $this->aiProvider->generateArticle($article, $outline, $options);

            // 4. Kiểm tra an ninh và làm sạch HTML (SEC05)
            $cleanHtml = HtmlSanitizer::clean($articleResult['html_content'] ?? '');

            // 5. Xác định số phiên bản tiếp theo
            $latestVersionNumber = $article->versions()->max('version') ?? 0;
            $newVersionNumber = $latestVersionNumber + 1;

            // 6. Lưu ArticleVersion mới
            $version = ArticleVersion::create([
                'article_id' => $article->id,
                'version' => $newVersionNumber,
                'title' => $articleResult['title'],
                'slug' => $articleResult['slug'],
                'outline' => $outline,
                'html_content' => $cleanHtml,
                'seo_title' => $articleResult['seo_title'],
                'meta_description' => $articleResult['meta_description'],
                'focus_keyword' => $articleResult['focus_keyword'] ?? $article->primary_keyword,
                'faq_schema' => $articleResult['faq_schema'] ?? [],
                'sources_cited' => $articleResult['sources_cited'] ?? [],
                'ai_provider' => $articleResult['model'] ?? 'ai-engine',
                'ai_model' => $articleResult['model'] ?? 'gemini',
                'prompt_version' => $articleResult['prompt_version'] ?? 'v1.0',
                'tokens_used' => $articleResult['tokens_used'] ?? 0,
                'cost_vnd' => $articleResult['cost_vnd'] ?? 0.00,
                'review_status' => 'needs_review',
            ]);

            // 7. Cập nhật trạng thái Article thành chờ duyệt (needs_review)
            $article->update(['workflow_status' => 'needs_review']);

            Log::info("Đã sinh thành công ArticleVersion #{$version->id} (v{$version->version}) cho bài viết #{$article->id}");

            return $version;
        });
    }
}
