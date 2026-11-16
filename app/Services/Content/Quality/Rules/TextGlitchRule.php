<?php

namespace App\Services\Content\Quality\Rules;

use App\Services\Content\Quality\QualityContext;
use App\Services\Content\Quality\QualityRule;
use App\Services\Content\Quality\RuleResult;

/**
 * TXT01 (P1): lỗi chữ do mô hình sinh ra: (a) chữ cái ngoài bảng chữ Latin chen vào ("mỗiσ"), (b) từ tiếng Việt dính liền từ tiếng Anh
 * ("vàqualified", "gồmmotor"). Âm tiết tiếng Việt dài tối đa 7 chữ cái, nên token chỉ gồm chữ cái, dài ≥ 8, có dấu và kết thúc bằng ≥ 4 chữ ASCII liền
 * sau phần có dấu là dính chữ. Không chặn (có thể báo nhầm tên riêng nước ngoài có dấu).
 */
class TextGlitchRule implements QualityRule
{
    private const VN_MARKED = 'àáảãạăằắẳẵặâầấẩẫậèéẻẽẹêềếểễệìíỉĩịòóỏõọôồốổỗộơờớởỡợùúủũụưừứửữựỳýỷỹỵđ';

    public function code(): string
    {
        return 'TXT01';
    }

    public function evaluate(QualityContext $ctx): array
    {
        $raw = preg_split('/[^\p{L}]+/u', $this->rawText($ctx), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $found = [];
        foreach ($raw as $tok) {
            if (preg_match('/(?![\p{Latin}])\p{L}/u', $tok)) {
                $found[$tok] = true; // chữ ngoài Latin (Hy Lạp, Kirin, Hán...) lẫn trong bài tiếng Việt

                continue;
            }
            if (mb_strlen($tok) >= 8 && preg_match('/^[a-z]*['.self::VN_MARKED.'][a-z]{0,3}[a-z]{4,}$/iu', mb_strtolower($tok)) && $this->glued($tok)) {
                $found[$tok] = true;
            }
        }
        $list = array_keys($found);
        $n = count($list);

        return [new RuleResult('TXT01', RuleResult::P1, $n === 0, 'Lỗi chữ (ký tự lạ, từ dính liền)', $n === 0
            ? 'Không thấy ký tự lạ hay từ dính liền.'
            : "{$n} chỗ có ký tự lạ hoặc từ bị dính vào nhau. Sửa lại cho đúng chính tả.",
            "{$n} chỗ", '0 chỗ', $n === 0 ? null : implode('; ', array_slice($list, 0, 6)))];
    }

    /** Phần đuôi toàn ASCII ≥ 4 chữ sau ký tự có dấu cuối cùng mà phần trước đó là một âm tiết Việt hợp lệ (≤ 7 chữ cái). */
    private function glued(string $tok): bool
    {
        $t = mb_strtolower($tok);
        $len = mb_strlen($t);
        $lastMarked = -1;
        for ($i = 0; $i < $len; $i++) {
            if (mb_strpos(self::VN_MARKED, mb_substr($t, $i, 1)) !== false) {
                $lastMarked = $i;
            }
        }
        if ($lastMarked < 0) {
            return false;
        }
        for ($cut = $lastMarked + 1; $cut <= min($len - 4, $lastMarked + 4); $cut++) {
            $head = mb_substr($t, 0, $cut);
            $tail = mb_substr($t, $cut);
            if (mb_strlen($head) <= 7 && preg_match('/^[a-z]{4,}$/', $tail) === 1) {
                return true;
            }
        }

        return false;
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
