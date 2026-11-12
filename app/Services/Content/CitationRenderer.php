<?php

namespace App\Services\Content;

use App\Models\KnowledgeItem;

/**
 * Đổi dấu trích dẫn thô [KB#12] (cách AI đánh dấu mục Kho trí tuệ để hệ thống kiểm tra) thành chú thích số [1] cho người đọc,
 * kèm khối "Nguồn tham khảo" cuối bài. Dấu số vẫn mang data-kb="id" nên CitationRule vẫn đối chiếu được. Mục không tồn tại giữ nguyên dấu thô để luật CITE01 còn bắt được.
 */
final class CitationRenderer
{
    private const MARK = '/\s*\[KB#(\d+(?:\s*[,;]\s*(?:KB)?#?\d+)*)\]/u';

    public static function render(string $html): string
    {
        if ($html === '' || ! preg_match_all(self::MARK, $html, $all)) {
            return $html;
        }
        $ids = [];
        foreach ($all[1] as $grp) {
            foreach (preg_split('/[^\d]+/', $grp, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $n) {
                $ids[(int) $n] = true;
            }
        }
        $items = KnowledgeItem::query()->with('source')->whereIn('id', array_keys($ids))->get()->keyBy('id');
        $number = [];
        $html = (string) preg_replace_callback(self::MARK, function (array $m) use ($items, &$number): string {
            $kb = [];
            $nums = [];
            foreach (preg_split('/[^\d]+/', $m[1], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $n) {
                $id = (int) $n;
                if (! $items->has($id)) {
                    return $m[0];
                }
                $number[$id] ??= count($number) + 1;
                $kb[] = $id;
                $nums[] = $number[$id];
            }
            sort($nums);

            return '<sup class="autoseo-cite" data-kb="'.implode(',', $kb).'">['.implode(', ', array_unique($nums)).']</sup>';
        }, $html);
        if ($number === []) {
            return $html;
        }
        asort($number);
        $li = '';
        foreach (array_keys($number) as $id) {
            $it = $items[$id];
            $src = $it->source;
            $label = e((string) ($src?->title ?: $it->title));
            $url = (string) ($src?->final_url ?: $src?->url);
            $link = preg_match('#^https?://#i', $url) ? ' — <a href="'.e($url).'" rel="nofollow noopener">'.e(parse_url($url, PHP_URL_HOST) ?: $url).'</a>' : '';
            $li .= '<li data-kb="'.$id.'">'.$label.($src && $it->title !== $src->title ? ': '.e((string) $it->title) : '').$link.'</li>';
        }

        return $html."\n".'<div class="autoseo-refs"><p><strong>Nguồn tham khảo</strong></p><ol>'.$li.'</ol></div>';
    }
}
