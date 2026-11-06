<?php

namespace App\Services\Content\Quality\Rules;

use App\Services\Content\Quality\QualityContext;
use App\Services\Content\Quality\QualityRule;
use App\Services\Content\Quality\RuleResult;

class FaqRule implements QualityRule
{
    public function code(): string
    {
        return 'FAQ01';
    }

    public function evaluate(QualityContext $ctx): array
    {
        $faq = $ctx->version->faq_schema;
        if (empty($faq)) {
            return [new RuleResult('FAQ01', RuleResult::P2, true, 'FAQ', 'Bài không có FAQ (không bắt buộc).')];
        }
        $items = is_array($faq) && isset($faq['mainEntity']) ? $faq['mainEntity'] : $faq;
        $total = 0;
        $bad = [];
        foreach ((array) $items as $i => $it) {
            $total++;
            $it = (array) $it;
            $q = trim((string) ($it['question'] ?? $it['q'] ?? $it['name'] ?? ''));
            $a = $it['answer'] ?? $it['a'] ?? ($it['acceptedAnswer']['text'] ?? '');
            $a = trim((string) (is_array($a) ? ($a['text'] ?? '') : $a));
            if ($q === '' || $a === '') {
                $bad[] = 'Mục ' . ($i + 1) . ($q === '' ? ': thiếu câu hỏi' : ': thiếu câu trả lời «' . QualityContext::excerpt($q, 60) . '»');
            }
        }
        $n = count($bad);

        return [new RuleResult('FAQ01', RuleResult::P2, $n === 0, 'FAQ', $n === 0
            ? "FAQ có {$total} cặp hỏi/đáp đầy đủ."
            : "{$n}/{$total} mục FAQ thiếu câu hỏi hoặc câu trả lời. Bổ sung đủ cặp hoặc xóa mục thừa (schema FAQ thiếu cặp có thể bị Google bỏ qua).",
            "{$n} mục lỗi", 'đủ cặp hỏi/đáp', $n === 0 ? null : implode('; ', array_slice($bad, 0, 5)))];
    }
}
