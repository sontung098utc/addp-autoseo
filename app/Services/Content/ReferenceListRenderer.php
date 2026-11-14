<?php

namespace App\Services\Content;

/**
 * Khối "Nguồn tham khảo" cuối bài cho các TÀI LIỆU BẬC 1 người viết đã được cung cấp (ReferenceDossier): chỉ gồm tài liệu có thật, tìm được từ web
 * và nằm trong nhóm nguồn tin cậy. Không bịa liên kết: không có tài liệu thì không thêm gì. Nếu bài đã có khối nguồn từ Kho trí tuệ
 * (CitationRenderer) thì nối tiếp vào đúng khối đó, đánh số tiếp.
 */
final class ReferenceListRenderer
{
    /** Tiêu đề không có chữ cái tiếng Việt (dấu) và có chữ Latin: coi là tiếng nước ngoài, cần giải nghĩa. */
    public static function needsGloss(string $title): bool
    {
        return $title !== '' && preg_match('/[A-Za-z]{3,}\s+[A-Za-z]{2,}/', $title) === 1 && preg_match('/[àáảãạâầấẩẫậăằắẳẵặèéẻẽẹêềếểễệìíỉĩịòóỏõọôồốổỗộơờớởỡợùúủũụưừứửữựỳýỷỹỵđ]/iu', $title) !== 1;
    }

    /** @param list<array{title?: string, title_vi?: string, domain?: string, url?: string}> $references */
    public static function append(string $html, array $references): string
    {
        $items = [];
        foreach ($references as $r) {
            $url = (string) ($r['url'] ?? '');
            if (! preg_match('#^https?://#i', $url)) {
                continue;
            }
            $host = (string) (preg_replace('/^www\./', '', (string) parse_url($url, PHP_URL_HOST)) ?: ($r['domain'] ?? ''));
            $title = trim(strip_tags((string) ($r['title'] ?? '')));
            $vi = trim(strip_tags((string) ($r['title_vi'] ?? '')));
            $items[$url] = '<a href="'.e($url).'" rel="nofollow noopener">'.e($title !== '' ? $title : $host).'</a>'.($host !== '' ? ' — '.e($host) : '')
                .(self::needsGloss($title) && $vi !== '' ? ' — <em>'.e($vi).'</em>' : ''); // tiêu đề tiếng nước ngoài kèm giải nghĩa tiếng Việt
        }
        if ($html === '' || $items === []) {
            return $html;
        }
        $li = '';
        foreach ($items as $it) {
            $li .= '<li>'.$it.'</li>';
        }
        if (preg_match('#(<div class="autoseo-refs">.*?<ol>.*?)(</ol>)#su', $html)) {
            return (string) preg_replace('#(<div class="autoseo-refs">.*?)(</ol>)#su', '$1'.str_replace('$', '\$', $li).'$2', $html, 1);
        }

        return $html."\n".'<div class="autoseo-refs"><p><strong>Nguồn tham khảo</strong></p><ol>'.$li.'</ol></div>';
    }
}
