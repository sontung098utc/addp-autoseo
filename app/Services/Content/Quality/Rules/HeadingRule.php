<?php

namespace App\Services\Content\Quality\Rules;

use App\Services\Content\Quality\QualityContext;
use App\Services\Content\Quality\QualityRule;
use App\Services\Content\Quality\QualityThresholds as T;
use App\Services\Content\Quality\RuleResult;

/**
 * Quy ước: tiêu đề bài (title) do CMS hiển thị thành H1, nên nội dung nên có 0 H1; chấp nhận tối đa 1 H1.
 * Heading đầu tiên được coi là tiếp nối H1 của tiêu đề (H3 ngay sau tiêu đề cũng là nhảy cấp).
 */
class HeadingRule implements QualityRule
{
    public function code(): string
    {
        return 'HEAD01';
    }

    public function evaluate(QualityContext $ctx): array
    {
        $hs = $ctx->headings();
        if (! $hs) {
            return [new RuleResult('HEAD01', RuleResult::P0, false, 'Cấu trúc heading',
                'Bài không có heading nào (H2, H3…). Hãy chia bài thành các mục có tiêu đề H2 để người đọc và công cụ tìm kiếm nắm được cấu trúc.',
                '0 heading', '≥ ' . T::MIN_H2 . ' H2')];
        }

        $h1 = count(array_filter($hs, fn ($h) => $h['level'] === 1));
        $h2 = count(array_filter($hs, fn ($h) => $h['level'] === 2));
        $out = [];
        $out[] = new RuleResult('HEAD01', RuleResult::P1, $h1 <= T::MAX_H1, 'Số H1', $h1 <= T::MAX_H1
            ? "Có {$h1} H1 (tiêu đề bài do CMS hiển thị thành H1)."
            : "Có {$h1} H1 trong nội dung, tối đa " . T::MAX_H1 . '. Đổi các H1 thừa thành H2.', "{$h1} H1", '≤ ' . T::MAX_H1 . ' H1');
        $out[] = new RuleResult('HEAD01', RuleResult::P1, $h2 >= T::MIN_H2, 'Số H2', $h2 >= T::MIN_H2
            ? "Có {$h2} H2."
            : "Bài có {$h2} H2, tối thiểu " . T::MIN_H2 . '. Thêm các mục H2 cho từng ý chính.', "{$h2} H2", '≥ ' . T::MIN_H2 . ' H2');

        $prev = 1;
        $skip = null;
        foreach ($hs as $h) {
            if ($h['level'] > $prev + 1) {
                $skip = "H{$prev} → H{$h['level']}: " . QualityContext::excerpt($h['text'], 80);
                break;
            }
            $prev = $h['level'];
        }
        $out[] = new RuleResult('HEAD01', RuleResult::P1, $skip === null, 'Nhảy cấp heading', $skip === null
            ? 'Heading không nhảy cấp.'
            : 'Heading nhảy cấp (bỏ qua một cấp ở giữa). Sửa để cấp heading tăng dần từng bậc (H2 → H3 → H4).', null, 'không nhảy cấp', $skip);

        return $out;
    }
}
