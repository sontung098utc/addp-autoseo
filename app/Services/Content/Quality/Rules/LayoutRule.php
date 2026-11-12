<?php

namespace App\Services\Content\Quality\Rules;

use App\Services\Content\Quality\QualityContext;
use App\Services\Content\Quality\QualityRule;
use App\Services\Content\Quality\RuleResult;

/**
 * LAY01 (P1): dùng khối bố cục có lý do, không lạm dụng (kỹ năng writer/article-layout). Tất định, không AI. Không chặn nhưng ĐƯA VÀO VÒNG SỬA (QualityReport::layoutGaps).
 *  - Khung lưu ý (autoseo-callout) tối đa 2 cái (khung miễn trừ y tế không tính); không đựng danh sách/bảng; không dài quá 60 từ; không lặp câu miễn trừ.
 *  - Mục lục chỉ ở bài từ 1.200 từ và có từ 4 h2; tóm tắt nhanh chỉ ở bài từ 800 từ.
 *  - Bảng phải có từ 2 cột và từ 2 hàng dữ liệu.
 * Ngưỡng là heuristic của hệ thống, chưa có nghiên cứu chứng minh; chỉ cảnh báo.
 */
class LayoutRule implements QualityRule
{
    public const MAX_CALLOUTS = 2;
    public const CALLOUT_MAX_WORDS = 60;
    public const TOC_MIN_WORDS = 1200;
    public const TOC_MIN_H2 = 4;
    public const SUMMARY_MIN_WORDS = 800;

    public function code(): string
    {
        return 'LAY01';
    }

    private static function hasClass(string $cls): string
    {
        return "contains(concat(' ', normalize-space(@class), ' '), ' {$cls} ')";
    }

    private static function wc(string $t): int
    {
        return count(preg_split('/\s+/u', trim($t), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    public function evaluate(QualityContext $ctx): array
    {
        $xp = $ctx->xpath();
        $words = count($ctx->words());
        $issues = [];
        $disc = (new \ReflectionClassConstant(MedicalSafetyRule::class, 'DISCLAIMER'))->getValue();

        $callouts = [];
        $disclaimers = 0;
        foreach ($xp->query('//aside['.self::hasClass('autoseo-callout').']|//div['.self::hasClass('autoseo-callout').']') ?: [] as $n) {
            $text = trim(preg_replace('/\s+/u', ' ', (string) $n->textContent) ?? '');
            if (preg_match($disc, $text) === 1) {
                $disclaimers++;

                continue;
            }
            $callouts[] = [$n, $text];
        }
        if (count($callouts) > self::MAX_CALLOUTS) {
            $issues[] = 'Có '.count($callouts).' khung lưu ý, tối đa '.self::MAX_CALLOUTS.': gộp hoặc đổi các khung ít quan trọng thành đoạn/đề mục thường.';
        }
        foreach ($callouts as [$n, $text]) {
            if ($xp->query('.//ul|.//ol|.//table', $n)->length > 0) {
                $issues[] = 'Khung lưu ý «'.QualityContext::excerpt($text, 50).'» đựng danh sách/bảng: đổi thành đề mục và danh sách thường.';
            } elseif (self::wc($text) > self::CALLOUT_MAX_WORDS) {
                $issues[] = 'Khung lưu ý «'.QualityContext::excerpt($text, 50).'» quá dài ('.self::wc($text).' từ, tối đa '.self::CALLOUT_MAX_WORDS.'): rút còn 1-2 câu hoặc đổi thành đoạn thường.';
            }
        }
        if ($disclaimers > 1) {
            $issues[] = 'Câu lưu ý «thông tin tham khảo, không thay thế bác sĩ» xuất hiện trong '.$disclaimers.' khung: giữ một khung duy nhất.';
        }

        $h2 = $xp->query('//h2')->length;
        if ($xp->query('//*['.self::hasClass('autoseo-toc').']')->length > 0 && ($words < self::TOC_MIN_WORDS || $h2 < self::TOC_MIN_H2)) {
            $issues[] = 'Mục lục chỉ dùng cho bài từ '.self::TOC_MIN_WORDS.' từ và từ '.self::TOC_MIN_H2.' đề mục h2 (bài này '.$words.' từ, '.$h2.' h2): bỏ mục lục.';
        }
        if ($xp->query('//*['.self::hasClass('autoseo-summary').']')->length > 0 && $words < self::SUMMARY_MIN_WORDS) {
            $issues[] = 'Tóm tắt nhanh chỉ dùng cho bài từ '.self::SUMMARY_MIN_WORDS.' từ (bài này '.$words.' từ): bỏ khối tóm tắt.';
        }

        foreach ($xp->query('//table') ?: [] as $t) {
            $rows = $xp->query('.//tr', $t);
            $cols = 0;
            foreach ($xp->query('(.//tr)[1]/*[self::th or self::td]', $t) ?: [] as $_) {
                $cols++;
            }
            $dataRows = max(0, $rows->length - 1);
            if ($cols < 2 || $dataRows < 2) {
                $issues[] = 'Bảng chỉ có '.$cols.' cột và '.$dataRows.' hàng dữ liệu: chỉ dùng bảng khi so sánh từ 2 đối tượng theo từ 2 tiêu chí; còn lại viết thành câu hoặc danh sách.';
            }
        }

        $n = count($issues);

        return [new RuleResult('LAY01', RuleResult::P1, $n === 0, 'Khối bố cục dùng có lý do', $n === 0
            ? 'Khối bố cục (khung lưu ý, mục lục, tóm tắt, bảng) không bị lạm dụng.'
            : $n.' chỗ dùng khối bố cục chưa hợp lý: '.implode(' ', array_slice($issues, 0, 3)),
            (string) $n, '0', $issues[0] ?? null, array_slice($issues, 0, 8))];
    }
}
