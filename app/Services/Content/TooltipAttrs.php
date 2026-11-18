<?php

namespace App\Services\Content;

/**
 * Thuộc tính của chú thích [n] và ghi chú [a]. Tooltip gốc của trình duyệt (title) hiện chậm (~1 giây), không có trên cảm ứng và mục tiêu hover chỉ cỡ chữ nhỏ,
 * nên có thêm data-tip (CSS ở docs/theme/autoseo-article.css hiện tức thì khi hover/focus), tabindex="0" (bàn phím, chạm) và aria-label (trình đọc màn hình).
 * Giữ cả title để khi theme chưa dán CSS vẫn có tooltip như cũ (không hồi quy); data-tip không có CSS thì không hiện gì. Đánh đổi: sau khi dán CSS, tooltip gốc vẫn hiện trễ ngay sau tooltip CSS.
 */
final class TooltipAttrs
{
    public static function attrs(string $tip): string
    {
        $t = e($tip);

        return ' tabindex="0" data-tip="'.$t.'" aria-label="'.$t.'" title="'.$t.'"';
    }
}
