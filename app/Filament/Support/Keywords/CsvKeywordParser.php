<?php

namespace App\Filament\Support\Keywords;

/**
 * Phân tích THẬT tệp CSV từ khóa. Tiêu đề Anh/Việt (keyword/từ khóa, volume/lượt tìm, intent/ý định); không có tiêu đề thì mỗi dòng là một từ khóa. Chấp nhận dấu , ; hoặc tab
 * và UTF-8 có BOM. Tối đa 5.000 dòng dữ liệu và 1 MB. Không bỏ im lặng: dòng sai được báo kèm số dòng + lý do.
 *
 * @phpstan-type Row array{line:int, keyword:string, volume:?int, intent:string}
 */
final class CsvKeywordParser
{
    public const MAX_ROWS = 5000;

    public const MAX_BYTES = 1048576;

    public const MAX_KEYWORD_LENGTH = 255;

    public static function normalizeKeyword(string $k): string
    {
        return KeywordSanitizer::check($k)['keyword'] ?? trim((string) preg_replace('/\s+/u', ' ', strip_tags($k)));
    }

    public static function key(string $k): string
    {
        return mb_strtolower(self::normalizeKeyword($k));
    }

    /**
     * @param  list<string>  $existing  từ khóa đã có trong kho (để phát hiện trùng)
     * @return array{rows: list<array>, errors: list<array{line:int, reason:string}>, duplicates: list<array{line:int, keyword:string, reason:string}>, fatal: ?string}
     */
    public function parse(string $content, array $existing = []): array
    {
        $out = ['rows' => [], 'errors' => [], 'duplicates' => [], 'fatal' => null, 'note' => null];
        if (strlen($content) > self::MAX_BYTES) {
            $out['fatal'] = 'Tệp vượt quá 1 MB. Hãy tách thành nhiều tệp nhỏ hơn.';

            return $out;
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        if (trim($content) === '') {
            $out['fatal'] = 'Tệp rỗng.';

            return $out;
        }
        if (! mb_check_encoding($content, 'UTF-8')) {
            $out['fatal'] = 'Tệp không phải UTF-8. Hãy lưu lại bằng UTF-8 để giữ đủ dấu tiếng Việt.';

            return $out;
        }

        $lines = explode("\n", $content);
        $headerIdx = null;
        foreach ($lines as $i => $l) {
            if (trim($l) !== '') {
                $headerIdx = $i;
                break;
            }
        }
        $headerLine = $lines[$headerIdx];
        $delim = self::detectDelimiter($headerLine);
        $col = self::headerColumns(array_map(fn ($h) => trim((string) $h), str_getcsv($headerLine, $delim)));
        if ($col !== [] && ! isset($col['keyword'])) {
            $out['fatal'] = 'Dòng tiêu đề chưa có cột từ khóa. Hãy đặt tên một cột là "từ khóa" (hoặc keyword), hoặc bỏ dòng tiêu đề để mỗi dòng là một từ khóa.';

            return $out;
        }
        $hasHeader = isset($col['keyword']);
        if ($hasHeader) {
            $dataLines = array_slice($lines, $headerIdx + 1, null, true);
            $out['note'] = 'Đã hiểu dòng đầu là tiêu đề cột: '.implode(', ', array_map(fn ($k) => ['keyword' => 'từ khóa', 'volume' => 'lượt tìm', 'intent' => 'ý định'][$k].' = cột '.($col[$k] + 1), array_keys($col))).'.';
        } else {
            // Không nhận ra tên cột nào: coi cả tệp là danh sách từ khóa (mỗi dòng một từ khóa, có thể kèm lượt tìm ở cột sau).
            $dataLines = array_slice($lines, $headerIdx, null, true);
            $out['note'] = 'Tệp không có dòng tiêu đề nhận ra được, nên đã hiểu mỗi dòng là một từ khóa (nếu dòng có thêm một số ở cột sau thì đó là lượt tìm). Tiêu đề được nhận: từ khóa, keyword, lượt tìm, volume, ý định.';
        }
        $nonBlank = count(array_filter($dataLines, fn ($l) => trim($l) !== ''));
        if ($nonBlank > self::MAX_ROWS) {
            $out['fatal'] = 'Tệp có '.number_format($nonBlank, 0, ',', '.').' dòng, vượt giới hạn '.number_format(self::MAX_ROWS, 0, ',', '.').' dòng mỗi lần nhập.';

            return $out;
        }

        $seen = [];
        foreach ($existing as $e) {
            $seen[self::key($e)] = true;
        }
        foreach ($dataLines as $i => $raw) {
            if (trim($raw) === '') {
                continue;
            }
            $line = $i + 1;
            if ($hasHeader) {
                $cells = str_getcsv($raw, $delim);
                $kwRaw = (string) ($cells[$col['keyword']] ?? '');
                $volRaw = isset($col['volume']) ? trim((string) ($cells[$col['volume']] ?? '')) : '';
                $intRaw = isset($col['intent']) ? trim((string) ($cells[$col['intent']] ?? '')) : '';
            } else {
                [$kwRaw, $volRaw] = self::splitHeaderless($raw);
                $intRaw = '';
            }
            $chk = KeywordSanitizer::check($kwRaw);
            if ($chk['keyword'] === null) {
                $out['errors'][] = ['line' => $line, 'reason' => $chk['reason']];

                continue;
            }
            $kw = $chk['keyword'];
            $volume = null;
            if ($volRaw !== '') {
                $volume = self::parseVolume($volRaw);
                if ($volume === null) {
                    $out['errors'][] = ['line' => $line, 'reason' => 'Lượt tìm "'.$volRaw.'" không phải số nguyên không âm.'];

                    continue;
                }
            }
            $intent = KeywordIntent::DEFAULT;
            if ($intRaw !== '') {
                $intent = KeywordIntent::normalize($intRaw);
                if ($intent === null) {
                    $out['errors'][] = ['line' => $line, 'reason' => 'Ý định "'.$intRaw.'" không hợp lệ (chấp nhận: informational, commercial, transactional, navigational hoặc nhãn tiếng Việt).'];

                    continue;
                }
            }
            $k = self::key($kw);
            if (isset($seen[$k])) {
                $out['duplicates'][] = ['line' => $line, 'keyword' => $kw, 'reason' => 'Đã có trong tệp hoặc trong kho từ khóa.'];

                continue;
            }
            $seen[$k] = true;
            $out['rows'][] = ['line' => $line, 'keyword' => $kw, 'volume' => $volume, 'intent' => $intent];
        }

        return $out;
    }

    private const HEADER_ALIASES = [
        'keyword' => ['keyword', 'keywords', 'kw', 'query', 'từ khóa', 'từ khoá', 'tu khoa', 'tukhoa', 'truy vấn', 'cụm từ khóa'],
        'volume' => ['volume', 'search volume', 'searchvolume', 'vol', 'lượt tìm', 'lượt tìm kiếm', 'luot tim', 'lượng tìm kiếm', 'lượng tìm', 'tìm kiếm hàng tháng', 'monthly searches'],
        'intent' => ['intent', 'search intent', 'ý định', 'y dinh', 'ý định tìm kiếm', 'ý định người dùng'],
    ];

    /** Dấu phân cách theo dòng đầu: tab, ; hoặc , (nhiều nhất thắng; hòa thì ,). */
    private static function detectDelimiter(string $line): string
    {
        $best = ',';
        $max = substr_count($line, ',');
        foreach (["\t", ';'] as $d) {
            if (substr_count($line, $d) > $max) {
                $best = $d;
                $max = substr_count($line, $d);
            }
        }

        return $best;
    }

    /** @param list<string> $header @return array<string,int> khóa chuẩn => chỉ số cột (chỉ gồm cột nhận ra) */
    private static function headerColumns(array $header): array
    {
        $col = [];
        foreach ($header as $i => $h) {
            $h = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $h)));
            foreach (self::HEADER_ALIASES as $canon => $aliases) {
                if (! isset($col[$canon]) && in_array($h, $aliases, true)) {
                    $col[$canon] = $i;
                }
            }
        }

        return $col;
    }

    /** Dòng không tiêu đề: "từ khóa<phân cách>lượt tìm" khi cột sau là số, ngược lại cả dòng là từ khóa. @return array{0:string,1:string} */
    private static function splitHeaderless(string $raw): array
    {
        foreach (["\t", ';', ','] as $d) {
            $p = strrpos($raw, $d);
            if ($p === false) {
                continue;
            }
            $tail = trim(substr($raw, $p + 1));
            if ($tail !== '' && self::parseVolume($tail) !== null) {
                return [trim(trim(substr($raw, 0, $p)), '"'), $tail];
            }
        }

        return [trim($raw), ''];
    }

    /** Dùng chung cách đọc số của nhập từ file Kế hoạch: "1.200" kiểu Việt = 1200, "12k", "1,5 nghìn". */
    public static function parseVolume(string $v): ?int
    {
        return \App\Services\Planning\Import\PlanFileFields::volume($v);
    }
}
