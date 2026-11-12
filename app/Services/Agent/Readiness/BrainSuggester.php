<?php

namespace App\Services\Agent\Readiness;

use App\Models\Article;
use App\Models\Brain;
use Illuminate\Support\Str;

/**
 * Gợi ý Trí tuệ khớp chủ đề của bài mà website CHƯA gắn (ví dụ mẫu "Đái tháo đường (tiểu đường)" cho bài "người tiểu đường nên ăn gì").
 * Thuần luật: so cụm 2 từ liền nhau (ưu tiên) rồi từ đơn ≥ 4 ký tự giữa từ khóa bài và tên/mô tả/khóa lĩnh vực của Trí tuệ, không phân biệt dấu.
 * Chỉ GỢI Ý, người xác nhận mới gắn (gắn Trí tuệ đổi hạng rủi ro và mức bắt buộc nguồn của mọi bài của website).
 */
final class BrainSuggester
{
    /**
     * Cụm 2 từ chung chung (không mang chủ đề): trùng một cụm như vậy với mô tả của Trí tuệ không nói gì về chủ đề. Trước đây "hướng dẫn", "chăm sóc"
     * (có trong mô tả của Trí tuệ Dược/Sức khỏe trẻ em) khiến bài "hướng dẫn thiết lập email tự động chăm sóc khách hàng" bị tự gắn Trí tuệ y tế,
     * nâng hạng rủi ro lên cao và ép bài về chuyên gia. Không dấu, chữ thường.
     */
    public const GENERIC_BIGRAMS = [
        'huong dan', 'cham soc', 'khach hang', 'so sanh', 'la gi', 'cach lam', 'cach chon', 'tai nha', 'tot nhat', 'bao gia', 'dich vu', 'cong ty', 'phan mem',
        'doanh nghiep', 'thiet lap', 'quy trinh', 'cho nguoi', 'nen chon', 'kinh nghiem', 'moi nhat', 'hieu qua', 'tu van', 'chi tiet', 'tu dong',
    ];

    /** @param  list<int>  $excludeIds Trí tuệ đã gắn. @return list<array{id:int,name:string,reason:string,score:int,bigram:bool}> */
    public static function forArticle(Article $article, array $excludeIds = [], int $limit = 3): array
    {
        $raw = self::tokens((string) $article->primary_keyword);
        $kw = array_column($raw, 'k');
        if ($kw === []) {
            return [];
        }
        $shown = [];   // bigram không dấu => chữ gốc để hiển thị
        for ($i = 0; $i < count($raw) - 1; $i++) {
            $shown[$raw[$i]['k'].' '.$raw[$i + 1]['k']] = $raw[$i]['o'].' '.$raw[$i + 1]['o'];
        }
        $kwBigrams = array_values(array_diff(array_keys($shown), self::GENERIC_BIGRAMS));

        $brains = Brain::query()->where('status', 'active')->whereNotIn('id', $excludeIds)->get();
        $perBrain = [];
        $df = [];   // bigram => số Trí tuệ chứa nó: cụm càng hiếm càng đáng tin (tránh "thực phẩm" kéo mọi Trí tuệ lên)
        foreach ($brains as $b) {
            $words = array_column(self::tokens($b->name.' '.$b->description.' '.str_replace('_', ' ', (string) $b->domain_key)), 'k');
            $perBrain[$b->id] = ['words' => $words, 'bi' => array_values(array_intersect($kwBigrams, self::bigrams($words)))];
            foreach ($perBrain[$b->id]['bi'] as $bg) {
                $df[$bg] = ($df[$bg] ?? 0) + 1;
            }
        }
        $out = [];
        foreach ($brains as $b) {
            $hitBi = $perBrain[$b->id]['bi'];
            $hitWords = array_values(array_filter(array_unique(array_intersect($kw, $perBrain[$b->id]['words'])), fn ($w) => mb_strlen($w) >= 4));
            if ($hitBi === []) {
                continue; // chỉ tin cụm 2 từ liền nhau: hai từ rời chung chung ("đường", "thường") kéo nhầm Trí tuệ không liên quan
            }
            $score = (int) round(array_sum(array_map(fn ($bg) => 100 / max(1, $df[$bg]), $hitBi))) + count($hitWords);
            $out[] = ['id' => $b->id, 'name' => $b->name, 'score' => $score, 'bigram' => $hitBi !== [],
                'reason' => 'Trùng "'.($hitBi !== [] ? $shown[$hitBi[0]] : implode(' ', array_slice($hitWords, 0, 2))).'" với chủ đề của bài'];
        }
        usort($out, fn ($a, $b) => $b['score'] <=> $a['score'] ?: $a['id'] <=> $b['id']);

        return array_slice($out, 0, $limit);
    }

    /** @return list<array{o:string,k:string}> o = chữ gốc (giữ dấu, chữ thường), k = khóa không dấu */
    private static function tokens(string $s): array
    {
        $o = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_map(fn ($w) => ['o' => $w, 'k' => Str::ascii($w)], $o);
    }

    /** @param list<string> $w @return list<string> */
    private static function bigrams(array $w): array
    {
        $o = [];
        for ($i = 0; $i < count($w) - 1; $i++) {
            if (mb_strlen($w[$i]) >= 2 && mb_strlen($w[$i + 1]) >= 2) {
                $o[] = $w[$i].' '.$w[$i + 1];
            }
        }

        return array_values(array_unique($o));
    }
}
