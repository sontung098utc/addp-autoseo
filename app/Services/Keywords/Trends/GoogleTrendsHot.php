<?php

namespace App\Services\Keywords\Trends;

use App\Models\Keyword;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * "Đang nổi hôm nay" từ nguồn cấp RSS công khai của Google Trends Việt Nam (không cần khóa). Chủ yếu là tin tức/thể thao nên thường KHÔNG khớp
 * lĩnh vực chuyên ngành; chỉ gắn nhãn khi một cụm trong danh sách trùng thật với từ khóa (ít nhất 2 từ chính hoặc cả cụm).
 */
final class GoogleTrendsHot
{
    /** @return list<string> tiêu đề các chủ đề đang nổi (chữ thường), rỗng nếu lỗi */
    public function titles(): array
    {
        $r = Cache::remember('trend:hot:vn', now()->addHours(3), function () {
            try {
                $res = Http::withUserAgent((string) config('keywords.trends.user_agent', 'autoseo-research/1.0'))->timeout(15)->get((string) config('keywords.trends.rss'));
                if (! $res->successful()) {
                    return [];
                }
                $xml = @simplexml_load_string((string) $res->body(), 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
                $out = [];
                foreach ($xml->channel->item ?? [] as $it) {
                    $out[] = Keyword::keyFor((string) $it->title);
                }

                return array_slice(array_values(array_filter($out)), 0, 40);
            } catch (\Throwable) {
                return [];
            }
        });

        return is_array($r) ? $r : [];
    }

    public function matches(string $keyword): ?string
    {
        $tok = fn (string $t) => array_values(array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', Keyword::keyFor($t), -1, PREG_SPLIT_NO_EMPTY) ?: [], fn ($x) => mb_strlen($x) >= 2)));
        $k = $tok($keyword);
        foreach ($this->titles() as $t) {
            $common = count(array_intersect($k, $tok($t)));
            if ($k !== [] && ($common >= 2 || ($common >= 1 && count($k) === 1)) && $common >= (int) ceil(count($k) * 0.6)) {
                return $t;
            }
        }

        return null;
    }
}
