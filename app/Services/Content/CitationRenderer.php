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
            $out = '';
            foreach (preg_split('/[^\d]+/', $m[1], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $n) {
                $id = (int) $n;
                if (! $items->has($id)) {
                    return $m[0];
                }
                $number[$id] ??= count($number) + 1;
                $it = $items[$id];
                $srcT = $it->source ? trim((string) $it->source->title) : '';
                $url0 = (string) ($it->source?->final_url ?: $it->source?->url);
                $host0 = preg_match('#^https?://#i', $url0) ? preg_replace('/^www\./', '', (string) parse_url($url0, PHP_URL_HOST)) : '';
                // Rê chuột vào số chỉ cho biết NGUỒN (không nhắc lại nội dung câu ở thân bài); bấm để nhảy tới dòng nguồn cuối bài. Định nghĩa/khái niệm bổ sung nằm ở khối Ghi chú.
                $tip = 'Nguồn: '.trim(($srcT !== '' ? $srcT : (string) $it->title).($host0 !== '' ? ' ('.$host0.')' : ''));
                $out .= '<sup class="autoseo-cite" data-kb="'.$id.'" title="'.e($tip).'"><a href="#nguon-'.$number[$id].'">['.$number[$id].']</a></sup>';
            }

            return $out;
        }, $html);
        if ($number === []) {
            return $html;
        }
        asort($number);
        $li = '';
        foreach (array_keys($number) as $id) {
            $it = $items[$id];
            $src = $it->source;
            $url = (string) ($src?->final_url ?: $src?->url);
            $host = preg_match('#^https?://#i', $url) ? (string) (parse_url($url, PHP_URL_HOST) ?: '') : '';
            $from = trim(($src ? (string) $src->title : '').($host !== '' ? ' ('.preg_replace('/^www\./', '', $host).')' : ''));
            $from = $host !== '' ? '<a href="'.e($url).'" rel="nofollow noopener">'.e($from).'</a>' : e($from);
            $li .= '<li id="nguon-'.$number[$id].'" data-kb="'.$id.'"><strong>'.e((string) $it->title).'</strong>'.($from !== '' ? ' — '.$from : '').'</li>';
        }

        return $html."\n".'<div class="autoseo-refs"><p><strong>Nguồn tham khảo</strong></p><ol>'.$li.'</ol></div>';
    }
}
