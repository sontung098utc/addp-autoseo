<?php

namespace App\Services\Agent\Refresh;

use App\Services\Content\Quality\QualityReport;
use App\Services\Content\Quality\RuleResult;

/**
 * Phân loại phạm vi sửa khi làm mới bài (thuần luật, 0 token, tất định).
 *
 * BẢNG ÁNH XẠ (chưa hiệu chỉnh trên dữ liệu thật; chỗ không chắc → REWRITE cho an toàn):
 *
 *  Luật chấm (RuleResult, chỉ xét mục CHƯA ĐẠT):
 *   META01 (tiêu đề SEO, meta description, slug)  → META (chỉ đổi trường, không đụng thân bài)
 *   KW01 mục "Từ khóa trong tiêu đề SEO"          → META
 *   IMG01  ảnh thiếu alt                          → SECTIONS nếu định vị được mọi ảnh qua src trong excerpt;
 *                                                   ảnh đại diện (không nằm trong HTML) hoặc không định vị được → REWRITE
 *   LINK01 liên kết lạ                            → SECTIONS nếu tìm được từng href trong mục; còn lại → REWRITE
 *   KB01   sai số liệu so với kho                 → SECTIONS nếu tìm được câu (excerpt) trong một mục; còn lại → REWRITE
 *   LEN01, HEAD01, DUP01, SAFE01, BAN01, KW01 (các mục khác), FACT01, FAQ01, EVID01, SRC01,
 *   mã lạ chưa biết                               → REWRITE (toàn bài/cấu trúc, lan nhiều chỗ, hoặc không đủ thông tin định vị)
 *
 *  Mã lỗi audit (IssueCatalog):
 *   missing_title, title_long, title_short, duplicate_title, missing_meta, duplicate_meta → META
 *   image_no_alt  → SECTIONS nếu có $html (tìm mục có <img> thiếu alt); không có $html → REWRITE
 *   broken_link, thin_page, missing_h1, multiple_h1, noindex_in_sitemap, missing_canonical, mã lạ → REWRITE
 *   (noindex/canonical là cấu hình kỹ thuật, không phải nội dung; để REWRITE vì chưa chắc, cần người xem.)
 *
 * Luật chọn: có REWRITE → rewrite; còn lại có SECTIONS → sections (gộp META thì ghi cần cả meta);
 * chỉ META → meta; không lỗi → none. Mục cần sửa > 50% tổng số mục → rewrite.
 */
final class RefreshScope
{
    public const NONE = 'none';

    public const META = 'meta';

    public const SECTIONS = 'sections';

    public const REWRITE = 'rewrite';

    private const MAX_SECTION_RATIO = 0.5;

    private const AUDIT_META = ['missing_title', 'title_long', 'title_short', 'duplicate_title', 'missing_meta', 'duplicate_meta'];

    /**
     * @param  list<string>  $auditIssueCodes  mã lỗi của SeoAudit (IssueCatalog)
     * @return array{scope:string, reasons:list<string>, section_indexes:list<int>}
     */
    public static function classify(QualityReport $report, array $auditIssueCodes = [], ?string $html = null): array
    {
        $parts = $html !== null ? HtmlSections::split($html)['sections'] : null;
        $rewrite = [];
        $meta = [];
        $sections = []; // index => list<mã>
        $sectionCodes = [];

        foreach ($report->failed() as $r) {
            [$kind, $idx] = self::ruleKind($r, $parts);
            self::bucket($kind, $idx, $r->code, $rewrite, $meta, $sections, $sectionCodes);
        }
        foreach (array_values(array_unique($auditIssueCodes)) as $code) {
            [$kind, $idx] = self::auditKind($code, $parts, $html);
            self::bucket($kind, $idx, 'audit:'.$code, $rewrite, $meta, $sections, $sectionCodes);
        }

        if ($rewrite !== []) {
            return self::result(self::REWRITE, ['Viết lại cả bài vì lỗi ảnh hưởng toàn bài, cấu trúc hoặc không định vị được: '.implode('; ', array_unique($rewrite)).'.'], []);
        }

        $indexes = array_values(array_unique(array_keys($sections)));
        sort($indexes);

        if ($indexes !== []) {
            $total = count($parts ?? []);
            if ($total > 0 && count($indexes) / $total > self::MAX_SECTION_RATIO) {
                return self::result(self::REWRITE, [sprintf('Cần sửa %d/%d mục (hơn 50%%) nên viết lại cả bài cho rẻ hơn gửi lại từng mục (mã: %s).', count($indexes), $total, implode(', ', array_unique($sectionCodes)))], []);
            }
            $reasons = ['Sửa '.count($indexes).' mục (số thứ tự '.implode(', ', array_map(fn ($i) => $i + 1, $indexes)).') vì mã: '.implode(', ', array_unique($sectionCodes)).'.'];
            if ($meta !== []) {
                $reasons[] = 'Cần sửa cả tiêu đề SEO/meta vì mã: '.implode(', ', array_unique($meta)).'.';
            }

            return self::result(self::SECTIONS, $reasons, $indexes);
        }

        if ($meta !== []) {
            return self::result(self::META, ['Chỉ sửa tiêu đề SEO/meta vì mã: '.implode(', ', array_unique($meta)).'.'], []);
        }

        return self::result(self::NONE, ['Không có lỗi nào cần sửa (báo cáo chất lượng và mã audit đều sạch).'], []);
    }

