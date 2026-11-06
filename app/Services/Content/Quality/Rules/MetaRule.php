<?php

namespace App\Services\Content\Quality\Rules;

use App\Services\Content\Quality\QualityContext;
use App\Services\Content\Quality\QualityRule;
use App\Services\Content\Quality\QualityThresholds as T;
use App\Services\Content\Quality\RuleResult;

/**
 * Thiếu meta description để ở P1 (không phải P0): CMS vẫn đăng được bài và tự lấy đoạn trích,
 * chỉ mất cơ hội tối ưu hiển thị — đáng sửa nhưng không đáng chặn duyệt.
 */
class MetaRule implements QualityRule
{
    public function code(): string
    {
        return 'META01';
    }

    public function evaluate(QualityContext $ctx): array
    {
        $v = $ctx->version;
        $title = trim((string) $v->seo_title);
        $tl = mb_strlen($title);
        $okTitle = $tl > 0 && $tl <= T::MAX_SEO_TITLE_CHARS;
        $meta = trim((string) $v->meta_description);
        $ml = mb_strlen($meta);
        $okMeta = $ml >= T::MIN_META_CHARS && $ml <= T::MAX_META_CHARS;
        $slug = (string) $v->slug;
        $okSlug = (bool) preg_match(T::SLUG_PATTERN, $slug);

        return [
            new RuleResult('META01', RuleResult::P1, $okTitle, 'Tiêu đề SEO', match (true) {
                $tl === 0 => 'Chưa có tiêu đề SEO. Nhập tiêu đề tối đa ' . T::MAX_SEO_TITLE_CHARS . ' ký tự.',
                ! $okTitle => "Tiêu đề SEO dài {$tl} ký tự, tối đa " . T::MAX_SEO_TITLE_CHARS . ' để không bị cắt trên Google. Rút gọn lại.',
                default => "Tiêu đề SEO {$tl} ký tự.",
            }, "{$tl} ký tự", '1–' . T::MAX_SEO_TITLE_CHARS . ' ký tự', $okTitle ? null : QualityContext::excerpt($title, 80)),
            new RuleResult('META01', RuleResult::P1, $okMeta, 'Meta description', match (true) {
                $ml === 0 => 'Chưa có meta description (' . T::MIN_META_CHARS . '–' . T::MAX_META_CHARS . ' ký tự). Không chặn duyệt vì CMS tự lấy đoạn trích, nhưng nên viết để tăng tỷ lệ nhấp.',
                $ml < T::MIN_META_CHARS => "Meta description dài {$ml} ký tự, tối thiểu " . T::MIN_META_CHARS . '. Mở rộng cho đủ ý.',
                $ml > T::MAX_META_CHARS => "Meta description dài {$ml} ký tự, tối đa " . T::MAX_META_CHARS . ' để không bị cắt. Rút gọn lại.',
                default => "Meta description {$ml} ký tự.",
            }, "{$ml} ký tự", T::MIN_META_CHARS . '–' . T::MAX_META_CHARS . ' ký tự'),
            new RuleResult('META01', RuleResult::P2, $okSlug, 'Slug', $okSlug
                ? 'Slug hợp lệ.'
                : 'Slug chỉ nên gồm chữ thường không dấu, số và dấu gạch ngang (ví dụ hoc-bong-toan-phan).', null, 'a-z, 0-9, dấu gạch ngang', $okSlug ? null : QualityContext::excerpt($slug, 80)),
        ];
    }
}
