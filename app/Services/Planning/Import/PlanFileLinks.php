<?php

namespace App\Services\Planning\Import;

/**
 * Liên kết giữa các sheet của một file (kiểu VLOOKUP): sheet chính (bài viết) có một cột chứa mã/tên, sheet tham chiếu (chiến dịch, cụm...)
 * có cột khóa trùng mã/tên đó và các cột cần lấy kèm. AI chỉ ĐỀ XUẤT liên kết; mọi thứ ở đây được kiểm lại: sheet phải có thật và khác sheet chính,
 * cột nằm trong phạm vi, và chỉ lấy được các trường trong LOOKUP_FIELDS (không bao giờ lấy từ khóa chính từ sheet khác).
 */
final class PlanFileLinks
{
    public const MAX = 3;

    /** Trường lấy được từ sheet khác. KHÔNG có primary_keyword: từ khóa chính quyết định dòng nào là một bài, chỉ lấy từ sheet chính. */
    public const LOOKUP_FIELDS = ['campaign', 'campaign_description', 'campaign_start', 'campaign_end', 'topic_cluster', 'guidance', 'planned_date', 'priority', 'search_intent', 'title', 'secondary_keywords'];

    /** Nối theo mã/tên của chiến dịch hay cụm: mã không tìm thấy là bất thường nên được báo (khác với nối ngày/tiêu đề, nơi chỉ một phần bài có dòng tương ứng). */
    public const STRICT_FIELDS = ['campaign', 'campaign_description', 'campaign_start', 'campaign_end', 'topic_cluster'];

    /**
     * Làm sạch danh sách liên kết thô (từ AI hoặc từ trạng thái trình duyệt).
     *
     * @param  list<array{name:string, rows:list<list<string>>}>  $sheets
     * @return list<array{from_col:int, sheet:string, header_row:int, key_col:int, fields:array<int,string>}>
     */
    public static function normalize(array $sheets, string $mainSheet, array $raw): array
    {
        $by = [];
        foreach ($sheets as $s) {
            $by[$s['name']] = $s;
        }
        $main = $by[$mainSheet] ?? null;
        if ($main === null) {
            return [];
        }
        $out = [];
        foreach ($raw as $l) {
            if (! is_array($l) || count($out) >= self::MAX) {
                continue;
            }
            $name = (string) ($l['sheet'] ?? '');
            $ref = $by[$name] ?? null;
            $from = PlanFileFields::colIndex($l['from_col'] ?? null);
            $key = PlanFileFields::colIndex($l['key_col'] ?? null);
            if ($ref === null || $name === $mainSheet || $from === null || $key === null || $from >= self::width($main) || $key >= self::width($ref)) {
                continue;
            }
            $fields = [];
            foreach ((array) ($l['fields'] ?? []) as $k => $f) {
                // dạng của AI: [{"col":"B","field":"campaign"}]; dạng đã lưu: {2: "campaign"}
                [$col, $field] = is_array($f) ? [PlanFileFields::colIndex($f['col'] ?? null), (string) ($f['field'] ?? '')] : [PlanFileFields::colIndex($k), (string) $f];
                if ($col !== null && $col < self::width($ref) && in_array($field, self::LOOKUP_FIELDS, true)) {
                    $fields[$col] = $field;
                }
            }
            if ($fields !== []) {
                $out[] = ['from_col' => $from, 'sheet' => $name, 'header_row' => max(0, min((int) ($l['header_row'] ?? 1), min(count($ref['rows']) - 1, 50))), 'key_col' => $key, 'fields' => $fields];
            }
        }

        return $out;
    }

    /**
     * Dạng importer dùng: thêm các dòng của sheet tham chiếu.
     *
     * @return list<array{sheet:string, from:int, key:int, header:int, fields:array<int,string>, rows:list<list<string>>}>
     */
    public static function resolve(array $sheets, string $mainSheet, array $raw): array
    {
        $by = [];
        foreach ($sheets as $s) {
            $by[$s['name']] = $s['rows'];
        }

        return array_map(fn ($l) => ['sheet' => $l['sheet'], 'from' => $l['from_col'], 'key' => $l['key_col'], 'header' => $l['header_row'], 'fields' => $l['fields'], 'rows' => $by[$l['sheet']]], self::normalize($sheets, $mainSheet, $raw));
    }

    public static function key(mixed $v): string
    {
        return mb_strtolower(PlanFileFields::text((string) $v, 200));
    }

    private static function width(array $sheet): int
    {
        $w = 0;
        foreach (array_slice($sheet['rows'], 0, 200) as $r) {
            $w = max($w, count($r));
        }

        return min($w, SheetReader::MAX_COLS);
    }
}
