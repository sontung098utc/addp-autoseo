<?php

namespace App\Services\Keywords\Trends;

use App\Models\Keyword;

/**
 * Gộp các nguồn xu hướng MIỄN PHÍ thành một tín hiệu cho từng từ khóa. Mỗi bằng chứng ghi rõ nguồn; không nguồn nào có dữ liệu thì 'unknown'.
 * Hướng chung: 'up' nếu có bằng chứng tăng và không có bằng chứng giảm; 'down' nếu chỉ có bằng chứng giảm; 'flat' nếu có dữ liệu nhưng không đổi.
 */
final class TrendSignals
{
    public function __construct(private readonly WikipediaPageviews $wiki = new WikipediaPageviews, private readonly GoogleTrendsHot $hot = new GoogleTrendsHot) {}

    public static function enabled(): bool
    {
        return (bool) config('keywords.trends.enabled', true);
    }

    /**
     * @param  array{ready:bool, movers:array<string,array{status:string,delta:?int}>}|null  $history  kết quả SuggestionHistory::movers
     * @return array{direction:string, evidence:list<array{source:string,text:string,direction:string}>, wiki:?array<string,mixed>}
     */
    public function forKeyword(string $keyword, ?array $history = null, bool $useWiki = true): array
    {
        $ev = [];
        $wiki = null;
        if ($useWiki) {
            $wiki = $this->wiki->signal($keyword);
            if ($wiki['direction'] !== 'unknown') {
                $ev[] = ['source' => 'wikipedia', 'direction' => $wiki['direction'], 'text' => 'Lượt xem Wikipedia «'.$wiki['article'].'» '.($wiki['change_pct'] >= 0 ? 'tăng ' : 'giảm ').abs($wiki['change_pct']).'% (14 ngày: '.$wiki['previous'].' → '.$wiki['recent'].')'];
            }
        }
        if ($hit = $this->hot->matches($keyword)) {
            $ev[] = ['source' => 'google_trends_rss', 'direction' => 'up', 'text' => 'Đang nổi hôm nay trên Google Trends Việt Nam («'.$hit.'»)'];
        }
        $m = $history['movers'][Keyword::keyFor($keyword)] ?? null;
        if ($m && $m['status'] === 'new') {
            $ev[] = ['source' => 'lich_su_goi_y', 'direction' => 'up', 'text' => 'Gợi ý mới xuất hiện so với lần ghi cũ'];
        } elseif ($m && $m['status'] === 'up') {
            $ev[] = ['source' => 'lich_su_goi_y', 'direction' => 'up', 'text' => 'Leo '.$m['delta'].' hạng trong gợi ý Google so với lần ghi cũ'];
        } elseif ($m && $m['status'] === 'down') {
            $ev[] = ['source' => 'lich_su_goi_y', 'direction' => 'down', 'text' => 'Tụt '.abs((int) $m['delta']).' hạng trong gợi ý Google so với lần ghi cũ'];
        }
        $up = count(array_filter($ev, fn ($e) => $e['direction'] === 'up'));
        $down = count(array_filter($ev, fn ($e) => $e['direction'] === 'down'));
        $dir = $ev === [] ? 'unknown' : ($up > 0 && $down === 0 ? 'up' : ($down > 0 && $up === 0 ? 'down' : ($up > 0 ? 'flat' : 'flat')));

        return ['direction' => $dir, 'evidence' => $ev, 'wiki' => $wiki];
    }
}
