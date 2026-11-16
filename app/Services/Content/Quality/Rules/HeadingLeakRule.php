<?php

namespace App\Services\Content\Quality\Rules;

use App\Services\Content\Quality\QualityContext;
use App\Services\Content\Quality\QualityRule;
use App\Services\Content\Quality\RuleResult;

/** LEAK01 (P1): nhãn cấu trúc của dàn ý rò vào bài ("H2: Cấu tạo...", "Heading 2 - ..."). Không chặn. */
class HeadingLeakRule implements QualityRule
{
    public function code(): string
    {
        return 'LEAK01';
    }

    public function evaluate(QualityContext $ctx): array
    {
        $bad = [];
        foreach ($ctx->xpath()->query('//h1|//h2|//h3|//h4|//h5|//h6|//p') ?: [] as $n) {
            $t = trim(preg_replace('/\s+/u', ' ', (string) $n->textContent) ?? '');
            if (preg_match('/^(?:H[1-6]|Heading\s*[1-6]?|Tiêu đề\s*[1-6])\s*[:：\-–]\s*\S/iu', $t)) {
                $bad[] = QualityContext::excerpt($t, 70);
            }
        }
        $n = count($bad);

        return [new RuleResult('LEAK01', RuleResult::P1, $n === 0, 'Nhãn cấu trúc rò vào bài', $n === 0
            ? 'Không có nhãn kiểu "H2:" trong tiêu đề hay đoạn.'
            : "{$n} tiêu đề/đoạn bắt đầu bằng nhãn cấu trúc (H1:, H2:...). Xóa nhãn, chỉ giữ nội dung.",
            "{$n} chỗ", '0 chỗ', $n === 0 ? null : implode('; ', array_slice($bad, 0, 5)))];
    }
}
