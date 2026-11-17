<?php

namespace App\Services\Search;

/**
 * Tìm web qua cầu nối Codex (POST {bridge}/v1/search, scripts/codex_bridge.py): chính model của OpenAI tự tìm bằng công cụ web_search của nó,
 * để đánh giá ChatGPT với năng lực tìm riêng. Cùng cách hoạt động, bộ nhớ và song song hóa với cầu nối Claude. Chọn bằng SEARCH_PROVIDER=codex_bridge.
 */
final class CodexBridgeSearchProvider extends ClaudeBridgeSearchProvider
{
    public const NAME = 'Codex CLI (cầu nối)';

    protected function providerName(): string
    {
        return self::NAME;
    }
}
