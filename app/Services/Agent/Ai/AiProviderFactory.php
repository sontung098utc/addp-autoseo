<?php

namespace App\Services\Agent\Ai;

use App\Contracts\AiProviderInterface;
use App\Models\AiProvider;
use App\Services\Ai\AbstractLlmProvider;
use App\Services\Ai\AnthropicAiProvider;
use App\Services\Ai\GeminiAiProvider;
use App\Services\Ai\OpenAiCompatibleProvider;

/** Dựng adapter THẬT theo kiểu provider. Kiểu không biết thì ném lỗi, không bao giờ trả Mock. */
final class AiProviderFactory
{
    public static function make(AiProvider $p, string $model): AbstractLlmProvider
    {
        return match ($p->kind) {
            'gemini' => new GeminiAiProvider($p->api_key, $model, $p->pricing),
            'anthropic' => new AnthropicAiProvider($p->api_key, $model, $p->base_url ?: null, $p->pricing),
            'openai_compat' => new OpenAiCompatibleProvider($p->api_key, $model, $p->base_url, $p->pricing),
            default => throw new \InvalidArgumentException("Kiểu nhà cung cấp AI không hỗ trợ: {$p->kind}"),
        };
    }
}
