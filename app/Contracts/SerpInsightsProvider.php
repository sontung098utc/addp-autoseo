<?php

namespace App\Contracts;

use App\Services\Keywords\Serp\SerpFailed;

/**
 * Nguồn dữ liệu kết quả tìm kiếm THẬT cho một từ khóa. Mỗi mục mang nhãn nguồn; không có dữ liệu thì để mảng rỗng (không bao giờ điền giả).
 */
interface SerpInsightsProvider
{
    /**
     * @param  array<string,mixed>  $options  location_code, language_code
     * @return array{
     *   paa: list<string>, related: list<string>, autocomplete: list<string>,
     *   organic: list<array{rank:int, url:string, title:string, domain:string}>,
     *   provider: string, cost_vnd: float, fetched_at: string
     * }
     *
     * @throws SerpFailed khi nhà cung cấp lỗi (thông báo đã ẩn thông tin đăng nhập)
     */
    public function insights(string $keyword, array $options = []): array;

    /** Nhãn nguồn: 'dataforseo' | 'autocomplete' (khớp nhãn trong ContentPlanner/đề xuất). */
    public function name(): string;
}
