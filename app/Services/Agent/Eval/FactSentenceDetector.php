<?php

namespace App\Services\Agent\Eval;

/**
 * Q0.2: tìm các CÂU SỰ THẬT trong bài (có số kèm đơn vị, năm, dẫn cơ quan/nghiên cứu, khẳng định tác dụng y tế) và cho biết câu nào
 * CHƯA có dấu trích dẫn. Tất định, không dùng AI. Là xấp xỉ có chủ ý: dùng để ĐO tỉ lệ, không để chặn bài.
 *
 * Dấu trích dẫn: `[KB#12]` thô, hoặc `<sup data-kb="12">[1]</sup>` (bản đã lưu, CitationRenderer). Dấu đứng sau dấu chấm được tính cho câu liền trước.
 */
final class FactSentenceDetector
{
    private const MARK = "\u{27E6}KB\u{27E7}";

    private const UNITS = '(?:%|mmol|mg|µg|mcg|g|kg|ml|l|mmhg|dl|ui|đồng|đ|vnđ|triệu|nghìn|tỷ|năm|tuổi|tháng|tuần|ngày|giờ|phút|lần|người|ca|độ|°c|kcal|calo)';

    private const FACT_PATTERNS = [
        '/\d+(?:[.,]\d+)?\s*'.self::UNITS.'(?![\p{L}])/iu',
        '/\b(?:19|20)\d{2}\b/u',
        '/\b(?:theo|dẫn|căn cứ)\s+(?:who|bộ y tế|tổ chức|hiệp hội|viện|cục|nghiên cứu|khuyến cáo|hướng dẫn|iso|tcvn)/iu',
        '/nghiên cứu\s+(?:cho thấy|chỉ ra|ghi nhận|công bố)|khuyến cáo|hướng dẫn (?:của|chẩn đoán)/iu',
        '/giảm nguy cơ|tăng nguy cơ|gây ra|dẫn đến|chống chỉ định|có tác dụng|an toàn khi|hiệu quả (?:trong|đối với)/iu',
    ];

    /** Câu mang tính lưu ý chung, không phải khẳng định cần nguồn. */
    private const SKIP = '/tham khảo|không thay thế|tư vấn của bác sĩ|ý kiến (?:của )?(?:bác sĩ|chuyên gia)/iu';

    /**
     * @return array{total: int, facts: int, cited: int, uncited: list<string>, ratio: ?float}
     */
    public function analyze(string $html): array
    {
        $total = 0;
        $facts = 0;
        $cited = 0;
        $uncited = [];
        foreach ($this->blocks($html) as $block) {
            foreach (self::sentences($block) as $s) {
                $total++;
                $hasMark = str_contains($s, self::MARK);
                $plain = trim(str_replace(self::MARK, '', $s));
                if (! self::isFact($plain)) {
                    continue;
                }
                $facts++;
                if ($hasMark) {
                    $cited++;
                } else {
                    $uncited[] = $plain;
                }
            }
        }

        return ['total' => $total, 'facts' => $facts, 'cited' => $cited, 'uncited' => $uncited, 'ratio' => $facts === 0 ? null : round($cited / $facts, 4)];
    }

    public static function isFact(string $sentence): bool
    {
        $s = trim($sentence);
        if (count(preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: []) < 6 || preg_match(self::SKIP, $s)) {
            return false;
        }
        foreach (self::FACT_PATTERNS as $p) {
            if (preg_match($p, $s)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tách thành các câu; dấu trích dẫn được chuyển về TRƯỚC dấu chấm kết câu để câu nào có dấu thì mang dấu đó.
     *
     * @return list<string>
     */
    public static function sentences(string $text): array
    {
        $text = preg_replace('/([.!?…])\s*'.preg_quote(self::MARK, '/').'/u', ' '.self::MARK.'$1', $text) ?? $text;
        $parts = preg_split('/(?<=[.!?…])\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_map(fn ($p) => trim($p), $parts));
    }

    /** @return list<string> văn bản từng khối p/li/td/blockquote (trừ khối Nguồn tham khảo), dấu trích dẫn đã đổi thành MARK */
    private function blocks(string $html): array
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xp = new \DOMXPath($dom);
        $skip = "[not(ancestor-or-self::*[contains(concat(' ', normalize-space(@class), ' '), ' autoseo-refs ')])]";
        $out = [];
        foreach ($xp->query('//p'.$skip.'|//li'.$skip.'|//td'.$skip.'|//blockquote'.$skip) ?: [] as $n) {
            foreach ($xp->query('.//sup[@data-kb]', $n) ?: [] as $sup) {
                $sup->parentNode?->replaceChild($dom->createTextNode(' '.self::MARK.' '), $sup);
            }
            $t = trim(preg_replace('/\s+/u', ' ', (string) $n->textContent) ?? '');
            $t = preg_replace('/\[(?:KB)?#?\d+(?:\s*[,;]\s*(?:KB)?#?\d+)*\]/u', self::MARK, $t) ?? $t;
            if ($t !== '') {
                $out[] = $t;
            }
        }

        return $out;
    }
}
