<?php

namespace App\Services\Ai;

use App\Contracts\AiProviderInterface;
use App\Models\Article;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GeminiAiProvider implements AiProviderInterface
{
    protected Client $httpClient;
    protected ?string $apiKey;
    protected string $model;
    protected string $promptVersion = 'v1.0-gemini-seo-vn';

    public function __construct(?string $apiKey = null, ?string $model = null)
    {
        $this->apiKey = $apiKey ?? config('services.google_ai.api_key', env('GOOGLE_AI_API_KEY'));
        $this->model = $model ?? config('services.google_ai.model', 'gemini-1.5-flash');
        $this->httpClient = new Client([
            'base_uri' => 'https://generativelanguage.googleapis.com/v1beta/',
            'timeout' => 90,
            'connect_timeout' => 10,
        ]);
    }

    public function generateOutline(Article $article, array $options = []): array
    {
        if (empty($this->apiKey)) {
            // Fallback sang MockAiProvider nếu chưa cấu hình API Key
            return (new MockAiProvider())->generateOutline($article, $options);
        }

        $systemPrompt = "Bạn là chuyên gia SEO tiếng Việt hàng đầu. Nhiệm vụ của bạn là lập dàn ý chi tiết cho một bài viết chuẩn SEO.\n"
            . "Từ khóa chính: {$article->primary_keyword}\n"
            . "Ý định tìm kiếm: {$article->search_intent}\n"
            . "Cụm chủ đề: {$article->topic_cluster}\n"
            . "Yêu cầu: Trả về ĐÚNG cấu trúc JSON với key 'outline' là một mảng các đề mục H2/H3 (từ 5 đến 7 đề mục). Không trả thêm văn bản ngoài JSON.";

        $response = $this->callGeminiApi($systemPrompt, "Hãy lập dàn ý chuẩn SEO cho từ khóa '{$article->primary_keyword}'.");

        $data = json_decode($response['text'], true) ?? [];
        $outline = $data['outline'] ?? [
            "1. Giới thiệu tổng quan về {$article->primary_keyword}",
            "2. Lợi ích và tầm quan trọng",
            "3. Quy trình thực hiện chi tiết",
            "4. Câu hỏi thường gặp và giải đáp",
        ];

        return [
            'outline' => $outline,
            'tokens_used' => $response['total_tokens'] ?? 450,
            'model' => $this->model,
            'prompt_version' => $this->promptVersion,
        ];
    }

    public function generateArticle(Article $article, array $outline, array $options = []): array
    {
        if (empty($this->apiKey)) {
            return (new MockAiProvider())->generateArticle($article, $outline, $options);
        }

        $outlineJson = json_encode($outline, JSON_UNESCAPED_UNICODE);
        $systemPrompt = "Bạn là chuyên gia viết bài SEO cao cấp bằng tiếng Việt. Nhiệm vụ là viết một bài viết toàn diện, chuyên nghiệp dựa trên dàn ý được cung cấp.\n"
            . "Từ khóa chính: {$article->primary_keyword}\n"
            . "Dàn ý bài viết: {$outlineJson}\n"
            . "Yêu cầu định dạng bắt buộc (JSON Schema):\n"
            . "{\n"
            . "  \"title\": \"Tiêu đề bài viết hấp dẫn (dưới 70 ký tự)\",\n"
            . "  \"slug\": \"duong-dan-than-thien-seo-khong-dau\",\n"
            . "  \"html_content\": \"Mã HTML ngữ nghĩa (h2, h3, p, ul, li, strong, blockquote). Không dùng script, style, inline style, thẻ h1\",\n"
            . "  \"seo_title\": \"Tiêu đề SEO (tối đa 60 ký tự)\",\n"
            . "  \"meta_description\": \"Mô tả meta hấp dẫn chuẩn SEO (120 - 160 ký tự)\",\n"
            . "  \"faq_schema\": [{\"question\": \"...\", \"answer\": \"...\"}],\n"
            . "  \"sources_cited\": [\"Nguồn 1\", \"Nguồn 2\"]\n"
            . "}\n"
            . "Chỉ trả về JSON thuần túy.";

        $userPrompt = "Hãy viết bài viết hoàn chỉnh chuẩn SEO cho từ khóa '{$article->primary_keyword}'.";
        $response = $this->callGeminiApi($systemPrompt, $userPrompt);

        $data = json_decode($response['text'], true);
        if (!$data || !isset($data['html_content'])) {
            // Nếu parse lỗi JSON, fallback sang mock an toàn
            Log::warning("Gemini trả về không đúng định dạng JSON: " . substr($response['text'], 0, 200));
            return (new MockAiProvider())->generateArticle($article, $outline, $options);
        }

        $totalTokens = $response['total_tokens'] ?? 2500;
        $promptTokens = $response['prompt_tokens'] ?? 1000;
        $candidateTokens = $response['candidate_tokens'] ?? 1500;

        return [
            'title' => $data['title'] ?? Str::title($article->primary_keyword),
            'slug' => $data['slug'] ?? Str::slug($article->primary_keyword),
            'html_content' => $data['html_content'],
            'seo_title' => $data['seo_title'] ?? $data['title'],
            'meta_description' => $data['meta_description'] ?? "Tìm hiểu hướng dẫn chi tiết về {$article->primary_keyword}.",
            'focus_keyword' => $article->primary_keyword,
            'faq_schema' => $data['faq_schema'] ?? [],
            'sources_cited' => $data['sources_cited'] ?? ['Tài liệu công ty'],
            'tokens_used' => $totalTokens,
            'cost_vnd' => $this->calculateCostVnd($promptTokens, $candidateTokens, $this->model),
            'model' => $this->model,
            'prompt_version' => $this->promptVersion,
        ];
    }

    public function calculateCostVnd(int $promptTokens, int $completionTokens, string $model): float
    {
        // Tỷ giá Gemini 1.5 Flash: $0.075 / 1M prompt token, $0.30 / 1M output token (USD/VND = 26.000)
        $promptCostUsd = ($promptTokens / 1000000) * 0.075;
        $completionCostUsd = ($completionTokens / 1000000) * 0.30;
        $totalUsd = $promptCostUsd + $completionCostUsd;

        $costVnd = $totalUsd * 26000;
        return round(max(50.0, $costVnd), 2);
    }

    protected function callGeminiApi(string $systemInstruction, string $userContent): array
    {
        $url = "models/{$this->model}:generateContent?key={$this->apiKey}";

        $payload = [
            'system_instruction' => [
                'parts' => [
                    ['text' => $systemInstruction],
                ],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $userContent],
                    ],
                ],
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature' => 0.4,
            ],
        ];

        try {
            $res = $this->httpClient->post($url, [
                'json' => $payload,
            ]);

            $body = json_decode((string) $res->getBody(), true);
            $candidateText = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $usage = $body['usageMetadata'] ?? [];

            return [
                'text' => $candidateText,
                'prompt_tokens' => $usage['promptTokenCount'] ?? 1000,
                'candidate_tokens' => $usage['candidatesTokenCount'] ?? 1500,
                'total_tokens' => $usage['totalTokenCount'] ?? 2500,
            ];
        } catch (\Throwable $e) {
            Log::error("Lỗi gọi Gemini API: " . $e->getMessage());
            throw $e;
        }
    }
}
