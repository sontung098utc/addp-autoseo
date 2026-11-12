<?php

namespace App\Services\Agent\Context;

use App\Models\Article;
use App\Models\MediaAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * AG-S25: chọn tối đa 3 ảnh trong THƯ VIỆN của website (và ảnh chung website_id null) cho bài đang viết. Tất định, không AI, không bịa:
 * chỉ trả ảnh có thật trong thư viện, có khớp thẻ/alt/tiêu đề/chú thích/tên file với từ khóa (so không dấu), và có alt dùng được.
 * Trọng số và ngưỡng là heuristic nội bộ, chưa hiệu chỉnh trên dữ liệu thật.
 */
final class ImageSelector
{
    public const LIMIT = 3;

    /** Ảnh có chiều rộng đã biết nhỏ hơn mức này bị loại (quá nhỏ làm ảnh bìa). */
    public const MIN_WIDTH = 400;

    /** Điểm khớp nội dung tối thiểu (trước thưởng/phạt) để được coi là phù hợp. */
    public const MIN_RELEVANCE = 1.0;

    private const FIELD_WEIGHT = ['tags' => 4.0, 'alt' => 3.0, 'title' => 2.0, 'caption' => 2.0, 'file' => 1.5];

    /**
     * @param  list<mixed>  $outline
     * @return list<array{id:int,url:string,file_path:string,alt_goi_y:string,diem:float,ly_do:string}>
     */
    public function select(Article $article, array $outline = []): array
    {
        $kw = TextMatch::tokens((string) $article->primary_keyword);
        if ($kw === [] || ! $article->website_id) {
            return [];
        }
        $kwPhrase = TextMatch::fold((string) $article->primary_keyword);
        $ol = array_values(array_diff(TextMatch::outlineTokens($outline), $kw));

        $assets = MediaAsset::query()
            ->where(fn ($q) => $q->where('website_id', $article->website_id)->orWhereNull('website_id'))
            ->where('mime_type', 'like', 'image/%')
            ->get();
        if ($assets->isEmpty()) {
            return [];
        }
        $uses = DB::table('article_version_media')->whereIn('media_asset_id', $assets->pluck('id'))
            ->selectRaw('media_asset_id, count(*) as c')->groupBy('media_asset_id')->pluck('c', 'media_asset_id');

        $scored = [];
        foreach ($assets as $a) {
            if (($a->metadata['ai_generated'] ?? false) === true) {
                continue; // ảnh do AI tạo cho bài khác không phải ảnh thư viện của website: không tái dùng làm ảnh bìa bài này
            }
            if ($a->width !== null && $a->width < self::MIN_WIDTH) {
                continue;
            }
            $alt = $this->altFor($a);
            if ($alt === '') {
                continue; // không có chữ nào để làm alt: sẽ trượt IMG01, không chọn
            }
            $fields = [
                'tags' => implode(' ', array_map(fn ($t) => is_scalar($t) ? (string) $t : '', (array) $a->tags)),
                'alt' => (string) $a->alt_text, 'title' => (string) $a->title, 'caption' => (string) $a->caption,
                'file' => pathinfo((string) ($a->original_name ?: $a->file_name), PATHINFO_FILENAME),
            ];
            $rel = 0.0;
            $hit = [];
            foreach ($fields as $name => $text) {
                $tok = TextMatch::tokens($text);
                $m = TextMatch::coverage($kw, $tok);
                $o = min(1.0, count(array_intersect($ol, $tok)) / 5);
                $phrase = count($kw) > 1 && $text !== '' && str_contains(' '.TextMatch::fold($text).' ', ' '.$kwPhrase.' ') ? 1.0 : 0.0;
                $part = self::FIELD_WEIGHT[$name] * ($m + 0.3 * $o + 0.5 * $phrase);
                if ($m > 0 || $o > 0) {
                    $hit[] = ['tags' => 'thẻ', 'alt' => 'alt', 'title' => 'tiêu đề', 'caption' => 'chú thích', 'file' => 'tên file'][$name];
                }
                $rel += $part;
            }
            if ($rel < self::MIN_RELEVANCE) {
                continue;
            }
            $score = $rel;
            $why = ['khớp từ khóa ở: '.implode(', ', $hit)];
            if (mb_strlen(trim((string) $a->alt_text)) >= 10) {
                $score += 1.0;
                $why[] = 'alt đủ dài';
            }
            if ($a->width !== null) {
                $score += 0.5;
                $why[] = "rộng {$a->width}px";
            }
            if ($a->website_id !== null) {
                $score += 0.5;
            } else {
                $why[] = 'ảnh dùng chung';
            }
            $used = (int) ($uses[$a->id] ?? 0);
            if ($used > 0) {
                $score -= min(3.0, 0.5 * $used);
                $why[] = "đã dùng ở {$used} bản bài";
            }
            $scored[] = ['id' => $a->id, 'url' => $this->publicUrl($a), 'file_path' => (string) $a->file_path, 'alt_goi_y' => $alt, 'diem' => round($score, 1), 'ly_do' => implode('; ', $why)];
        }
        usort($scored, fn ($x, $y) => [$y['diem'], $x['id']] <=> [$x['diem'], $y['id']]);

        return array_slice($scored, 0, self::LIMIT);
    }

    /** Alt có sẵn (người nhập) → tiêu đề → chú thích. Không tự bịa mô tả. */
    private function altFor(MediaAsset $a): string
    {
        foreach ([$a->alt_text, $a->title, $a->caption] as $c) {
            $c = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $c)) ?? '');
            if ($c !== '') {
                return mb_substr($c, 0, 125);
            }
        }

        return '';
    }

    private function publicUrl(MediaAsset $a): string
    {
        try {
            $url = Storage::disk($a->disk ?: 'public')->url($a->file_path);
        } catch (\Throwable) {
            $url = '/storage/'.ltrim((string) $a->file_path, '/');
        }
        $own = parse_url((string) config('app.url'), PHP_URL_HOST);
        if ($own && preg_match('#^https?://([^/:]+)(?::\d+)?(/.*)$#i', $url, $m) && strcasecmp($m[1], $own) === 0) {
            return $m[2]; // ảnh của chính ứng dụng: dùng đường dẫn tương đối
        }

        return $url;
    }
}
