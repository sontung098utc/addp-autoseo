<?php

namespace App\Filament\Support\Article;

use App\Filament\Support\Review\ReviewActions;
use App\Models\ArticleVersion;
use Illuminate\Support\Str;

/**
 * So sánh hai phiên bản của một bài: thẻ meta và từng mục theo tiêu đề H2. Thuần PHP, tất định, không AI.
 * Chưa so từng câu: mục "đã sửa" là mục cùng tiêu đề nhưng chữ khác.
 */
class VersionDiff
{
    public const INTRO = '(Mở đầu)';

    public const META = ['title' => 'Tiêu đề', 'seo_title' => 'Tiêu đề SEO', 'meta_description' => 'Mô tả meta', 'slug' => 'Đường dẫn', 'focus_keyword' => 'Từ khóa chính'];

    /** @return array<string, mixed> */
    public static function between(ArticleVersion $old, ArticleVersion $new): array
    {
        $meta = [];
        foreach (self::META as $field => $label) {
            $o = self::norm($old->{$field});
            $n = self::norm($new->{$field});
            if ($o !== $n) {
                $meta[] = ['field' => $field, 'label' => $label, 'old' => $o, 'new' => $n];
            }
        }

        $oldSections = self::sections((string) $old->html_content);
        $newSections = self::sections((string) $new->html_content);
        $sections = [];
        $used = [];
        foreach ($newSections as $key => $s) {
            if (isset($oldSections[$key])) {
                $used[$key] = true;
                $o = $oldSections[$key];
                $same = self::plain($o['html']) === self::plain($s['html']);
                $sections[] = ['status' => $same ? 'same' : 'changed', 'heading' => $s['heading'], 'old_words' => self::words($o['html']), 'new_words' => self::words($s['html']), 'old_html' => $o['html'], 'new_html' => $s['html']];
            } else {
                $sections[] = ['status' => 'added', 'heading' => $s['heading'], 'old_words' => 0, 'new_words' => self::words($s['html']), 'old_html' => null, 'new_html' => $s['html']];
            }
        }
        foreach ($oldSections as $key => $o) {
            if (! isset($used[$key])) {
                $sections[] = ['status' => 'removed', 'heading' => $o['heading'], 'old_words' => self::words($o['html']), 'new_words' => 0, 'old_html' => $o['html'], 'new_html' => null];
            }
        }

        $count = fn (string $st) => count(array_filter($sections, fn ($s) => $s['status'] === $st));

        return [
            'meta' => $meta,
            'sections' => $sections,
            'summary' => [
                'meta_changed' => count($meta),
                'sections_changed' => $count('changed'),
                'sections_added' => $count('added'),
                'sections_removed' => $count('removed'),
                'words_old' => self::words((string) $old->html_content),
                'words_new' => self::words((string) $new->html_content),
                'quality_old' => ReviewActions::qualityFor($old)->counts(),
                'quality_new' => ReviewActions::qualityFor($new)->counts(),
            ],
        ];
    }

    /** Chữ thuần của HTML, cắt gọn để hiển thị an toàn (view vẫn phải thoát ký tự). */
    public static function excerpt(?string $html, int $max = 600): string
    {
        return Str::limit(self::plain((string) $html), $max);
    }

    private static function norm(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : null;

        return $v === '' ? null : $v;
    }

    private static function plain(string $html): string
    {
        $text = strip_tags(preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html);

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    private static function words(string $html): int
    {
        $p = self::plain($html);

        return $p === '' ? 0 : count(preg_split('/\s+/u', $p, -1, PREG_SPLIT_NO_EMPTY));
    }

    private static function key(string $heading): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $heading) ?? ''));
    }

    /** @return array<string, array{heading:string, html:string}> khóa = tiêu đề chuẩn hóa; tiêu đề trùng được đánh số để không mất mục. */
    private static function sections(string $html): array
    {
        $parts = preg_split('/(<h2\b[^>]*>.*?<\/h2>)/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        $add = function (string $heading, string $body) use (&$out) {
            $key = self::key($heading);
            for ($i = 2; isset($out[$key]); $i++) {
                $key = self::key($heading).'#'.$i;
            }
            $out[$key] = ['heading' => $heading, 'html' => $body];
        };
        $heading = null;
        $buf = '';
        foreach ($parts as $part) {
            if (preg_match('/^<h2\b/i', $part)) {
                if ($heading !== null || self::plain($buf) !== '') {
                    $add($heading ?? self::INTRO, $buf);
                }
                $heading = self::plain($part);
                $buf = '';
            } else {
                $buf .= $part;
            }
        }
        if ($heading !== null || self::plain($buf) !== '') {
            $add($heading ?? self::INTRO, $buf);
        }

        return $out;
    }
}
