<?php

namespace App\Services\Content\Quality\Rules;

use App\Services\Agent\Context\InternalLinkSuggester;
use App\Services\Content\Quality\QualityContext;
use App\Services\Content\Quality\QualityRule;
use App\Services\Content\Quality\RuleResult;

/**
 * AG-S25 LINK01: liên kết trong bài phải trỏ tới trang CÓ THẬT của website (danh sách trang đã quét). Liên kết ra domain lạ
 * hoặc URL nội bộ không có trong danh sách bị cờ P1 (không chặn). Có gợi ý mà bài không có liên kết nội bộ nào → P2.
 * Không có ngưỡng số cứng: đây là thực hành tốt nội bộ, chưa xác minh với Google.
 */
class LinkRule implements QualityRule
{
    public function code(): string
    {
        return 'LINK01';
    }

    public function evaluate(QualityContext $ctx): array
    {
        $site = $ctx->article?->website;
        $host = $site ? InternalLinkSuggester::host((string) $site->base_url) : null;
        $allowed = $site ? InternalLinkSuggester::allowedSet($site) : [];

        $foreign = [];
        $unknown = [];
        $validInternal = 0;
        foreach ($ctx->xpath()->query('//a[@href]') ?: [] as $a) {
            $href = trim((string) $a->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || preg_match('#^(mailto|tel):#i', $href)) {
                continue;
            }
            if ($host !== null && str_starts_with($href, '/') && ! str_starts_with($href, '//')) {
                $href = 'https://'.$host.$href; // đường dẫn tương đối thuộc chính website
            } elseif ($host !== null && str_starts_with($href, '//')) {
                $href = 'https:'.$href;
            }
            $own = $host !== null ? InternalLinkSuggester::normalizeOwn($href, $host) : null;
            if ($own === null) {
                $foreign[] = QualityContext::excerpt($href, 80);
            } elseif (isset($allowed[InternalLinkSuggester::key($own)])) {
                $validInternal++;
            } else {
                $unknown[] = QualityContext::excerpt($href, 80);
            }
        }

        $bad = array_merge($foreign, $unknown);
        $results = [new RuleResult('LINK01', RuleResult::P1, $bad === [], 'Liên kết thuộc website', $bad === []
            ? 'Mọi liên kết trong bài đều trỏ tới trang đã quét của website.'
            : count($foreign).' liên kết ra domain khác và '.count($unknown).' liên kết nội bộ không có trong danh sách trang đã quét của website (có thể là URL bịa). Chỉ giữ liên kết tới trang có thật.',
            count($bad).' liên kết lạ', '0 liên kết lạ', $bad === [] ? null : implode('; ', array_slice($bad, 0, 5)))];

        if ($site && $this->hasSuggestions($ctx)) {
            $results[] = new RuleResult('LINK01', RuleResult::P2, $validInternal > 0, 'Có liên kết nội bộ', $validInternal > 0
                ? "Bài có {$validInternal} liên kết nội bộ hợp lệ."
                : 'Website có trang liên quan đã quét nhưng bài chưa có liên kết nội bộ nào; nên thêm 1–3 liên kết tự nhiên.',
                "{$validInternal} liên kết nội bộ", 'nên có ít nhất 1 khi có trang liên quan');
        }

        return $results;
    }

    private function hasSuggestions(QualityContext $ctx): bool
    {
        if (! $ctx->article) {
            return false;
        }
        $outline = is_array($ctx->version->outline ?? null) ? $ctx->version->outline : [];

        return (new \App\Services\Agent\Context\InternalLinkSuggester())->suggest($ctx->article, $outline) !== [];
    }
}
