<?php

namespace App\Services\Ai;

use App\Services\Security\UrlSafetyService;
use Illuminate\Support\Facades\Http;

/** AG-S11: adapter OpenAI-tương thích (Chat Completions); base_url cấu hình được và phải qua kiểm SSRF. */
class OpenAiCompatibleProvider extends AbstractLlmProvider
{
    public function __construct(?string $apiKey, string $model, protected ?string $baseUrl = null, ?array $pricing = null)
    {
        parent::__construct($apiKey, $model, $pricing);
    }

    protected function complete(string $system, string $user): array
    {
        $this->requireKey();
        if (empty($this->baseUrl)) {
            throw new AiProviderError('Thiếu base_url cho nhà cung cấp OpenAI-tương thích.');
        }
        try {
            app(UrlSafetyService::class)->validateUrl($this->baseUrl);
        } catch (\InvalidArgumentException $e) {
            throw new AiProviderError('base_url không an toàn: '.$e->getMessage());
        }
        $res = $this->send(fn () => Http::baseUrl(rtrim($this->baseUrl, '/'))->timeout(90)->connectTimeout(10)
            ->withToken($this->apiKey)->acceptJson()
            ->post('/chat/completions', [
                'model' => $this->model, 'temperature' => 0.4, 'response_format' => ['type' => 'json_object'],
                'messages' => [['role' => 'system', 'content' => $system.' Chỉ trả về JSON thuần túy.'], ['role' => 'user', 'content' => $user]],
            ]));
        $body = $this->body($res);
        $text = $body['choices'][0]['message']['content'] ?? null;
        if (! is_string($text) || $text === '') {
            throw new AiSchemaError('Phản hồi không có nội dung văn bản.');
        }
        $in = (int) ($body['usage']['prompt_tokens'] ?? 0);
        $out = (int) ($body['usage']['completion_tokens'] ?? 0);

        return ['text' => $text, 'prompt_tokens' => $in, 'completion_tokens' => $out, 'total_tokens' => (int) ($body['usage']['total_tokens'] ?? ($in + $out))];
    }
}
