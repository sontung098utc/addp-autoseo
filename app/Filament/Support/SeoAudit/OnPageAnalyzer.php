<?php

namespace App\Filament\Support\SeoAudit;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Bộ phân tích SEO on-page THẬT trên chuỗi HTML đã thu thập. Không tải URL nào (không có I/O mạng).
 *
 * Đầu vào: danh sách trang ['url' => đường dẫn hoặc URL đầy đủ, 'status' => mã HTTP, 'html' => ?string, 'in_sitemap' => bool].
 * Đầu ra: danh sách phát hiện, mỗi phát hiện LUÔN có url + measured + evidence (không bằng chứng thì không ghi lỗi).
 *
 * @phpstan-type Page array{url: string, status?: int, html?: ?string, in_sitemap?: bool}
 * @phpstan-type Finding array{type: string, url: string, measured: string, evidence: string}
 */
final class OnPageAnalyzer
{
    /**
     * @param  list<array<string, mixed>>  $pages
     * @return list<array{type: string, url: string, measured: string, evidence: string}>
     */
    public function analyze(array $pages): array
    {
        $known = [];
        foreach ($pages as $p) {
            $known[self::normalizeUrl((string) ($p['url'] ?? ''))] = (int) ($p['status'] ?? 200);
        }

        $findings = [];
        $titles = [];
        $metas = [];

        foreach ($pages as $p) {
            $url = (string) ($p['url'] ?? '');
            if ($url === '' || (int) ($p['status'] ?? 200) !== 200) {
                continue; // chỉ phân tích trang trả 200
            }
            $html = (string) ($p['html'] ?? '');
            $f = $this->analyzePage($url, $html, (bool) ($p['in_sitemap'] ?? true), $known);
            foreach ($f['findings'] as $x) {
                $findings[] = $x;
            }
            if ($f['title'] !== '') {
                $titles[self::fold($f['title'])][] = ['url' => $url, 'value' => $f['title']];
            }
            if ($f['meta'] !== '') {
                $metas[self::fold($f['meta'])][] = ['url' => $url, 'value' => $f['meta']];
            }
        }

        foreach ([['duplicate_title', $titles, 'title'], ['duplicate_meta', $metas, 'meta description']] as [$type, $map, $name]) {
            foreach ($map as $group) {
                $urls = array_values(array_unique(array_column($group, 'url')));
                if (count($urls) < 2) {
                    continue; // một trang không tự trùng với chính nó
                }
                foreach ($urls as $u) {
                    $others = array_values(array_diff($urls, [$u]));
                    $findings[] = [
                        'type' => $type,
                        'url' => $u,
                        'measured' => count($urls).' trang cùng '.$name,
                        'evidence' => '"'.$group[0]['value'].'" — trùng với '.implode(', ', array_slice($others, 0, 3)).(count($others) > 3 ? ' và '.(count($others) - 3).' trang khác' : ''),
                    ];
                }
            }
        }

        return $findings;
    }

    /**
     * @param  array<string, int>  $known
     * @return array{findings: list<array{type: string, url: string, measured: string, evidence: string}>, title: string, meta: string}
     */
    private function analyzePage(string $url, string $html, bool $inSitemap, array $known): array
    {
        $out = [];
        $add = function (string $type, string $measured, string $evidence) use (&$out, $url): void {
            $out[] = ['type' => $type, 'url' => $url, 'measured' => $measured, 'evidence' => $evidence];
        };

        $xp = $this->parse($html);
        if ($xp === null) {
            $add('missing_title', 'nội dung rỗng', '(phản hồi 200 nhưng không có nội dung HTML)');
            $add('thin_page', '0 từ', '(phản hồi 200 nhưng không có nội dung HTML)');

            return ['findings' => $out, 'title' => '', 'meta' => ''];
        }

        // Title
        $titleNode = $xp->query('//head/title | //title')->item(0);
        $title = $titleNode ? self::clean($titleNode->textContent) : '';
        if ($title === '') {
            $add('missing_title', $titleNode ? 'title rỗng' : 'không có thẻ title', $titleNode ? '<title></title>' : 'Không tìm thấy thẻ <title> trong <head>');
        } else {
            $len = mb_strlen($title);
            if ($len > IssueCatalog::MAX_TITLE) {
                $add('title_long', "{$len} ký tự", '<title>'.$title.'</title>');
            } elseif ($len < IssueCatalog::MIN_TITLE) {
                $add('title_short', "{$len} ký tự", '<title>'.$title.'</title>');
            }
        }

        // Meta description, canonical, robots
        $meta = '';
        $metaNode = null;
        foreach ($xp->query('//meta[@name]') as $m) {
            if ($m instanceof DOMElement && strtolower($m->getAttribute('name')) === 'description') {
                $metaNode = $m;
                $meta = self::clean($m->getAttribute('content'));
                break;
            }
        }
        if ($meta === '') {
            $add('missing_meta', $metaNode ? 'meta description rỗng' : 'không có meta description', $metaNode ? '<meta name="description" content="">' : 'Không tìm thấy <meta name="description"> trong <head>');
        }

        $hasCanonical = false;
        foreach ($xp->query('//link[@rel]') as $l) {
            if ($l instanceof DOMElement && in_array('canonical', preg_split('/\s+/', strtolower($l->getAttribute('rel'))) ?: [], true) && trim($l->getAttribute('href')) !== '') {
                $hasCanonical = true;
                break;
            }
        }
        if (! $hasCanonical) {
            $add('missing_canonical', 'không có canonical', 'Không tìm thấy <link rel="canonical" href="..."> trong <head>');
        }

        foreach ($xp->query('//meta[@name]') as $m) {
            if ($m instanceof DOMElement && strtolower($m->getAttribute('name')) === 'robots' && str_contains(strtolower($m->getAttribute('content')), 'noindex')) {
                if ($inSitemap) {
                    $add('noindex_in_sitemap', 'noindex, có trong sitemap', '<meta name="robots" content="'.$m->getAttribute('content').'">');
                }
                break;
            }
        }

        // H1
        $h1 = $xp->query('//h1');
        if ($h1->length === 0) {
            $add('missing_h1', '0 thẻ h1', 'Không tìm thấy thẻ <h1> trong trang');
        } elseif ($h1->length > 1) {
            $add('multiple_h1', $h1->length.' thẻ h1', implode(' | ', array_map(fn ($n) => '<h1>'.self::clean($n->textContent).'</h1>', iterator_to_array($h1))));
        }

        // Ảnh thiếu alt (alt="" là ảnh trang trí hợp lệ)
        $noAlt = [];
        foreach ($xp->query('//img') as $img) {
            if ($img instanceof DOMElement && ! $img->hasAttribute('alt')) {
                $noAlt[] = '<img src="'.$img->getAttribute('src').'">';
            }
        }
        if ($noAlt) {
            $add('image_no_alt', count($noAlt).' ảnh thiếu alt', implode("\n", array_slice($noAlt, 0, 3)));
        }

        // Trang mỏng
        $body = $xp->query('//body')->item(0);
        $words = $body ? $this->countWords($body) : 0;
        if ($words < IssueCatalog::MIN_WORDS) {
            $add('thin_page', "{$words} từ", "Nội dung văn bản chỉ có {$words} từ (ngưỡng ".IssueCatalog::MIN_WORDS.')');
        }

        // Liên kết nội bộ gãy: đích nằm trong tập URL đã thu thập và trả 404
        $seen = [];
        foreach ($xp->query('//a[@href]') as $a) {
            if (! $a instanceof DOMElement) {
                continue;
            }
            $target = self::resolve($url, $a->getAttribute('href'));
            if ($target === null || isset($seen[$target])) {
                continue;
            }
            if (($known[$target] ?? null) === 404) {
                $seen[$target] = true;
                $add('broken_link', $target, '<a href="'.$a->getAttribute('href').'">'.self::clean($a->textContent).'</a> → trả 404');
            }
        }

        return ['findings' => $out, 'title' => $title, 'meta' => $meta];
    }

