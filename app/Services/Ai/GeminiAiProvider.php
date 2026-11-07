<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;

/**
 * Adapter Google Gemini. AG-S11: dùng chung AbstractLlmProvider; KHÔNG còn rơi về MockAiProvider khi thiếu khóa hay JSON hỏng
 * (nay ném lỗi rõ), khóa gửi qua header thay vì query string để không lọt vào URL/nhật ký.
 */
class GeminiAiProvider extends AbstractLlmProvider
{
    public const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/';

    public function __construct(?string $apiKey = null, ?string $model = null, ?array $pricing = null)
    {
        parent::__construct(
            $apiKey ?? config('services.google_ai.api_key', env('GOOGLE_AI_API_KEY')),
            $model ?? config('services.google_ai.model', 'gemini-1.5-flash'),
            $pricing,
        );
    }

    protected function complete(string $system, string $user): array
    {
        $this->requireKey();

        return $this->callGeminiApi($system, $user);
    }

    protected function callGeminiApi(string $systemInstruction, string $userContent): array
    {
        $res = $this->send(fn () => Http::baseUrl(self::BASE_URL)->timeout(90)->connectTimeout(10)
            ->withHeaders(['x-goog-api-key' => $this->apiKey])->acceptJson()
            ->post("models/{$this->model}:generateContent", [
                'system_instruction' => ['parts' => [['text' => $systemInstruction]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $userContent]]]],
                'generationConfig' => ['temperature' => 0.4, 'response_mime_type' => 'application/json'],
            ]));
        $body = $this->body($res);
        $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (! is_string($text) || $text === '') {
            throw new AiSchemaError('Phản hồi Gemini không có nội dung văn bản.');
        }
        $u = $body['usageMetadata'] ?? [];
        $in = (int) ($u['promptTokenCount'] ?? 0);
        $out = (int) ($u['candidatesTokenCount'] ?? 0);

        return ['text' => $text, 'prompt_tokens' => $in, 'completion_tokens' => $out, 'total_tokens' => (int) ($u['totalTokenCount'] ?? ($in + $out))];
    }
}
