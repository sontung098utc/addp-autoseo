<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;

/** AG-S11: adapter Anthropic Messages API. Tên model do cấu hình định tuyến quyết định, không hard-code ở đây. */
class AnthropicAiProvider extends AbstractLlmProvider
{
    public const DEFAULT_BASE_URL = 'https://api.anthropic.com';

    private const API_VERSION = '2023-06-01';

    public function __construct(?string $apiKey, string $model, protected ?string $baseUrl = null, ?array $pricing = null, protected int $maxTokens = 8192)
    {
        parent::__construct($apiKey, $model, $pricing);
    }

    protected function complete(string $system, string $user): array
    {
        $this->requireKey();
        $res = $this->send(fn () => Http::baseUrl(rtrim($this->baseUrl ?: self::DEFAULT_BASE_URL, '/'))->timeout((int) config('ai_rate.http_timeout', 90))->connectTimeout(10)
            ->withHeaders(['x-api-key' => $this->apiKey, 'anthropic-version' => self::API_VERSION])->acceptJson()
            ->post('/v1/messages', [
                'model' => $this->model, 'max_tokens' => $this->maxTokens, 'temperature' => 0.4,
                'system' => $system.' Chỉ trả về JSON thuần túy.',
                'messages' => [['role' => 'user', 'content' => $user]],
            ]));
        $body = $this->body($res);
        $text = '';
        foreach ((array) ($body['content'] ?? []) as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }
        if ($text === '') {
            throw new AiSchemaError('Phản hồi Anthropic không có nội dung văn bản.');
        }
        $in = (int) ($body['usage']['input_tokens'] ?? 0);
        $out = (int) ($body['usage']['output_tokens'] ?? 0);

        return ['text' => $text, 'prompt_tokens' => $in, 'completion_tokens' => $out, 'total_tokens' => $in + $out];
    }
}
