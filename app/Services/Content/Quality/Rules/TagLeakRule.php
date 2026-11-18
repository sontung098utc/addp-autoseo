<?php

namespace App\Services\Content\Quality\Rules;

use App\Services\Content\Quality\QualityContext;
use App\Services\Content\Quality\QualityRule;
use App\Services\Content\Quality\RuleResult;

/**
 * TAG01 (P0): mảnh thẻ HTML rò vào văn bản người đọc thấy ("...Hemoglobin A1c [4].">[a]; khi chỉ số..."). Xảy ra khi một thẻ/thuộc tính bị vỡ
 * (vd. chèn <sup title="..."> vào giữa giá trị thuộc tính). Sau khi bỏ thẻ, văn bản hiển thị không được còn `">`, `'>`, `<tag`, hay `class="`/`href="`/`title="`/`data-x="`.
 */
class TagLeakRule implements QualityRule
{
    public function code(): string
    {
        return 'TAG01';
    }

    public function evaluate(QualityContext $ctx): array
    {
        $found = [];
        foreach ($ctx->xpath()->query('//text()[not(ancestor::script) and not(ancestor::style)]') ?: [] as $n) {
            $t = (string) $n->nodeValue;
            if (preg_match_all('/["\']\/?>|<\/?[A-Za-z][A-Za-z0-9-]*(?=[\s>\/])|\b(?:class|href|title|id|style|data-[a-z-]+)\s*=\s*["\']/u', $t, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$hit, $off]) {
                    $found[] = trim(mb_substr($t, max(0, $off - 40), 90));
                }
            }
        }
        $n = count($found);

        return [new RuleResult('TAG01', RuleResult::P0, $n === 0, 'Mảnh thẻ HTML lọt vào chữ', $n === 0
            ? 'Văn bản hiển thị không có mảnh thẻ HTML.'
            : "{$n} chỗ có mảnh thẻ HTML hiện thành chữ (vd \">\"): thẻ bị vỡ. Không đăng; sửa hoặc dựng lại bài.",
            "{$n} chỗ", '0 chỗ', $n === 0 ? null : implode(' | ', array_slice($found, 0, 3)))];
    }
}
