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
    /** @param  list<int>  $excludeIds Trí tuệ đã gắn. @return list<array{id:int,name:string,reason:string,score:int}> */
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
        $kwBigrams = array_keys($shown);

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
            if ($hitBi === [] && count($hitWords) < 2) {
                continue; // một từ đơn lẻ trùng chưa đủ tin
            }
            $score = (int) round(array_sum(array_map(fn ($bg) => 100 / max(1, $df[$bg]), $hitBi))) + count($hitWords);
            $out[] = ['id' => $b->id, 'name' => $b->name, 'score' => $score,
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
