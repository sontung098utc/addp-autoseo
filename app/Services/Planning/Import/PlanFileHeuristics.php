<?php

namespace App\Services\Planning\Import;

use Illuminate\Support\Str;

/**
 * Lớp kiểm tra bằng DỮ LIỆU THẬT đặt sau AI, không phụ thuộc model: model yếu hoặc đoán sai (chọn nhầm dòng tiêu đề, gán cột lệch) thì kết quả
 * vẫn phải hợp lý. Gồm: (1) sửa dòng tiêu đề khi AI chọn một dòng nằm sau dòng trông đúng là tiêu đề rõ ràng hơn; (2) hạ mọi cột AI gán mà nội dung
 * không khớp trường (cột "ngày" không phải ngày, cột "lượng tìm" không phải số...); (3) nhận cột theo tên cột để điền chỗ AI bỏ sót/nhận sai
 * (và làm phương án dự phòng khi AI không dùng được); (4) bảo đảm có cột từ khóa chính bằng cách đoán theo nội dung.
 * Mọi thứ ở đây là luật tất định, kiểm thử được; AI chỉ là một nguồn gợi ý.
 */
final class PlanFileHeuristics
{
    private const SAMPLE = 80;

    /** Trường mà TÊN CỘT thường rất rõ nghĩa: khi tên cột nói rõ và nội dung hợp, tên cột thắng gán sai của AI. Không gồm title/guidance (nhập nhằng). */
    private const STRONG = ['primary_keyword', 'planned_date', 'search_volume', 'priority', 'secondary_keywords', 'search_intent', 'campaign', 'campaign_description', 'topic_cluster'];

    /** Luật theo tên cột (không dấu, chữ thường). Thứ tự = ưu tiên: luật cụ thể đặt trước. 'title' sẽ thành guidance nếu có từ 2 cột trở lên. */
    private const RULES = [
        ['ignore', '/(trang thai|status|nguoi phu trach|nguoi duyet|reviewer|bac si tham dinh|bien tap vien|\bbtv\b|\bowner\b|\bwriter\b|ngan sach|budget)/'],
        ['internal_links', '/(lien ket noi bo|internal link|link noi bo|bai lien quan noi bo)/'],
        ['difficulty', '/(do kho|difficulty|\bkd\b)/'],
        ['cpc', '/(\bcpc\b|gia thau)/'],
        ['competition', '/(muc canh tranh|canh tranh quang cao|competition)/'],
        ['ignore', '/^(stt|#|no\.?|tt|id|so thu tu|link|url)\b/'],
        ['search_volume', '/(luong tim|search volume|\bvolume\b|\bvol\b)/'],
        ['secondary_keywords', '/(tu khoa phu|tu khoa lien quan|\blsi\b|related|secondary|cau hoi lien quan|\bpaa\b)/'],
        ['primary_keyword', '/^(tu khoa|keyword|kw\b|chu de chinh|main keyword|focus keyword)/'],
        ['search_intent', '/(y dinh|\bintent\b|funnel)/'],
        ['planned_date', '/(ngay dang|ngay xuat ban|ngay du kien|ngay publish|publish|\bpub\b|deadline|lich dang|^ngay$|^date$)/'],
        ['priority', '/(uu tien|priority|^p$)/'],
        ['campaign_description', '/(muc tieu chien dich|mo ta chien dich)/'],
        ['campaign', '/(chien dich|campaign|giai doan|\bphase\b)/'],
        ['topic_cluster', '/(\bcum\b|cluster|pillar|nhom|\btopic\b)/'],
        ['post_type', '/(loai bai|dang bai|content type|post type|dinh dang bai)/'],
        ['length_words', '/(do dai|so tu|word count|^length|so chu)/'],
        ['table_count', '/(so bang|^bang$|table)/'],
        ['image_count', '/(so anh|so hinh|image count|anh toi da)/'],
        ['h2_count', '/(so muc h2|so h2|^h2$|so muc toi thieu)/'],
        ['faq', '/(\bfaq\b|hoi dap|cau hoi thuong gap)/'],
        ['audience', '/(doi tuong|audience|persona|nguoi doc)/'],
        ['angle', '/(goc tiep can|goc viet|goc nhin|angle|dieu can nhan|nhan manh)/'],
        ['tone', '/(giong van|\btone\b|giong dieu|van phong)/'],
        ['questions', '/(cau hoi can tra loi|cau hoi bai|cau hoi doc gia|cau hoi cua bai)/'],
        ['must_sources', '/(nguon nen dung|nguon bat buoc|nguon tham khao|tai lieu tham khao|references?\b)/'],
        ['avoid', '/(can tranh|dieu tranh|tu cam|khong nen noi|avoid|forbidden)/'],
        ['competitor_refs', '/(doi thu|competitor|bai tham khao|bai mau|url doi thu)/'],
        ['cta', '/(\bcta\b|san pham lien quan|keu goi hanh dong)/'],
        ['expert_review', '/(chuyen gia duyet|can chuyen gia|bac si duyet|duyet chuyen mon|expert)/'],
        ['title', '/^(tieu de|title)/'],
        ['guidance', '/(ghi chu|\bnote\b|brief|dan y|\bcta\b|goi y|huong dan|doi tuong|goc viet|mo ta)/'],
    ];

