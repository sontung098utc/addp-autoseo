<?php

namespace App\Services\Content\Quality\Rules;

use App\Services\Content\Quality\QualityContext;
use App\Services\Content\Quality\QualityRule;
use App\Services\Content\Quality\QualityThresholds as T;
use App\Services\Content\Quality\RuleResult;

class KeywordRule implements QualityRule
{
    public function code(): string
    {
        return 'KW01';
    }

    public function evaluate(QualityContext $ctx): array
    {
        $kw = $ctx->keyword();
        if ($kw === '') {
            return [new RuleResult('KW01', RuleResult::P1, false, 'Từ khóa chính',
                'Chưa có từ khóa chính (focus_keyword của bản hoặc từ khóa của bài) nên không kiểm tra được. Hãy khai báo từ khóa.', null, 'có từ khóa')];
        }

        $text = $ctx->text();
        $occ = substr_count($text, $kw);
        $seoTitle = QualityContext::normalize((string) $ctx->version->seo_title);
        $inTitle = $seoTitle !== '' && str_contains($seoTitle, $kw);
        $titleResult = new RuleResult('KW01', RuleResult::P1, $inTitle, 'Từ khóa trong tiêu đề SEO', $inTitle
            ? "Tiêu đề SEO có chứa «{$kw}»."
            : "Tiêu đề SEO chưa chứa từ khóa «{$kw}». Đưa từ khóa lên gần đầu tiêu đề SEO.", null, 'có trong seo_title', $seoTitle === '' ? 'seo_title trống' : QualityContext::excerpt((string) $ctx->version->seo_title, 80));

        if ($occ === 0) {
            return [
                new RuleResult('KW01', RuleResult::P0, false, 'Từ khóa trong bài',
                    "Từ khóa «{$kw}» hoàn toàn không xuất hiện trong nội dung. Viết lại bài bám sát chủ đề hoặc đổi từ khóa cho đúng.", '0 lần', '≥ 1 lần'),
                $titleResult,
            ];
        }

        $lead = implode(' ', array_slice($ctx->words(), 0, T::KEYWORD_LEAD_WORDS));
        $inLead = str_contains($lead, $kw);
        $inHeading = false;
        foreach ($ctx->headings() as $h) {
            if (str_contains($h['text'], $kw)) {
                $inHeading = true;
                break;
            }
        }
        $kwWords = max(1, count(explode(' ', $kw)));
        $total = max(1, count($ctx->words()));
        $density = round($occ * $kwWords / $total * 100, 2);
        $dense = $density > T::MAX_KEYWORD_DENSITY_PERCENT;

        return [
            new RuleResult('KW01', RuleResult::P0, true, 'Từ khóa trong bài', "Từ khóa «{$kw}» xuất hiện {$occ} lần.", "{$occ} lần", '≥ 1 lần'),
            $titleResult,
            new RuleResult('KW01', RuleResult::P1, $inLead, 'Từ khóa trong ' . T::KEYWORD_LEAD_WORDS . ' từ đầu', $inLead
                ? 'Từ khóa có trong phần mở đầu.'
                : "Từ khóa «{$kw}» chưa có trong " . T::KEYWORD_LEAD_WORDS . ' từ đầu. Nhắc đến từ khóa ngay ở đoạn mở đầu.', null, 'có trong ' . T::KEYWORD_LEAD_WORDS . ' từ đầu',
                $inLead ? null : QualityContext::excerpt($lead, 120)),
            new RuleResult('KW01', RuleResult::P1, $inHeading, 'Từ khóa trong heading', $inHeading
                ? 'Có heading chứa từ khóa.'
                : "Chưa heading nào chứa «{$kw}». Đưa từ khóa vào ít nhất một H2.", null, '≥ 1 heading'),
            new RuleResult('KW01', RuleResult::P2, ! $dense, 'Mật độ từ khóa', ! $dense
                ? "Mật độ từ khóa {$density}%."
                : "Mật độ từ khóa {$density}% cao hơn " . T::MAX_KEYWORD_DENSITY_PERCENT . '%, có dấu hiệu nhồi từ khóa. Dùng biến thể/từ đồng nghĩa.', "{$density}%", '≤ ' . T::MAX_KEYWORD_DENSITY_PERCENT . '%'),
        ];
    }
}
