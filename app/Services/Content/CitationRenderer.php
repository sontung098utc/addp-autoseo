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

    /**
     * Người viết đôi khi gộp mã kho và mã tài liệu web trong một ngoặc ("[KB#976, T2]"): hai bộ đổi dấu chỉ hiểu từng loại riêng nên dấu gộp lọt nguyên văn vào bài giao. Tách thành "[KB#976][T2]".
     */
    public static function splitMixed(string $html): string
    {
        return MarkupText::replaceInText('/\[((?:KB#\d+|T\d+)(?:\s*[,;]\s*(?:KB#\d+|T\d+))+)\]/u', function (array $m): string {
            if (! preg_match('/(?<![A-Z])T\d/', $m[1]) || ! str_contains($m[1], 'KB#')) {
                return $m[0]; // chỉ tách dấu pha trộn kho + web; nhóm thuần một loại để bộ đổi dấu cũ xử lý
            }

            return implode('', array_map(fn ($x) => '['.trim($x).']', preg_split('/\s*[,;]\s*/', $m[1]) ?: []));
        }, $html);
    }

    /** @param array<int,string> $titlesVi giải nghĩa tiếng Việt cho tiêu đề nguồn tiếng nước ngoài, theo id mục kho */
    public static function render(string $html, array $titlesVi = []): string
    {
        $html = self::splitMixed($html);
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
        $srcNo = []; // một NGUỒN (địa chỉ) chỉ một số: nhiều mục kho cùng một trang không được liệt kê lặp
        $html = MarkupText::replaceInText(self::MARK, function (array $m) use ($items, &$number, &$srcNo): string {
            $out = '';
            foreach (preg_split('/[^\d]+/', $m[1], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $n) {
                $id = (int) $n;
                if (! $items->has($id)) {
                    return $m[0];
                }
                $it = $items[$id];
                $key = (string) ($it->source?->final_url ?: $it->source?->url) ?: 'src'.($it->source_id ?: 'item'.$id);
                $srcNo[$key] ??= count($srcNo) + 1;
                $number[$id] = $srcNo[$key];
                $url0 = (string) ($it->source?->final_url ?: $it->source?->url);
                $host0 = preg_match('#^https?://#i', $url0) ? preg_replace('/^www\./', '', (string) parse_url($url0, PHP_URL_HOST)) : '';
                $srcT = $it->source ? ReferenceListRenderer::readableTitle((string) $it->source->title, (string) $host0) : '';
                // Rê chuột vào số chỉ cho biết NGUỒN (không nhắc lại nội dung câu ở thân bài); bấm để nhảy tới dòng nguồn cuối bài. Định nghĩa/khái niệm bổ sung nằm ở khối Ghi chú.
                $tip = 'Nguồn: '.trim($srcT !== '' ? $srcT.($host0 !== '' ? ' ('.$host0.')' : '').' — '.(string) $it->title : (string) $it->title);
                $out .= '<sup class="autoseo-cite" data-kb="'.$id.'" title="'.e($tip).'"><a href="#nguon-'.$number[$id].'">['.$number[$id].']</a></sup>';
            }

            return $out;
        }, $html);
        if ($number === []) {
            return $html;
        }
        asort($number);
        $li = '';
        $listed = [];
        foreach (array_keys($number) as $id) {
            if (isset($listed[$number[$id]])) {
                continue; // đã có dòng cho nguồn này
            }
            $listed[$number[$id]] = true;
            $it = $items[$id];
            $src = $it->source;
            $url = (string) ($src?->final_url ?: $src?->url);
            $host = preg_match('#^https?://#i', $url) ? (string) (parse_url($url, PHP_URL_HOST) ?: '') : '';
            $hostShown = preg_replace('/^www\./', '', $host);
            $srcTitle = $src ? ReferenceListRenderer::readableTitle((string) $src->title, (string) $hostShown) : ''; // tiêu đề trích sai thì dùng tên miền, không để chữ rác
            $from = trim($srcTitle.($host !== '' ? ' ('.$hostShown.')' : ''));
            $from = $host !== '' ? '<a href="'.e($url).'" rel="nofollow noopener">'.e($from).'</a>' : e($from);
            $li .= '<li id="nguon-'.$number[$id].'" data-kb="'.$id.'">'.($src && $srcTitle !== '' ? $from : '<strong>'.e((string) $it->title).'</strong>'.($from !== '' ? ' — '.$from : '')).(isset($titlesVi[$id]) && $src && ReferenceListRenderer::needsGloss($srcTitle) ? ' — <em>'.e($titlesVi[$id]).'</em>' : '').'</li>'; // tên trang nguồn (không phải câu trích do AI rút ra)
        }

        return $html."\n".'<div class="autoseo-refs"><p><strong>Nguồn tham khảo</strong></p><ol>'.$li.'</ol></div>';
    }
}
