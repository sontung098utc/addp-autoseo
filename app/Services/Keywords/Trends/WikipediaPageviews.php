<?php

namespace App\Services\Keywords\Trends;

use App\Models\Keyword;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Xu hướng quan tâm theo LƯỢT XEM bài Wikipedia tiếng Việt cùng chủ đề (API chính thức, miễn phí, không cần khóa).
 * Chỉ là thước đo sự quan tâm, KHÔNG phải lượt tìm trên Google; chỉ dùng được cho chủ đề có bài Wikipedia khớp và đủ lượt xem để không nhiễu.
 * So 14 ngày gần nhất với 14 ngày liền trước. Không đủ dữ liệu thì trả "unknown" kèm lý do, không đoán.
 */
final class WikipediaPageviews
{
    public const WINDOW = 14;

    /** Mỗi kỳ cần ít nhất ngần này lượt xem mới so sánh (ít hơn thì dao động ngẫu nhiên lớn hơn xu hướng). */
    public const MIN_VIEWS = 60;

    /** Tăng/giảm từ ngưỡng này (%) mới coi là có xu hướng. */
    public const THRESHOLD_PCT = 25;

    /** @return array{direction:string, change_pct:?int, recent:?int, previous:?int, article:?string, source:string, reason:?string} */
    public function signal(string $keyword): array
    {
        $key = 'trend:wiki:'.md5(Keyword::keyFor($keyword)).':'.now()->toDateString();
        if (is_array($hit = Cache::get($key))) {
            return $hit;
        }
        $r = $this->compute($keyword);
        // Bị giới hạn tần suất thì chỉ nhớ ít phút (thử lại sau), kết quả thật mới nhớ cả ngày.
        Cache::put($key, $r, ($r['rate_limited'] ?? false) ? now()->addMinutes(10) : now()->addDay());

        return $r;
    }

    /** @return array<string,mixed> */
    private function compute(string $keyword): array
    {
        $unknown = fn (string $why, ?string $article = null, bool $limited = false, ?int $recent = null, ?int $previous = null) => ['direction' => 'unknown', 'change_pct' => null, 'recent' => $recent, 'previous' => $previous, 'article' => $article, 'source' => 'wikipedia', 'reason' => $why, 'rate_limited' => $limited];
        $query = self::withSynonym($keyword);
        $ua = (string) config('keywords.trends.user_agent', 'autoseo-research/1.0');
        try {
            $s = Http::withUserAgent($ua)->acceptJson()->timeout(15)->get((string) config('keywords.trends.wikipedia_api'), ['action' => 'opensearch', 'search' => mb_substr($query, 0, 120), 'limit' => 3, 'format' => 'json']);
            if ($s->status() === 429) {
                return $unknown('Wikipedia đang giới hạn tần suất gọi (HTTP 429); thử lại sau ít phút.', null, true);
            }
            $titles = $s->successful() ? (array) ($s->json()[1] ?? []) : [];
            $title = null;
            foreach ($titles as $t) {
                if ($this->matches($query, (string) $t)) {
                    $title = (string) $t;
                    break;
                }
            }
            if ($title === null) {
                return $unknown('Không có bài Wikipedia khớp chủ đề này.');
            }
            $end = now()->subDays(2); // dữ liệu lượt xem trễ khoảng một ngày
            $start = $end->copy()->subDays(self::WINDOW * 2 - 1);
            $url = rtrim((string) config('keywords.trends.wikipedia_pageviews'), '/').'/'.rawurlencode(str_replace(' ', '_', $title)).'/daily/'.$start->format('Ymd').'/'.$end->format('Ymd');
            $v = Http::withUserAgent($ua)->acceptJson()->timeout(15)->get($url);
        } catch (\Throwable $e) {
            return $unknown('Không kết nối được Wikipedia: '.mb_substr($e->getMessage(), 0, 80));
        }
        if ($v->status() === 429) {
            return $unknown('Wikipedia đang giới hạn tần suất gọi (HTTP 429); thử lại sau ít phút.', $title, true);
        }
        if (! $v->successful()) {
            return $unknown('Wikipedia chưa có số lượt xem cho bài này (HTTP '.$v->status().').', $title);
        }
        // Ngày không có lượt xem bị bỏ khỏi dữ liệu: cộng theo NGÀY trong từng cửa sổ chứ không đếm số dòng.
        $byDay = [];
        foreach ((array) ($v->json('items') ?? []) as $i) {
            $byDay[substr((string) ($i['timestamp'] ?? ''), 0, 8)] = (int) ($i['views'] ?? 0);
        }
        if ($byDay === []) {
            return $unknown('Wikipedia chưa có dữ liệu lượt xem trong kỳ đo.', $title);
        }
        $sum = function ($from, $to) use ($byDay) {
            $t = 0;
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                $t += $byDay[$d->format('Ymd')] ?? 0;
            }

            return $t;
        };
        $recent = $sum($end->copy()->subDays(self::WINDOW - 1), $end);
        $prev = $sum($end->copy()->subDays(self::WINDOW * 2 - 1), $end->copy()->subDays(self::WINDOW));
        if ($prev < self::MIN_VIEWS || $recent < self::MIN_VIEWS) {
            return $unknown('Lượt xem bài «'.$title.'» quá thấp ('.$prev.' → '.$recent.') nên chưa kết luận được xu hướng.', $title, false, $recent, $prev);
        }
        $pct = (int) round(($recent - $prev) / $prev * 100);
        $dir = $pct >= self::THRESHOLD_PCT ? 'up' : ($pct <= -self::THRESHOLD_PCT ? 'down' : 'flat');

        return ['direction' => $dir, 'change_pct' => $pct, 'recent' => $recent, 'previous' => $prev, 'article' => $title, 'source' => 'wikipedia', 'reason' => null];
    }

    /** Từ đồng nghĩa y khoa hay gặp (cấu hình được): "tiểu đường" là tên thường gọi, bài Wikipedia tên "Đái tháo đường". */
    public static function withSynonym(string $keyword): string
    {
        $k = Keyword::keyFor($keyword);
        foreach ((array) config('keywords.trends.synonyms', []) as $from => $to) {
            if (str_contains($k, Keyword::keyFor((string) $from))) {
                return str_replace(Keyword::keyFor((string) $from), Keyword::keyFor((string) $to), $k);
            }
        }

        return $keyword;
    }

    /** Tiêu đề bài khớp từ khóa khi có ít nhất 60% từ chính của từ khóa (tránh gán nhầm sang bài khác). */
    private function matches(string $keyword, string $title): bool
    {
        $w = fn (string $t) => array_values(array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', Keyword::keyFor($t), -1, PREG_SPLIT_NO_EMPTY) ?: [], fn ($x) => mb_strlen($x) >= 2)));
        $k = $w($keyword);

        return $k !== [] && count(array_intersect($k, $w($title))) >= (int) ceil(count($k) * 0.6);
    }
}