    private function parse(string $html): ?DOMXPath
    {
        if (trim($html) === '') {
            return null;
        }
        $prev = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument();
            $ok = $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
            if (! $ok) {
                return null;
            }

            return new DOMXPath($doc);
        } catch (\Throwable) {
            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    private function countWords(\DOMNode $body): int
    {
        $text = '';
        $walk = function (\DOMNode $n) use (&$walk, &$text): void {
            foreach ($n->childNodes as $c) {
                if ($c instanceof DOMElement && in_array(strtolower($c->tagName), ['script', 'style', 'noscript'], true)) {
                    continue;
                }
                if ($c->nodeType === XML_TEXT_NODE) {
                    $text .= ' '.$c->nodeValue;
                } else {
                    $walk($c);
                }
            }
        };
        $walk($body);
        $parts = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? 0 : count($parts);
    }

    private static function clean(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    private static function fold(string $s): string
    {
        return mb_strtolower(self::clean($s));
    }

    /** Chuẩn hóa URL để so khớp: bỏ fragment, bỏ "/" cuối (trừ gốc), giữ đường dẫn (+ host nếu có). */
    public static function normalizeUrl(string $url): string
    {
        $url = preg_replace('/#.*$/', '', trim($url)) ?? $url;
        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }
        $path = rtrim($parts['path'] ?? '/', '/');
        $path = $path === '' ? '/' : $path;

        return isset($parts['host']) ? strtolower($parts['scheme'] ?? 'https').'://'.strtolower($parts['host']).$path : $path;
    }

    /** Ghép href với URL trang. Trả null cho mailto/tel/javascript/liên kết ngoài/neo. */
    public static function resolve(string $pageUrl, string $href): ?string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#') || preg_match('/^(mailto|tel|javascript|data):/i', $href)) {
            return null;
        }
        $page = parse_url($pageUrl) ?: [];
        if (preg_match('#^https?://#i', $href) || str_starts_with($href, '//')) {
            $h = parse_url(str_starts_with($href, '//') ? 'https:'.$href : $href);
            if (isset($page['host']) && strtolower($h['host'] ?? '') !== strtolower($page['host'])) {
                return null; // liên kết ngoài
            }
            if (! isset($page['host'])) {
                return null;
            }

            return self::normalizeUrl($href);
        }
        $href = preg_replace('/[?#].*$/', '', $href) ?? $href;
        if (! str_starts_with($href, '/')) {
            $dir = rtrim(dirname($page['path'] ?? '/'), '/');
            $href = $dir.'/'.$href;
        }
        $segs = [];
        foreach (explode('/', $href) as $s) {
            if ($s === '..') {
                array_pop($segs);
            } elseif ($s !== '.' && $s !== '') {
                $segs[] = $s;
            }
        }
        $path = '/'.implode('/', $segs);

        return isset($page['host']) ? self::normalizeUrl(strtolower($page['scheme'] ?? 'https').'://'.strtolower($page['host']).$path) : self::normalizeUrl($path);
    }
}
