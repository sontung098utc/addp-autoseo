<?php

namespace App\Services\Keywords\Trends;

use App\Models\Keyword;
use Illuminate\Support\Facades\Storage;

/**
 * Tự làm, miễn phí: mỗi lần đề xuất, ghi lại danh sách gợi ý tự động của một chủ đề (một bản mỗi ngày, giữ 90 ngày). Sau vài ngày so sánh được
 * cụm nào MỚI XUẤT HIỆN hoặc LEO HẠNG so với lần ghi cũ, tức là xu hướng của chính danh sách gợi ý. Chưa đủ lịch sử thì nói thẳng, không đoán.
 * Lưu file JSON trong storage (không cần bảng mới). Lên lịch ghi hằng ngày: lệnh `php artisan trends:snapshot`.
 */
final class SuggestionHistory
{
    public const KEEP_DAYS = 90;

    /** Bản cũ nhất cách hôm nay tối thiểu ngần này ngày mới đủ để so sánh. */
    public const MIN_AGE_DAYS = 6;

    public const RANK_JUMP = 3;

    private function path(string $seed): string
    {
        return 'trends/autocomplete/'.md5(Keyword::keyFor($seed)).'.json';
    }

    /** @return array<string,list<string>> ngày => danh sách gợi ý theo thứ hạng */
    public function load(string $seed): array
    {
        $d = Storage::disk('local')->exists($this->path($seed)) ? json_decode((string) Storage::disk('local')->get($this->path($seed)), true) : [];

        return is_array($d) ? $d : [];
    }

    /** @param list<string> $list */
    public function record(string $seed, array $list): void
    {
        if ($list === []) {
            return;
        }
        $d = $this->load($seed);
        $d[now()->toDateString()] = array_values(array_map(fn ($x) => Keyword::keyFor((string) $x), $list));
        ksort($d);
        $d = array_slice($d, -self::KEEP_DAYS, null, true);
        Storage::disk('local')->put($this->path($seed), json_encode($d, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  list<string>  $current  danh sách gợi ý hiện tại
     * @return array{ready:bool, since:?string, days:int, movers:array<string,array{status:string, delta:?int}>}
     *                                                                                                      status: new (chưa từng có ở bản cũ) | up (leo ≥ RANK_JUMP hạng) | down | same
     */
    public function movers(string $seed, array $current): array
    {
        $d = $this->load($seed);
        $today = now()->toDateString();
        unset($d[$today]);
        $base = null;
        $baseDate = null;
        foreach ($d as $date => $list) { // bản cũ gần ngưỡng MIN_AGE_DAYS nhất nhưng không mới hơn nó
            if (now()->parse($date)->diffInDays(now()) >= self::MIN_AGE_DAYS) {
                $base = $list;
                $baseDate = $date;
            }
        }
        if ($base === null) {
            return ['ready' => false, 'since' => null, 'days' => count($d) + (in_array($today, array_keys($this->load($seed)), true) ? 1 : 0), 'movers' => []];
        }
        $pos = array_flip($base);
        $out = [];
        foreach (array_values($current) as $i => $kw) {
            $k = Keyword::keyFor((string) $kw);
            if (! isset($pos[$k])) {
                $out[$k] = ['status' => 'new', 'delta' => null];
            } else {
                $delta = $pos[$k] - $i; // dương = leo hạng
                $out[$k] = ['status' => $delta >= self::RANK_JUMP ? 'up' : ($delta <= -self::RANK_JUMP ? 'down' : 'same'), 'delta' => $delta];
            }
        }

        return ['ready' => true, 'since' => $baseDate, 'days' => count($d), 'movers' => $out];
    }
}