    public static function fieldForHeader(string $header): ?string
    {
        $a = mb_strtolower(Str::ascii(trim($header)));
        if ($a === '') {
            return null;
        }
        foreach (self::RULES as [$field, $re]) {
            if (preg_match($re, $a)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Nhận cột chỉ theo tên cột ở dòng tiêu đề.
     *
     * @return array<int,string> chỉ số cột => trường (có thể gồm 'ignore')
     */
    public static function mapByHeader(array $sheet, int $header): array
    {
        if ($header < 1) {
            return [];
        }
        $out = [];
        foreach (($sheet['rows'][$header - 1] ?? []) as $i => $cell) {
            if (($f = self::fieldForHeader((string) $cell)) !== null) {
                $out[(int) $i] = $f;
            }
        }
        if (count(array_filter($out, fn ($f) => $f === 'title')) >= 2) { // nhiều cột tiêu đề/phương án: tất cả là chỉ dẫn viết
            $out = array_map(fn ($f) => $f === 'title' ? 'guidance' : $f, $out);
        }

        return $out;
    }

    /** Gợi ý ban đầu (không cần AI): theo tên cột; dùng khi AI lỗi hoặc làm giá trị khởi tạo cho bảng cột. @return array<int,array{field:string,confidence:?float,reason:string}> */
    public static function seed(array $sheet, int $header): array
    {
        $out = [];
        foreach (self::mapByHeader($sheet, $header) as $i => $f) {
            $out[$i] = ['field' => $f, 'confidence' => null, 'reason' => 'Nhận theo tên cột'];
        }
        if (! in_array('primary_keyword', array_column($out, 'field'), true) && ($k = self::guessKeywordColumn($sheet, $header, $out)) !== null) {
            $out[$k] = ['field' => 'primary_keyword', 'confidence' => null, 'reason' => 'Đoán theo nội dung'];
        }

        return $out;
    }

    /**
     * Nếu AI chọn dòng tiêu đề nằm SAU một dòng trông đúng là tiêu đề cột (nhiều ô, hầu hết là chữ, ngay dưới có dữ liệu) thì dùng dòng đó.
     * Chỉ kéo dòng tiêu đề lên sớm hơn; AI chọn 0 (không có tiêu đề) hoặc chọn sớm hơn thì giữ nguyên.
     */
    public static function correctHeader(array $sheet, int $ai): int
    {
        if ($ai < 1) {
            return $ai;
        }
        $rows = $sheet['rows'];
        $width = 0;
        foreach (array_slice($rows, 0, 30) as $r) {
            $width = max($width, count(array_filter($r, fn ($c) => trim((string) $c) !== '')));
        }
        $need = max(3, (int) ceil($width * 0.6));
        foreach (array_slice($rows, 0, min($ai - 1, 25), true) as $i => $cells) {
            $filled = array_values(array_filter($cells, fn ($c) => trim((string) $c) !== ''));
            if (count($filled) < $need || ! isset($rows[$i + 1]) || count(array_filter($rows[$i + 1], fn ($c) => trim((string) $c) !== '')) < 2) {
                continue;
            }
            if (count(array_filter($filled, fn ($c) => self::textLike((string) $c))) / count($filled) >= 0.8) {
                return $i + 1;
            }
        }

        return $ai;
    }

    /**
     * Đối chiếu bản gán của AI với dữ liệu thật rồi sửa: hạ cột không khớp nội dung, điền cột còn thiếu theo tên cột, bảo đảm có từ khóa chính.
     *
     * @param  array<int,array{field:string,confidence:?float,reason:string}>  $ai
     * @return array{0: array<int,array{field:string,confidence:?float,reason:string}>, 1: list<string>} [bản gán đã sửa, các ghi chú điều chỉnh]
     */
    public static function reconcile(array $sheet, int $header, array $ai): array
    {
        $by = self::mapByHeader($sheet, $header);
        $out = [];
        $demoted = [];
        foreach ($ai as $i => $f) {
            if ($f['field'] !== 'ignore' && ! self::plausible($f['field'], self::values($sheet, $header, (int) $i))) {
                $demoted[] = (int) $i;
                $f = ['field' => 'ignore', 'confidence' => null, 'reason' => ''];
            }
            $out[(int) $i] = $f;
        }
        $overridden = [];
        foreach ($by as $i => $field) {
            if (! in_array($field, self::STRONG, true) || ($out[$i]['field'] ?? 'ignore') === $field || ! self::plausible($field, self::values($sheet, $header, $i))) {
                continue;
            }
            if (! PlanFileFields::allowsMany($field)) { // trường chỉ một cột: gỡ cột khác (AI gán lệch) đang giữ nó
                foreach ($out as $j => $g) {
                    if ($j !== $i && $g['field'] === $field) {
                        $out[$j] = ['field' => 'ignore', 'confidence' => null, 'reason' => ''];
                    }
                }
            }
            $out[$i] = ['field' => $field, 'confidence' => null, 'reason' => 'Nhận theo tên cột'];
            $overridden[] = $i;
        }
        $used = [];
        foreach ($out as $f) {
            if ($f['field'] !== 'ignore' && ! PlanFileFields::allowsMany($f['field'])) {
                $used[$f['field']] = true;
            }
        }
        $filled = [];
        foreach ($by as $i => $field) {
            if (($out[$i]['field'] ?? 'ignore') !== 'ignore' || $field === 'ignore' || (isset($used[$field]) && ! PlanFileFields::allowsMany($field))) {
                continue;
            }
            if (self::plausible($field, self::values($sheet, $header, $i))) {
                $out[$i] = ['field' => $field, 'confidence' => null, 'reason' => 'Nhận theo tên cột'];
                $used[$field] = true;
                $filled[] = $i;
            }
        }
        if (! isset($used['primary_keyword']) && ($k = self::guessKeywordColumn($sheet, $header, $out)) !== null) {
            $out[$k] = ['field' => 'primary_keyword', 'confidence' => null, 'reason' => 'Đoán theo nội dung'];
            $filled[] = $k;
        }
        ksort($out);

        $notes = [];
        $changed = count(array_unique(array_merge($demoted, $overridden)));
        if ($changed > 0) {
            $notes[] = $changed.' cột AI nhận chưa khớp tên/nội dung thật nên đã được điều chỉnh.';
        }
        if ($filled !== []) {
            $notes[] = count($filled).' cột được nhận bổ sung theo tên cột và nội dung.';
        }

        return [$out, $notes];
    }

    /** Giá trị không rỗng của một cột (tối đa SAMPLE dòng sau dòng tiêu đề). @return list<string> */
    public static function values(array $sheet, int $header, int $col): array
    {
        $out = [];
        foreach (array_slice($sheet['rows'], max(0, $header), self::SAMPLE) as $r) {
            $v = trim((string) ($r[$col] ?? ''));
            if ($v !== '') {
                $out[] = $v;
            }
        }

        return $out;
    }

    /** Nội dung cột có hợp với trường được gán không. Cột rỗng thì không có gì để bác bỏ. */
    public static function plausible(string $field, array $vals): bool
    {
        $n = count($vals);
        $ratio = fn (callable $ok) => $n === 0 ? 1.0 : count(array_filter($vals, $ok)) / $n;

        return match ($field) {
            'planned_date', 'campaign_start', 'campaign_end' => $ratio(fn ($v) => PlanFileFields::date($v) !== null) >= 0.6,
            'search_volume' => $ratio(fn ($v) => PlanFileFields::volume($v) !== null) >= 0.7,
            'priority' => $ratio(fn ($v) => PlanFileFields::priority($v) !== null) >= 0.5,
            'primary_keyword' => $n > 0 && $ratio(fn ($v) => self::textLike($v)) >= 0.7 && (array_sum(array_map('mb_strlen', $vals)) / $n) <= 140,
            default => true,
        };
    }

    /** Đoán cột từ khóa chính theo nội dung: cột chữ, giá trị ít trùng, ngắn (từ khóa thường ngắn hơn tiêu đề/ghi chú), chưa dùng cho trường khác. */
    private static function guessKeywordColumn(array $sheet, int $header, array $assigned): ?int
    {
        $width = 0;
        foreach (array_slice($sheet['rows'], 0, 60) as $r) {
            $width = max($width, count($r));
        }
        $best = null;
        $bestScore = -1.0;
        for ($i = 0; $i < min($width, SheetReader::MAX_COLS); $i++) {
            if (isset($assigned[$i]) && $assigned[$i]['field'] !== 'ignore') {
                continue;
            }
            $v = self::values($sheet, $header, $i);
            if (count($v) < 3 || ! self::plausible('primary_keyword', $v)) {
                continue;
            }
            $uniq = count(array_unique(array_map('mb_strtolower', $v))) / count($v);
            $avg = array_sum(array_map('mb_strlen', $v)) / count($v);
            if ($uniq < 0.7 || $avg < 3 || $avg > 100) {
                continue;
            }
            $score = $uniq - $avg / 1000;
            if ($score > $bestScore) {
                $best = $i;
                $bestScore = $score;
            }
        }

        return $best;
    }

    private static function textLike(string $v): bool
    {
        $t = trim($v);

        return $t !== '' && ! is_numeric(str_replace(',', '.', $t)) && PlanFileFields::date($t) === null;
    }
}
