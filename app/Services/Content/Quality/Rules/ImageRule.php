<?php

namespace App\Services\Content\Quality\Rules;

use App\Services\Content\Quality\QualityContext;
use App\Services\Content\Quality\QualityRule;
use App\Services\Content\Quality\RuleResult;

class ImageRule implements QualityRule
{
    public function code(): string
    {
        return 'IMG01';
    }

    public function evaluate(QualityContext $ctx): array
    {
        $total = 0;
        $missing = [];
        foreach ($ctx->xpath()->query('//img') ?: [] as $img) {
            $total++;
            if (trim((string) $img->getAttribute('alt')) === '') {
                $missing[] = QualityContext::excerpt((string) $img->getAttribute('src'), 80) ?: '(không có src)';
            }
        }

        $featured = null;
        if ($ctx->version->exists) {
            $asset = $ctx->version->mediaAssets()->orderByPivot('is_featured', 'desc')->orderByPivot('position')->first();
            if ($asset) {
                $alt = trim((string) ($asset->pivot->custom_alt ?: $asset->alt_text));
                $featured = $alt !== '';
                if (! $featured) {
                    $missing[] = 'Ảnh đại diện: ' . QualityContext::excerpt((string) $asset->original_name, 60);
                    $total++;
                }
            }
        }

        $n = count($missing);

        return [new RuleResult('IMG01', RuleResult::P1, $n === 0, 'Alt của ảnh', $n === 0
            ? ($total === 0 && $featured === null ? 'Bài chưa có ảnh nào để kiểm tra alt.' : 'Mọi ảnh đều có alt.')
            : "{$n} ảnh chưa có alt. Thêm mô tả ngắn cho từng ảnh (hỗ trợ người khiếm thị và SEO ảnh).",
            "{$n} ảnh thiếu alt", '0 ảnh thiếu alt', $n === 0 ? null : implode('; ', array_slice($missing, 0, 5)))];
    }
}
