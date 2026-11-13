<?php

namespace App\Services\Content;

/**
 * Khối "Nguồn tham khảo" cuối bài cho các TÀI LIỆU BẬC 1 người viết đã được cung cấp (ReferenceDossier): chỉ gồm tài liệu có thật, tìm được từ web
 * và nằm trong nhóm nguồn tin cậy. Không bịa liên kết: không có tài liệu thì không thêm gì. Nếu bài đã có khối nguồn từ Kho trí tuệ
 * (CitationRenderer) thì nối tiếp vào đúng khối đó, đánh số tiếp.
 */
final class ReferenceListRenderer
{
    /** @param list<array{title?: string, domain?: string, url?: string}> $references */
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
            $items[$url] = '<a href="'.e($url).'" rel="nofollow noopener">'.e($title !== '' ? $title : $host).'</a>'.($host !== '' ? ' — '.e($host) : '');
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
