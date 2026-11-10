<?php

namespace App\Filament\Support\Competitors;

use App\Filament\Support\Keywords\CsvKeywordParser;
use InvalidArgumentException;

/**
 * Đọc THẬT tệp CSV khoảng trống. Cột bắt buộc `keyword`; tùy chọn `competitor_domain`, `competitor_rank`, `our_rank`,
 * `volume`. Giới hạn như CsvKeywordParser (5.000 dòng, 1 MB, UTF-8, dấu , hoặc ;). Tên miền chỉ nhận qua
 * CompetitorDomain và phải là đối thủ đã được thêm (không tự tạo đối thủ ngầm). Dòng sai/trùng được báo kèm số dòng.
 */
final class CsvGapParser
{
    public const MAX_RANK = 1000;

    /**
     * @param  array<string,int>  $competitors  tên miền chuẩn hóa => id đối thủ của website
     * @param  array<string,bool>  $existing  khóa "competitor_id|keyword_key" đã có trong kho
     * @return array{rows: list<array{line:int, keyword:string, competitor_id:?int, competitor_rank:?int, our_rank:?int, volume:?int}>, errors: list<array{line:int, reason:string}>, duplicates: list<array{line:int, keyword:string, reason:string}>, fatal: ?string}
     */
    public function parse(string $content, array $competitors, array $existing = [], ?string $ownBaseUrl = null): array
    {
        $out = ['rows' => [], 'errors' => [], 'duplicates' => [], 'fatal' => null];
        if (strlen($content) > CsvKeywordParser::MAX_BYTES) {
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
        $col = array_flip(array_map(fn ($h) => mb_strtolower(trim((string) $h)), str_getcsv($headerLine, $delim)));
        if (! isset($col['keyword'])) {
            $out['fatal'] = 'Thiếu cột bắt buộc "keyword" ở dòng tiêu đề.';

            return $out;
        }
        $dataLines = array_slice($lines, $headerIdx + 1, null, true);
        $nonBlank = count(array_filter($dataLines, fn ($l) => trim($l) !== ''));
        if ($nonBlank > CsvKeywordParser::MAX_ROWS) {
            $out['fatal'] = 'Tệp có '.number_format($nonBlank, 0, ',', '.').' dòng, vượt giới hạn '.number_format(CsvKeywordParser::MAX_ROWS, 0, ',', '.').' dòng mỗi lần nhập.';

            return $out;
        }

        $seen = $existing;
        foreach ($dataLines as $i => $raw) {
            if (trim($raw) === '') {
                continue;
            }
            $line = $i + 1;
            $cells = str_getcsv($raw, $delim);
            $cell = fn (string $name): string => isset($col[$name]) ? trim((string) ($cells[$col[$name]] ?? '')) : '';

            $kw = CsvKeywordParser::normalizeKeyword($cell('keyword'));
            if ($kw === '') {
                $out['errors'][] = ['line' => $line, 'reason' => 'Thiếu từ khóa.'];

                continue;
            }
            if (mb_strlen($kw) > CsvKeywordParser::MAX_KEYWORD_LENGTH) {
                $out['errors'][] = ['line' => $line, 'reason' => 'Từ khóa dài quá '.CsvKeywordParser::MAX_KEYWORD_LENGTH.' ký tự.'];

                continue;
            }
            $competitorId = null;
            if (($dom = $cell('competitor_domain')) !== '') {
                try {
                    $norm = CompetitorDomain::validate($dom, [], $ownBaseUrl);
                } catch (InvalidArgumentException $e) {
                    $out['errors'][] = ['line' => $line, 'reason' => 'Tên miền đối thủ "'.mb_substr($dom, 0, 60).'": '.$e->getMessage()];

                    continue;
                }
                if (! isset($competitors[$norm])) {
                    $out['errors'][] = ['line' => $line, 'reason' => "Đối thủ $norm chưa được thêm; hãy thêm ở tab Đối thủ trước."];

                    continue;
                }
                $competitorId = $competitors[$norm];
            }
            $nums = [];
            foreach (['competitor_rank', 'our_rank'] as $name) {
                $nums[$name] = null;
                if (($v = $cell($name)) !== '') {
                    if (! preg_match('/^\d{1,4}$/', $v) || (int) $v < 1 || (int) $v > self::MAX_RANK) {
                        $out['errors'][] = ['line' => $line, 'reason' => 'Thứ hạng "'.$v.'" phải là số nguyên từ 1 đến '.self::MAX_RANK.'.'];

                        continue 2;
                    }
                    $nums[$name] = (int) $v;
                }
            }
            $volume = null;
            if (($v = $cell('volume')) !== '') {
                $volume = CsvKeywordParser::parseVolume($v);
                if ($volume === null) {
                    $out['errors'][] = ['line' => $line, 'reason' => 'Volume "'.$v.'" không phải số nguyên không âm.'];

                    continue;
                }
            }
            $k = ($competitorId ?? 0).'|'.CsvKeywordParser::key($kw);
            if (isset($seen[$k])) {
                $out['duplicates'][] = ['line' => $line, 'keyword' => $kw, 'reason' => 'Đã có trong tệp hoặc trong kho khoảng trống (cùng đối thủ).'];

                continue;
            }
            $seen[$k] = true;
            $out['rows'][] = ['line' => $line, 'keyword' => $kw, 'competitor_id' => $competitorId, 'competitor_rank' => $nums['competitor_rank'], 'our_rank' => $nums['our_rank'], 'volume' => $volume];
        }

        return $out;
    }
}
