<?php

namespace App\Filament\Support\Keywords;

/**
 * Phân tích THẬT tệp CSV từ khóa. Cột bắt buộc `keyword`, tùy chọn `volume`, `intent`. Chấp nhận dấu , hoặc ;
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
        return trim((string) preg_replace('/\s+/u', ' ', $k));
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
        $out = ['rows' => [], 'errors' => [], 'duplicates' => [], 'fatal' => null];
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
        $delim = substr_count($headerLine, ';') > substr_count($headerLine, ',') ? ';' : ',';
        $header = array_map(fn ($h) => mb_strtolower(trim((string) $h)), str_getcsv($headerLine, $delim));
        $col = array_flip($header);
        if (! isset($col['keyword'])) {
            $out['fatal'] = 'Thiếu cột bắt buộc "keyword" ở dòng tiêu đề.';

            return $out;
        }

        $dataLines = array_slice($lines, $headerIdx + 1, null, true);
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
            $cells = str_getcsv($raw, $delim);
            $kw = self::normalizeKeyword((string) ($cells[$col['keyword']] ?? ''));
            if ($kw === '') {
                $out['errors'][] = ['line' => $line, 'reason' => 'Thiếu từ khóa.'];

                continue;
            }
            if (mb_strlen($kw) > self::MAX_KEYWORD_LENGTH) {
                $out['errors'][] = ['line' => $line, 'reason' => 'Từ khóa dài quá '.self::MAX_KEYWORD_LENGTH.' ký tự.'];

                continue;
            }
            $volume = null;
            if (isset($col['volume'])) {
                $v = trim((string) ($cells[$col['volume']] ?? ''));
                if ($v !== '') {
                    $volume = self::parseVolume($v);
                    if ($volume === null) {
                        $out['errors'][] = ['line' => $line, 'reason' => 'Volume "'.$v.'" không phải số nguyên không âm.'];

                        continue;
                    }
                }
            }
            $intent = KeywordIntent::DEFAULT;
            if (isset($col['intent'])) {
                $iv = trim((string) ($cells[$col['intent']] ?? ''));
                if ($iv !== '') {
                    $intent = KeywordIntent::normalize($iv);
                    if ($intent === null) {
                        $out['errors'][] = ['line' => $line, 'reason' => 'Ý định "'.$iv.'" không hợp lệ (chấp nhận: informational, commercial, transactional, navigational hoặc nhãn tiếng Việt).'];

                        continue;
                    }
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

    public static function parseVolume(string $v): ?int
    {
        $v = str_replace(' ', '', $v);
        if (preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) {
            $v = str_replace('.', '', $v);
        }

        return preg_match('/^\d{1,9}$/', $v) ? (int) $v : null;
    }
}
