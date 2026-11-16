<?php

namespace App\Services\Content\Quality\Rules;

use App\Models\KnowledgeItem;
use App\Services\Content\Quality\QualityContext;
use App\Services\Content\Quality\QualityRule;
use App\Services\Content\Quality\RuleResult;
use App\Services\Knowledge\KnowledgeDedupe;
use App\Services\Knowledge\KnowledgeRetriever;

/**
 * AG-S21 KB01 "Số liệu có nguồn trong kho" (P1, không bao giờ P0). Tất định, KHÔNG dùng AI.
 * Với mỗi mục kiến thức ĐÃ DUYỆT, còn hiệu lực của website có thực thể + trường + giá trị: tìm câu trong bài nhắc cả thực thể lẫn trường,
 * lấy các số liệu CÙNG LOẠI (tiền / % / năm / thời gian) trong câu đó rồi so với giá trị của mục.
 *  - Có số khớp → đạt. Chỉ có số khác → cờ "có thể sai số liệu" kèm [KB#id] và giá trị đúng (P1, chưa đạt).
 *  - Số liệu không câu nào đối chiếu được → chỉ ghi nhận "chưa kiểm chứng được" (mức thông tin, đạt, không phạt).
 */
class KnowledgeFigureRule implements QualityRule
{
    /** số + đơn vị. Nhóm 1 = số, nhóm 2 = đơn vị. */
    private const FIGURE = '/(?<![\d.,])(\d+(?:[.,]\d+)*)\s?(%|phần trăm|triệu|tỷ|nghìn|ngàn|đồng|vnđ|vnd|usd|đ(?![\p{L}])|\$|ngày|tuần|tháng|năm|giờ|phút)/iu';

    private const YEAR = '/(?<![\d.,])((?:19|20)\d{2})(?![\d.,]*\d)/u';

    private const MULT = ['nghìn' => 1e3, 'ngàn' => 1e3, 'triệu' => 1e6, 'tỷ' => 1e9];

    private const MONEY = ['triệu', 'tỷ', 'nghìn', 'ngàn', 'đồng', 'vnđ', 'vnd', 'đ', 'usd', '$', 'k'];

    private const TIME = ['ngày', 'tuần', 'tháng', 'năm', 'giờ', 'phút'];

    public function code(): string
    {
        return 'KB01';
    }

    public function evaluate(QualityContext $ctx): array
    {
        $label = 'Số liệu có nguồn trong kho';
        $site = $ctx->article?->website;
        if (! $site) {
            return [new RuleResult('KB01', RuleResult::P1, true, $label, 'Bài chưa gắn website nên không đối chiếu với kho kiến thức.')];
        }
        $atoms = [];
        foreach ((new KnowledgeRetriever())->eligible($site)->whereNotNull('entity')->whereNotNull('field')->whereNotNull('value')->limit(500)->get() as $a) {
            $f = self::parse(trim($a->value.' '.$a->value_unit));
            $e = KnowledgeDedupe::normalize((string) $a->entity);
            $fl = KnowledgeDedupe::normalize((string) $a->field);
            if ($f && $e !== '' && $fl !== '') {
                $atoms[] = ['item' => $a, 'fig' => $f, 'entity' => $e, 'field' => $fl];
            }
        }
        $sentences = preg_split('/(?<=[.!?])\s+/u', $ctx->text()) ?: [];
        $all = [];
        foreach ($sentences as $i => $s) {
            foreach (self::figures($s) as $f) {
                $all[$i.'|'.$f['raw']] = ['s' => $i] + $f;
            }
        }
        if ($atoms === []) {
            return [new RuleResult('KB01', RuleResult::P1, true, $label, 'Website chưa có mục kiến thức đã duyệt kèm thực thể/giá trị để đối chiếu.', '0 mục đối chiếu', null)];
        }

        $out = [];
        $ok = [];
        $covered = [];
        foreach ($atoms as $a) {
            foreach ($sentences as $i => $s) {
                $norm = KnowledgeDedupe::normalize($s);
                if (! str_contains($norm, $a['entity']) || ! str_contains($norm, $a['field'])) {
                    continue;
                }
                $same = array_values(array_filter(array_values($all), fn ($f) => $f['s'] === $i && $f['kind'] === $a['fig']['kind']));
                if ($same === []) {
                    continue;
                }
                foreach ($same as $f) {
                    $covered[$i.'|'.$f['raw']] = true;
                }
                $match = array_filter($same, fn ($f) => abs($f['num'] - $a['fig']['num']) <= max(1e-9, abs($a['fig']['num']) * 1e-9));
                $id = $a['item']->id;
                if ($match) {
                    $ok[$id] = true;
                } elseif (! isset($ok[$id])) {
                    $bad = $same[0];
                    $out[] = new RuleResult('KB01', RuleResult::P1, false, 'Có thể sai số liệu so với kho kiến thức',
                        sprintf('Bài ghi "%s" cho "%s — %s", nhưng kho [KB#%d] ghi "%s". Kiểm tra lại số liệu hoặc cập nhật kho nếu số mới đúng.', $bad['raw'], $a['item']->entity, $a['item']->field, $id, trim($a['item']->value.' '.$a['item']->value_unit)),
                        $bad['raw'], trim($a['item']->value.' '.$a['item']->value_unit).' [KB#'.$id.']', QualityContext::excerpt($s));
                }
            }
        }
        // một mục đã có câu khớp thì bỏ cờ sai của chính mục đó (bài nhắc đúng ở chỗ khác)
        $out = array_values(array_filter($out, fn (RuleResult $r) => ! preg_match('/\[KB#(\d+)\]$/', (string) $r->threshold, $m) || ! isset($ok[(int) $m[1]])));
        if ($ok) {
            $ids = array_keys($ok);
            sort($ids);
            $out[] = new RuleResult('KB01', RuleResult::P1, true, $label, count($ids).' số liệu khớp mục kiến thức đã duyệt ('.implode(', ', array_map(fn ($i) => "[KB#$i]", array_slice($ids, 0, 8))).').', count($ids).' khớp');
        }
        $unverified = count($all) - count($covered);
        $total = count($all);
        if ($total > 0 && $unverified > 0) {
            $out[] = new RuleResult('KB01', RuleResult::P2, true, 'Số liệu chưa kiểm chứng được với kho',
                "{$unverified}/{$total} số liệu trong bài chưa có mục kiến thức nào để đối chiếu (chỉ ghi nhận, không phạt).", "{$unverified} chưa đối chiếu", null);
        }
        if ($out === []) {
            $out[] = new RuleResult('KB01', RuleResult::P1, true, $label, 'Bài không có số liệu thuộc thực thể/trường nào trong kho để đối chiếu.', '0 số liệu đối chiếu');
        }

        return $out;
    }