    /** @return array{0:string,1:list<int>} */
    private static function ruleKind(RuleResult $r, ?array $parts): array
    {
        switch ($r->code) {
            case 'META01':
                return [self::META, []];
            case 'KW01':
                return $r->label === 'Từ khóa trong tiêu đề SEO' ? [self::META, []] : [self::REWRITE, []];
            case 'IMG01':
                return self::locateImages($r, $parts);
            case 'LINK01':
                return self::locateLinks($r, $parts);
            case 'KB01':
                return self::locateSentence($r, $parts);
            default: // LEN01, HEAD01, DUP01, SAFE01, BAN01, FACT01, FAQ01, EVID01, SRC01, mã lạ
                return [self::REWRITE, []];
        }
    }

    /** @return array{0:string,1:list<int>} */
    private static function auditKind(string $code, ?array $parts, ?string $html): array
    {
        if (in_array($code, self::AUDIT_META, true)) {
            return [self::META, []];
        }
        if ($code === 'image_no_alt' && $parts !== null) {
            $idx = [];
            foreach ($parts as $s) {
                if (preg_match_all('/<img\b[^>]*>/iu', $s['html'], $m)) {
                    foreach ($m[0] as $tag) {
                        if (! preg_match('/\balt\s*=\s*("[^"]*[^"\s][^"]*"|\'[^\']*[^\'\s][^\']*\')/iu', $tag)) {
                            $idx[] = $s['index'];
                            break;
                        }
                    }
                }
            }

            // ảnh thiếu alt nằm ngoài mục (phần mở đầu) hoặc không thấy → không đoán
            return $idx === [] ? [self::REWRITE, []] : [self::SECTIONS, $idx];
        }

        return [self::REWRITE, []];
    }

    /** IMG01: excerpt = các src (tối đa 5, ngăn bằng "; ", có thể cắt "…"). */
    private static function locateImages(RuleResult $r, ?array $parts): array
    {
        $items = self::items($r);
        if ($parts === null || $items === [] || self::missingCount($r) > count($items)) {
            return [self::REWRITE, []];
        }
        $idx = [];
        foreach ($items as $src) {
            if (str_starts_with($src, 'Ảnh đại diện:') || $src === '(không có src)') {
                return [self::REWRITE, []];
            }
            $found = self::sectionsContaining($parts, $src, true);
            if ($found === []) {
                return [self::REWRITE, []];
            }
            $idx = array_merge($idx, $found);
        }

        return [self::SECTIONS, $idx];
    }

    /** LINK01: excerpt = các href lạ. Mục P2 "thiếu liên kết nội bộ" không có vị trí → REWRITE. */
    private static function locateLinks(RuleResult $r, ?array $parts): array
    {
        $items = self::items($r);
        if ($r->label !== 'Liên kết thuộc website' || $parts === null || $items === [] || self::missingCount($r) > count($items)) {
            return [self::REWRITE, []];
        }
        $idx = [];
        foreach ($items as $href) {
            $found = self::sectionsContaining($parts, $href, true);
            if ($found === []) {
                return [self::REWRITE, []];
            }
            $idx = array_merge($idx, $found);
        }

        return [self::SECTIONS, $idx];
    }

    /** KB01: excerpt = câu sai số liệu (có thể cắt "…"). Phải khớp đúng một mục. */
    private static function locateSentence(RuleResult $r, ?array $parts): array
    {
        $s = trim((string) $r->excerpt);
        if ($parts === null || $s === '') {
            return [self::REWRITE, []];
        }
        $found = self::sectionsContaining($parts, $s, false);

        return count($found) === 1 ? [self::SECTIONS, $found] : [self::REWRITE, []];
    }

    /** @return list<string> */
    private static function items(RuleResult $r): array
    {
        $out = [];
        foreach (explode('; ', (string) $r->excerpt) as $x) {
            $x = trim(rtrim(trim($x), '…'));
            if ($x !== '') {
                $out[] = $x;
            }
        }

        return $out;
    }

    /** Số lỗi báo trong `measured` ("3 ảnh thiếu alt", "2 liên kết lạ"); excerpt chỉ có tối đa 5 nên nếu nhiều hơn là thiếu thông tin. */
    private static function missingCount(RuleResult $r): int
    {
        return preg_match('/^(\d+)/', (string) $r->measured, $m) ? (int) $m[1] : 0;
    }

    /**
     * @param  bool  $raw  true: tìm trong HTML thô (src/href); false: tìm trong chữ thuần đã chuẩn hóa
     * @return list<int>
     */
    private static function sectionsContaining(array $parts, string $needle, bool $raw): array
    {
        $out = [];
        $needleNorm = self::norm($needle);
        foreach ($parts as $s) {
            $hay = $raw
                ? html_entity_decode($s['html'], ENT_QUOTES | ENT_HTML5, 'UTF-8')
                : self::norm(html_entity_decode(strip_tags($s['html']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (str_contains($hay, $raw ? $needle : $needleNorm)) {
                $out[] = $s['index'];
            }
        }

        return $out;
    }

    private static function norm(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    private static function bucket(string $kind, array $idx, string $code, array &$rewrite, array &$meta, array &$sections, array &$sectionCodes): void
    {
        match ($kind) {
            self::REWRITE => $rewrite[] = $code,
            self::META => $meta[] = $code,
            default => (function () use ($idx, $code, &$sections, &$sectionCodes) {
                foreach ($idx as $i) {
                    $sections[$i] = true;
                }
                $sectionCodes[] = $code;
            })(),
        };
    }

    /** @return array{scope:string, reasons:list<string>, section_indexes:list<int>} */
    private static function result(string $scope, array $reasons, array $indexes): array
    {
        return ['scope' => $scope, 'reasons' => array_values($reasons), 'section_indexes' => array_values($indexes)];
    }
}
