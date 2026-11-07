<?php

namespace App\Services\Content\Quality\Rules;

use App\Services\Content\Quality\BannedTermsProvider;
use App\Services\Content\Quality\FileBannedTerms;
use App\Services\Content\Quality\QualityContext;
use App\Services\Content\Quality\QualityRule;
use App\Services\Content\Quality\RuleResult;

/**
 * BAN01 (P0): bài chứa từ/cụm bị cấm thì bị chặn bằng LUẬT, không chỉ dặn trong prompt.
 * Kiểm tiêu đề, meta, nội dung và FAQ. Khớp nguyên từ, không phân biệt hoa thường.
 */
class BannedTermsRule implements QualityRule
{
    public function __construct(private ?BannedTermsProvider $provider = null)
    {
    }

    public function code(): string
    {
        return 'BAN01';
    }

    public function evaluate(QualityContext $ctx): array
    {
        $terms = ($this->provider ?? new FileBannedTerms())->termsFor($ctx);
        $haystack = $this->haystack($ctx);
        $hits = [];
        foreach ($terms as $group => $phrases) {
            foreach ($phrases as $p) {
                $n = QualityContext::normalize($p);
                if ($n !== '' && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($n, '/').'(?![\p{L}\p{N}])/u', $haystack)) {
                    $hits[] = $p.' ('.$group.')';
                }
            }
        }
        $n = count($hits);

        return [new RuleResult('BAN01', RuleResult::P0, $n === 0, 'Từ bị cấm', $n === 0
            ? 'Không có từ/cụm bị cấm.'
            : "Bài có {$n} từ/cụm bị cấm theo cài đặt website/chiến dịch. Xóa hoặc viết lại rồi kiểm lại.",
            "{$n} cụm", '0 cụm', $n === 0 ? null : implode('; ', array_slice($hits, 0, 5)))];
    }

    private function haystack(QualityContext $ctx): string
    {
        $v = $ctx->version;
        $faq = is_array($v->faq_schema) ? json_encode($v->faq_schema, JSON_UNESCAPED_UNICODE) : '';
        $parts = [$ctx->text(), (string) $v->title, (string) ($v->seo_title ?? ''), (string) ($v->meta_description ?? ''), (string) $faq];

        return QualityContext::normalize(implode(' ', $parts));
    }
}