    /** @return list<array{raw:string,kind:string,num:float}> */
    private static function figures(string $s): array
    {
        $out = [];
        $years = [];
        if (preg_match_all(self::FIGURE, $s, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $f = self::parse($x[0]);
                if ($f) {
                    $out[] = ['raw' => trim($x[0])] + $f;
                }
            }
        }
        if (preg_match_all(self::YEAR, $s, $m)) {
            foreach ($m[1] as $y) {
                $years[] = ['raw' => $y, 'kind' => 'year', 'num' => (float) $y];
            }
        }

        return array_merge($out, $years);
    }

    /** "8.000.000 đ" / "8 triệu" / "20%" / "2026" / "7 ngày" → loại + số. @return array{kind:string,num:float}|null */
    public static function parse(string $raw): ?array
    {
        $raw = trim(mb_strtolower($raw));
        if (! preg_match('/^(\d+(?:[.,]\d+)*)\s*(.*)$/u', $raw, $m)) {
            return null;
        }
        $num = self::number($m[1]);
        $unit = trim($m[2]);
        if ($num === null) {
            return null;
        }
        if ($unit === '' && preg_match('/^(19|20)\d{2}$/', $m[1])) {
            return ['kind' => 'year', 'num' => $num];
        }
        if ($unit === '%' || str_starts_with($unit, 'phần trăm')) {
            return ['kind' => 'percent', 'num' => $num];
        }
        $first = preg_split('/\s+/u', $unit)[0] ?? '';
        if (in_array($first, self::TIME, true)) {
            return ['kind' => 'time:'.$first, 'num' => $num];
        }
        if (in_array($first, self::MONEY, true) || in_array($unit, self::MONEY, true)) {
            return ['kind' => 'money', 'num' => $num * (self::MULT[$first] ?? 1)];
        }

        return null;
    }

    private static function number(string $s): ?float
    {
        if (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) {
            return (float) str_replace('.', '', $s);
        }
        if (preg_match('/^\d{1,3}(,\d{3})+$/', $s)) {
            return (float) str_replace(',', '', $s);
        }
        if (str_contains($s, '.') && str_contains($s, ',')) { // dấu cuối là thập phân
            $dec = strrpos($s, '.') > strrpos($s, ',') ? '.' : ',';
            $int = str_replace($dec === '.' ? ',' : '.', '', $s);

            return (float) str_replace($dec, '.', $int);
        }

        return is_numeric(str_replace(',', '.', $s)) ? (float) str_replace(',', '.', $s) : null;
    }
}
