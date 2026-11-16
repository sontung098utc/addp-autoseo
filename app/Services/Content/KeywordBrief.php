<?php

namespace App\Services\Content;

use App\Models\Article;
use App\Services\Keywords\KeywordResearchService;
use App\Services\Keywords\Serp\SerpInsights;
use App\Services\Keywords\Serp\SerpUnavailable;

/**
 * Bản tóm tắt từ khóa cho một bài: từ khóa phụ do người đặt + câu hỏi liên quan và cụm liên quan lấy từ SERP/gợi ý tự động THẬT.
 * Mỗi mục có nhãn nguồn. Không có nguồn SERP thì chỉ còn từ khóa phụ của người đặt và ghi rõ "chưa có dữ liệu", không bịa câu hỏi.
 * Nội dung lấy từ ngoài chỉ là DỮ LIỆU: lọc ký tự điều khiển, độ dài, câu giống chỉ dẫn tiêm; không cho điều khiển công cụ.
 */
final class KeywordBrief
{
    public const MAX_SECONDARY = 8;

    public const MAX_QUESTIONS = 6;

    public const MAX_RELATED = 8;

    public function __construct(private readonly ?SerpInsights $serp = null) {}

    /**
     * @return array{status:string, note:string, secondary:list<string>, questions:list<array{text:string,source:string}>, related:list<array{text:string,source:string}>, providers:list<string>, errors:list<string>, cost_vnd:float}
     *   status: ok (có dữ liệu SERP) | partial (có SERP nhưng một nguồn lỗi) | blocked (chưa có nguồn SERP) | none (không có gì để dùng)
     */
    public function build(Article $article): array
    {
        $kw = trim((string) $article->primary_keyword);
        $secondary = self::clean((array) $article->secondary_keywords, self::MAX_SECONDARY, $kw);
        $out = ['status' => 'none', 'note' => '', 'secondary' => $secondary, 'questions' => [], 'related' => [], 'providers' => [], 'errors' => [], 'cost_vnd' => 0.0];
        if ($kw === '') {
            return $out;
        }
        try {
            $r = ($this->serp ?? SerpInsights::fromConfig())->fetch($kw);
        } catch (SerpUnavailable $e) {
            $out['status'] = 'blocked';
            $out['note'] = $e->getMessage();

            return $out;
        } catch (\Throwable $e) {
            report($e);
            $out['status'] = 'blocked';
            $out['note'] = 'Không lấy được dữ liệu SERP: '.mb_substr($e->getMessage(), 0, 160);

            return $out;
        }
        $seen = array_map('mb_strtolower', array_merge([$kw], $secondary));
        $out['questions'] = self::labelled(self::clean($r['paa'], self::MAX_QUESTIONS, $kw, $seen), 'serp');
        $rel = array_merge(self::labelled(self::clean($r['related'], self::MAX_RELATED, $kw, $seen), 'serp'), self::labelled(self::clean($r['autocomplete'], self::MAX_RELATED, $kw, $seen), 'autocomplete'));
        $out['related'] = array_slice(self::dedupe($rel), 0, self::MAX_RELATED);
        $out['providers'] = $r['providers'];
        $out['errors'] = $r['errors'];
        $out['cost_vnd'] = (float) $r['cost_vnd'];
        $out['status'] = $r['errors'] !== [] ? 'partial' : 'ok';
        if ($out['questions'] === [] && $out['related'] === [] && $secondary === []) {
            $out['status'] = 'none';
            $out['note'] = 'Nguồn SERP không trả câu hỏi hay cụm liên quan cho từ khóa này.';
        }

        return $out;
    }

    /** @return bool có gì để đưa vào lời nhắc */
    public static function hasContent(array $brief): bool
    {
        return ($brief['secondary'] ?? []) !== [] || ($brief['questions'] ?? []) !== [] || ($brief['related'] ?? []) !== [];
    }

    /**
     * Từ khóa phụ nào của bài đã xuất hiện trong nội dung (không phân biệt hoa thường; so theo chữ thường đã bỏ thẻ HTML).
     *
     * @param  list<string>  $secondary
     * @return array{used:list<string>, missing:list<string>}
     */
    public static function coverage(string $html, array $secondary): array
    {
        $text = mb_strtolower(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $used = $missing = [];
        foreach ($secondary as $s) {
            (str_contains($text, mb_strtolower($s)) ? $used[] = $s : $missing[] = $s);
        }

        return ['used' => $used, 'missing' => $missing];
    }

    /**
     * @param  list<mixed>  $items
     * @param  list<string>  $exclude  chữ thường
     * @return list<string>
     */
    private static function clean(array $items, int $max, string $primary, array $exclude = []): array
    {
        $out = [];
        $seen = array_flip(array_merge($exclude, [mb_strtolower($primary)]));
        foreach ($items as $v) {
            $t = trim(preg_replace('/[\x00-\x1F\x7F]+|\s+/u', ' ', strip_tags((string) $v)) ?? '');
            $k = mb_strtolower($t);
            if ($t === '' || mb_strlen($t) > KeywordResearchService::KEYWORD_MAX || isset($seen[$k]) || preg_match(KeywordResearchService::INJECTION, $t)) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $t;
            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    /** @param list<string> $texts @return list<array{text:string,source:string}> */
    private static function labelled(array $texts, string $source): array
    {
        return array_map(fn (string $t) => ['text' => $t, 'source' => $source], $texts);
    }

    /** @param list<array{text:string,source:string}> $rows @return list<array{text:string,source:string}> */
    private static function dedupe(array $rows): array
    {
        $seen = [];
        $out = [];
        foreach ($rows as $r) {
            $k = mb_strtolower($r['text']);
            if (! isset($seen[$k])) {
                $seen[$k] = true;
                $out[] = $r;
            }
        }

        return $out;
    }
}
