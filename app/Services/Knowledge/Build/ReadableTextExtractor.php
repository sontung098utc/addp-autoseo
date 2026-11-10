<?php

namespace App\Services\Knowledge\Build;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * G2: HTML → văn bản đọc được. Bỏ script/style/nav/header/footer/aside/menu/form; giữ tiêu đề và đoạn; bảng thành dòng "ô | ô".
 * `header` nằm trong article/main (tiêu đề bài) được giữ. Tất định: cùng HTML cho cùng kết quả. Không gọi mạng, không AI.
 * Trả {title, text, headings}.
 */
final class ReadableTextExtractor
{
    private const DROP = ['script', 'style', 'noscript', 'nav', 'footer', 'aside', 'menu', 'form', 'svg', 'iframe', 'template', 'select', 'button'];

    private const BLOCK = ['p', 'div', 'section', 'article', 'main', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'br', 'blockquote', 'pre', 'dl', 'dt', 'dd', 'figure', 'figcaption', 'table', 'tr', 'hr', 'details', 'summary', 'address'];

    public const MAX_TEXT = 200000;

    /** @return array{title: string, text: string, headings: list<string>} */
    public function extract(string $html): array
    {
        $empty = ['title' => '', 'text' => '', 'headings' => []];
        if (trim($html) === '') {
            return $empty;
        }
        $dom = new DOMDocument;
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $xp = new DOMXPath($dom);
        $title = $this->clean((string) ($xp->query('//title')->item(0)?->textContent ?? ''));

        foreach (self::DROP as $tag) {
            $this->remove($xp->query('//'.$tag));
        }
        $this->remove($xp->query('//header[not(ancestor::article) and not(ancestor::main)]'));
        $this->remove($xp->query('//comment()'));
        $this->remove($xp->query('//head'));

        $headings = [];
        foreach ($xp->query('//h1|//h2|//h3') as $h) {
            $t = $this->clean($h->textContent);
            if ($t !== '' && count($headings) < 30 && ! in_array($t, $headings, true)) {
                $headings[] = $t;
            }
        }
        if ($title === '' && $headings !== []) {
            $title = $headings[0];
        }

        $body = $xp->query('//body')->item(0) ?? $dom->documentElement;
        $buf = '';
        if ($body) {
            $this->walk($body, $buf);
        }
        $lines = [];
        $last = null;
        foreach (explode("\n", $buf) as $l) {
            $l = $this->clean($l);
            if ($l === '' || $l === $last) {
                continue;
            }
            $lines[] = $last = $l;
        }

        return ['title' => mb_substr($title, 0, 200), 'text' => mb_substr(implode("\n", $lines), 0, self::MAX_TEXT), 'headings' => $headings];
    }

    private function walk(DOMNode $n, string &$buf): void
    {
        foreach ($n->childNodes as $c) {
            if ($c instanceof DOMElement) {
                $tag = strtolower($c->tagName);
                if ($tag === 'tr') {
                    $cells = [];
                    foreach ($c->childNodes as $cell) {
                        if ($cell instanceof DOMElement && in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                            $t = $this->clean($cell->textContent);
                            if ($t !== '') {
                                $cells[] = $t;
                            }
                        }
                    }
                    $buf .= "\n".implode(' | ', $cells)."\n";

                    continue;
                }
                $block = in_array($tag, self::BLOCK, true);
                $block && $buf .= "\n";
                $this->walk($c, $buf);
                $block && $buf .= "\n";
            } elseif ($c->nodeType === XML_TEXT_NODE || $c->nodeType === XML_CDATA_SECTION_NODE) {
                $buf .= preg_replace('/\s+/u', ' ', (string) $c->nodeValue);
            }
        }
    }

    private function remove(\DOMNodeList|false $nodes): void
    {
        if ($nodes === false) {
            return;
        }
        foreach (iterator_to_array($nodes) as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    private function clean(string $s): string
    {
        $s = str_replace("\u{00A0}", ' ', $s);
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? $s;

        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }
}
