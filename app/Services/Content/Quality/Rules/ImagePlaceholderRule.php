<?php

namespace App\Services\Content\Quality\Rules;

use App\Services\Content\Quality\QualityContext;
use App\Services\Content\Quality\QualityRule;
use App\Services\Content\Quality\RuleResult;

/** IMG02 (P1): khối ảnh (figure) có chú thích nhưng không có ảnh, hoặc ảnh không có nguồn: ô giữ chỗ lọt ra bài. Không chặn. */
class ImagePlaceholderRule implements QualityRule
{
    public function code(): string
    {
        return 'IMG02';
    }

    public function evaluate(QualityContext $ctx): array
    {
        $bad = [];
        foreach ($ctx->xpath()->query('//figure') ?: [] as $fig) {
            $imgs = $ctx->xpath()->query('.//img', $fig);
            $hasSrc = false;
            foreach ($imgs ?: [] as $img) {
                $hasSrc = $hasSrc || trim((string) $img->getAttribute('src')) !== '';
            }
            if (! $hasSrc) {
                $bad[] = QualityContext::excerpt((string) $fig->textContent, 60) ?: '(khối ảnh trống)';
            }
        }
        foreach ($ctx->xpath()->query('//img[not(@src) or normalize-space(@src)=""]') ?: [] as $img) {
            $bad[] = '(thẻ img không có src)';
        }
        $n = count($bad);

        return [new RuleResult('IMG02', RuleResult::P1, $n === 0, 'Ô ảnh giữ chỗ không có ảnh', $n === 0
            ? 'Không có ô ảnh trống.'
            : "{$n} ô ảnh chỉ có chú thích mà không có ảnh. Chèn ảnh thật hoặc xóa ô.",
            "{$n} ô", '0 ô', $n === 0 ? null : implode('; ', array_slice($bad, 0, 5)))];
    }
}
