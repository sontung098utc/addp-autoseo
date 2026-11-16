<?php

namespace App\Services\Content\Quality\Rules;

use App\Services\Content\AiPaddingFilter;
use App\Services\Content\Quality\QualityContext;
use App\Services\Content\Quality\QualityRule;
use App\Services\Content\Quality\RuleResult;

/** PAD01 (P1): cụm đệm kiểu văn máy ("Tóm lại,", "đóng vai trò then chốt"...). Dùng AiPaddingFilter có sẵn cộng vài cụm gặp nhiều khi chạy thử Gemini. Không chặn. */
class PaddingRule implements QualityRule
{
    /** Cụm bổ sung (chữ thường) lặp lại trong 10 bài chạy thử 12/11/2026, chưa có trong AiPaddingFilter. */
    public const EXTRA = ['đóng vai trò cốt lõi', 'đóng vai trò quyết định', 'trong bối cảnh kinh tế số', 'thời gian là vàng bạc', 'kỷ nguyên số'];

    public function code(): string
    {
        return 'PAD01';
    }

    public function evaluate(QualityContext $ctx): array
    {
        $text = mb_strtolower($ctx->text(), 'UTF-8');
        $found = [];
        foreach ((new AiPaddingFilter)->detectPadding($ctx->html())['matches'] ?? [] as $phrase => $count) {
            $found[] = is_string($phrase) ? $phrase : (string) $count;
        }
        foreach (self::EXTRA as $p) {
            if (str_contains($text, $p)) {
                $found[] = $p;
            }
        }
        $found = array_values(array_unique($found));
        $n = count($found);

        return [new RuleResult('PAD01', RuleResult::P1, $n === 0, 'Cụm đệm kiểu văn máy', $n === 0
            ? 'Không thấy cụm đệm AI thường gặp.'
            : "Có {$n} cụm đệm kiểu văn máy. Viết lại câu cho đi thẳng vào ý, bỏ các cụm sáo rỗng.",
            "{$n} cụm", '0 cụm', $n === 0 ? null : implode('; ', array_slice($found, 0, 6)))];
    }
}
