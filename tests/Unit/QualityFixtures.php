<?php

namespace Tests\Unit;

use App\Models\Article;
use App\Models\ArticleVersion;

/** Dữ liệu dựng sẵn cho test chất lượng: bài "tốt" qua mọi luật bắt buộc. */
class QualityFixtures
{
    private const LEX = ['sinh', 'viên', 'trường', 'đại', 'học', 'ngành', 'tuyển', 'chương', 'trình', 'kỳ', 'năng', 'lực', 'điều', 'kiện',
        'hồ', 'sơ', 'thời', 'gian', 'đăng', 'ký', 'xét', 'kết', 'quả', 'phỏng', 'vấn', 'nhà', 'quản', 'lý', 'tư', 'vấn', 'cơ', 'hội',
        'việc', 'làm', 'doanh', 'nghiệp', 'thực', 'tập', 'kỹ', 'thuật', 'công', 'nghệ', 'thông', 'tin', 'ngoại', 'ngữ', 'giảng', 'viên'];

    /** n từ giả lập nhưng xác định theo seed (các seed khác nhau gần như không trùng cụm 5 từ). */
    public static function words(int $n, int $seed = 1): string
    {
        $x = $seed * 7919 + 13;
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $x = ($x * 1103515245 + 12345) & 0x7fffffff;
            $out[] = self::LEX[($x >> 8) % count(self::LEX)];
        }

        return implode(' ', $out);
    }

    /** HTML đạt mọi luật cho từ khóa $kw: ~$n từ, 3 H2 (một H2 chứa từ khóa), từ khóa ở đoạn mở đầu. */
    public static function goodHtml(string $kw = 'học bổng toàn phần', int $n = 900, int $seed = 1): string
    {
        $per = intdiv($n - 40, 3);

        return "<h2>Tổng quan về {$kw}</h2><p>Bài viết này giải thích {$kw} cho sinh viên. " . self::words($per, $seed) . '</p>'
            . '<h2>Điều kiện xét tuyển</h2><p>' . self::words($per, $seed + 1) . '</p>'
            . '<h2>Hồ sơ và thời gian</h2><p>' . self::words($per, $seed + 2) . '</p>';
    }

    /** Thuộc tính phiên bản tốt (seo_title ≤ 60, meta 120–160, slug hợp lệ). */
    public static function goodAttrs(string $kw = 'học bổng toàn phần', int $n = 900, int $seed = 1): array
    {
        return [
            'title' => "Tìm hiểu {$kw}",
            'slug' => 'hoc-bong-toan-phan',
            'html_content' => self::goodHtml($kw, $n, $seed),
            'seo_title' => "{$kw}: điều kiện và hồ sơ",
            'meta_description' => str_pad("Hướng dẫn {$kw} cho sinh viên: điều kiện xét tuyển, hồ sơ cần chuẩn bị và thời gian đăng ký chi tiết, dễ hiểu", 130, '.'),
            'focus_keyword' => $kw,
        ];
    }

    public static function version(Article $a, int $v, string $status, array $attrs = []): ArticleVersion
    {
        $kw = $a->primary_keyword;

        return ArticleVersion::create(array_merge(self::goodAttrs($kw), ['article_id' => $a->id, 'version' => $v, 'review_status' => $status], $attrs));
    }
}
