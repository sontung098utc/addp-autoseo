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
        $kw = self::words((string) $article->primary_keyword);
        if (count($kw) === 0) {
            return [];
        }
        $kwBigrams = self::bigrams($kw);
        $out = [];
        foreach (Brain::query()->where('status', 'active')->whereNotIn('id', $excludeIds)->get() as $b) {
            $words = self::words($b->name.' '.$b->description.' '.str_replace('_', ' ', (string) $b->domain_key));
            $hitBi = array_values(array_intersect($kwBigrams, self::bigrams($words)));
            $hitWords = array_values(array_filter(array_unique(array_intersect($kw, $words)), fn ($w) => mb_strlen($w) >= 4));
            $score = count($hitBi) * 10 + count($hitWords);
            if ($hitBi === [] && count($hitWords) < 2) {
                continue; // một từ đơn lẻ trùng chưa đủ tin
            }
            $out[] = ['id' => $b->id, 'name' => $b->name, 'score' => $score,
                'reason' => 'Trùng "'.($hitBi[0] ?? implode(' ', array_slice($hitWords, 0, 2))).'" với chủ đề của bài'];
        }
        usort($out, fn ($a, $b) => $b['score'] <=> $a['score'] ?: $a['id'] <=> $b['id']);

        return array_slice($out, 0, $limit);
    }

    /** @return list<string> */
    private static function words(string $s): array
    {
        $s = Str::of(Str::ascii(mb_strtolower($s)))->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->toString();

        return $s === '' ? [] : array_values(array_filter(explode(' ', $s), fn ($w) => $w !== ''));
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
