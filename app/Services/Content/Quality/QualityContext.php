<?php

namespace App\Services\Content\Quality;

use App\Models\Article;
use App\Models\ArticleVersion;
use DOMDocument;
use DOMXPath;

/** Dữ liệu đã phân tích của một phiên bản, tính một lần dùng chung cho mọi luật. HTML hỏng không ném lỗi. */
class QualityContext
{
    private ?DOMDocument $dom = null;
    private ?string $text = null;
    /** @var list<array{level:int,text:string}>|null */
    private ?array $headings = null;

    public function __construct(public readonly ArticleVersion $version, public readonly ?Article $article)
    {
    }

    public function html(): string
    {
        return (string) $this->version->html_content;
    }

    public function dom(): DOMDocument
    {
        if ($this->dom === null) {
            $prev = libxml_use_internal_errors(true);
            $dom = new DOMDocument('1.0', 'UTF-8');
            // Không dùng LIBXML_NOENT/DTDLOAD: tránh XXE.
            $dom->loadHTML('<?xml encoding="UTF-8"><div id="ss-root">' . $this->html() . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            $this->dom = $dom;
        }

        return $this->dom;
    }

    public function xpath(): DOMXPath
    {
        return new DOMXPath($this->dom());
    }

    /** Văn bản hiển thị (bỏ script/style), đã chuẩn hóa khoảng trắng. */
    public function text(): string
    {
        if ($this->text === null) {
            $parts = [];
            foreach ($this->xpath()->query('//text()[not(ancestor::script) and not(ancestor::style)]') ?: [] as $n) {
                $parts[] = $n->nodeValue;
            }
            $this->text = self::normalize(implode(' ', $parts));
        }

        return $this->text;
    }

    /** @return list<string> */
    public function words(): array
    {
        return $this->text() === '' ? [] : explode(' ', $this->text());
    }

    /** @return list<array{level:int,text:string}> theo thứ tự xuất hiện */
    public function headings(): array
    {
        if ($this->headings === null) {
            $this->headings = [];
            foreach ($this->xpath()->query('//h1|//h2|//h3|//h4|//h5|//h6') ?: [] as $h) {
                $this->headings[] = ['level' => (int) substr($h->nodeName, 1), 'text' => self::normalize($h->textContent)];
            }
        }

        return $this->headings;
    }

    /** Từ khóa chính: của phiên bản, nếu trống thì của bài. */
    public function keyword(): string
    {
        return self::normalize((string) ($this->version->focus_keyword ?: $this->article?->primary_keyword ?: ''));
    }

    /** Chữ thường (UTF-8), gộp khoảng trắng. Giữ dấu tiếng Việt. */
    public static function normalize(string $s): string
    {
        if (class_exists(\Normalizer::class)) {
            $s = \Normalizer::normalize($s, \Normalizer::FORM_C) ?: $s;
        }

        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($s)) ?? '');
    }

    public static function excerpt(string $s, int $max = 140): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');

        return mb_strlen($s) > $max ? mb_substr($s, 0, $max) . '…' : $s;
    }
}
