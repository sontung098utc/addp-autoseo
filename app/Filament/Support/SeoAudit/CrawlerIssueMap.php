<?php

namespace App\Filament\Support\SeoAudit;

use App\Models\CrawledUrl;

/**
 * AG-K04: bảng ánh xạ mã lỗi của crawler (task 1.1, Dev1: HtmlSeoExtractor) sang danh mục lỗi của giao diện (IssueCatalog, P0/P1/P2).
 * Một bảng DUY NHẤT để cùng một URL cho cùng kết luận ở crawler và ở giao diện. Mã crawler chưa có trong danh mục
 * KHÔNG bị bỏ im lặng: được đếm riêng (xem DbSeoAuditSource) để hiển thị "chưa có trong danh mục".
 *
 * Thang điểm: điểm 0-100 của crawler (100 trừ phạt, trung bình theo URL = `url_average_v1`) KHÔNG được trộn với P0/P1/P2.
 */
final class CrawlerIssueMap
{
    public const SCORE_METHOD = 'url_average_v1';

    /** mã crawler (không kèm hậu tố ":N") => loại trong IssueCatalog. */
    public const MAP = [
        'missing_title' => 'missing_title',
        'title_too_short' => 'title_short',
        'title_too_long' => 'title_long',
        'missing_meta_description' => 'missing_meta',
        'missing_canonical_url' => 'missing_canonical',
        'missing_h1' => 'missing_h1',
        'multiple_h1' => 'multiple_h1',
        'missing_alt' => 'image_no_alt',
        'http_error_404' => 'broken_link',
    ];

    /** Mã crawler biết là có nhưng giao diện chưa có loại tương ứng (hiển thị riêng, không giả làm loại khác). */
    public const NO_CATALOG = [
        'meta_description_too_short' => 'Meta description quá ngắn',
        'meta_description_too_long' => 'Meta description quá dài',
        'missing_h2_subheadings' => 'Thiếu H2 chia mục',
        'missing_json_ld_schema' => 'Thiếu dữ liệu có cấu trúc JSON-LD',
    ];

    /** "missing_alt:3" => "missing_alt". */
    public static function base(string $code): string
    {
        return explode(':', $code, 2)[0];
    }

    /** Loại trong IssueCatalog hoặc null nếu chưa có (http_error_5xx, mã lạ...). */
    public static function type(string $code): ?string
    {
        $type = self::MAP[self::base($code)] ?? null;

        return $type !== null && IssueCatalog::exists($type) ? $type : null;
    }

    /** Nhãn cho mã chưa có trong danh mục. */
    public static function noCatalogLabel(string $code): string
    {
        $base = self::base($code);

        return self::NO_CATALOG[$base] ?? (str_starts_with($base, 'http_error_') ? 'Lỗi HTTP '.substr($base, 11) : $base);
    }

    /**
     * Phát hiện cho giao diện, bằng chứng LẤY TỪ DỮ LIỆU THẬT đã lưu (không bịa).
     *
     * @return array{type: string, url: string, measured: string, evidence: string}|null
     */
    public static function finding(string $code, CrawledUrl $row): ?array
    {
        $type = self::type($code);
        if ($type === null) {
            return null;
        }
        $suffix = str_contains($code, ':') ? explode(':', $code, 2)[1] : null;
        $title = (string) $row->title;
        $len = mb_strlen($title);

        [$measured, $evidence] = match ($type) {
            'missing_title' => ['không có title', 'Trang trả 200 nhưng không có thẻ <title> hoặc rỗng'],
            'title_short' => ["{$len} ký tự", '<title>'.$title.'</title>'],
            'title_long' => ["{$len} ký tự", '<title>'.$title.'</title>'],
            'missing_meta' => ['không có meta description', 'Không có hoặc rỗng meta description'],
            'missing_canonical' => ['không có canonical', 'Không có <link rel="canonical">'],
            'missing_h1' => ['không có H1', 'Không có thẻ h1'],
            'multiple_h1' => [$row->h1_count.' thẻ H1', 'Có '.$row->h1_count.' thẻ h1 trên một trang'],
            'image_no_alt' => [($suffix ?? $row->missing_alt_count).' ảnh thiếu alt', ($suffix ?? $row->missing_alt_count).' trên '.$row->images_count.' ảnh không có alt'],
            'broken_link' => ['HTTP '.$row->http_status, 'URL trong danh sách quét trả mã '.$row->http_status],
            default => null,
        };

        return $measured === null ? null : ['type' => $type, 'url' => $row->url, 'measured' => $measured, 'evidence' => $evidence];
    }
}
