<?php

namespace App\Services\Content\Quality\Rules;

use App\Services\Content\Quality\QualityContext;
use App\Services\Content\Quality\QualityRule;
use App\Services\Content\Quality\RuleResult;

/**
 * INT01 (P1, không chặn): bài thương mại/so sánh phải CỤ THỂ.
 * - Từ khóa "báo giá/bảng giá/giá …": phải có ít nhất một mức giá (số kèm đơn vị tiền) hoặc nói rõ cách nhận báo giá.
 * - Từ khóa "so sánh/vs/so với/top…": phải có bảng (≥ 3 hàng) hoặc nêu tên ≥ 3 đối tượng cụ thể (tên riêng, thương hiệu, tiêu chuẩn viết hoa).
 * Heuristic để gắn cờ "thiếu cụ thể", không phán đúng sai của số liệu (số có thật hay không là việc của người duyệt).
 * Phát hiện qua từ khóa của bài; bài ý định khác không bị xét.
 */
class IntentSpecificityRule implements QualityRule
{
    public function code(): string
    {
        return 'INT01';
    }

    public function evaluate(QualityContext $ctx): array
    {
        $kw = $ctx->keyword();
        $price = (bool) preg_match('/\b(báo giá|bảng giá|giá (?:bán|máy|thuê)|chi phí)\b/u', $kw) || str_starts_with($kw, 'giá ');
        $compare = (bool) preg_match('/\b(so sánh|so với|vs|top \d+|tốt nhất|nên chọn)\b/u', $kw);
        if (! $price && ! $compare) {
            return [new RuleResult('INT01', RuleResult::P1, true, 'Độ cụ thể theo ý định', 'Bài không thuộc nhóm báo giá/so sánh nên không xét.')];
        }

        $missing = [];
        if ($price && ! $this->hasPrice($ctx)) {
            $missing[] = 'chưa có mức giá nào (hoặc khoảng giá kèm đơn vị)';
        }
        if ($compare && ! $this->hasComparable($ctx)) {
            $missing[] = 'chưa có bảng so sánh thật hoặc tên cụ thể của các đối tượng được so sánh';
        }
        $ok = $missing === [];

        return [new RuleResult('INT01', RuleResult::P1, $ok, 'Độ cụ thể theo ý định', $ok
            ? 'Bài có số liệu/đối tượng cụ thể đúng ý định tìm kiếm.'
            : 'Thiếu cụ thể: '.implode('; ', $missing).'. Bổ sung số liệu có nguồn hoặc nói rõ cách nhận báo giá; không bịa số.',
            $ok ? 'đủ' : 'thiếu cụ thể', 'có giá / có bảng hoặc tên cụ thể', $ok ? null : implode('; ', $missing))];
    }

    private function hasPrice(QualityContext $ctx): bool
    {
        $t = $this->rawText($ctx);

        return (bool) preg_match('/\d[\d.,]*\s*(?:triệu|tr\b|nghìn|ngàn|k\b|tỷ|đ\b|₫|vnđ|vnd|usd|\$|đồng)/iu', $t)
            || (bool) preg_match('/(?:₫|\$)\s*\d/u', $t);
    }

    private function hasComparable(QualityContext $ctx): bool
    {
        foreach ($ctx->xpath()->query('//table') ?: [] as $tbl) {
            if ($ctx->xpath()->query('.//tr', $tbl)->length >= 3) {
                return true;
            }
        }
        $names = [];
        foreach ($ctx->xpath()->query('//p|//li|//td|//th') ?: [] as $n) {
            $text = trim((string) $n->textContent);
            // Bỏ chữ đầu câu (viết hoa vì đầu câu): chỉ lấy token viết hoa giữa câu.
            $parts = preg_split('/(?<=[.!?])\s+|\n/u', $text) ?: [];
            foreach ($parts as $sentence) {
                $tokens = preg_split('/\s+/u', trim($sentence), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                foreach (array_slice($tokens, 1) as $tok) {
                    $tok = trim($tok, " \t\n\r\0\x0B.,;:()\"'“”«»[]");
                    if (mb_strlen($tok) < 3) {
                        continue;
                    }
                    // Thương hiệu/tiêu chuẩn: HubSpot, Mailchimp, Zoho, GMP-WHO, EU-GMP, PIC/S... (không tính từ tiếng Việt viết hoa thường gặp ở tiêu đề).
                    if (preg_match('/^[A-Z][a-z]+[A-Z][A-Za-z0-9]*$/u', $tok) || preg_match('/^[A-Z]{2,}(?:[-\/][A-Z0-9]{1,})+$/u', $tok) || preg_match('/^[A-Z][a-z]{2,}$/u', $tok)) {
                        $names[mb_strtolower($tok)] = true;
                    }
                }
            }
        }

        return count($names) >= 3;
    }

    private function rawText(QualityContext $ctx): string
    {
        $parts = [];
        foreach ($ctx->xpath()->query('//text()[not(ancestor::script) and not(ancestor::style)]') ?: [] as $n) {
            $parts[] = $n->nodeValue;
        }

        return implode(' ', $parts);
    }
}
