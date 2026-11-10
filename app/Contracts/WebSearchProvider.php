<?php

namespace App\Contracts;

use App\Services\Search\WebSearchUnavailable;

/** Nhà cung cấp tìm kiếm web (hiện là Tavily). Đổi nhà cung cấp bằng cách cài lớp khác và đổi config/search.php. */
interface WebSearchProvider
{
    /**
     * @param  array{max_results?: int, include_domains?: list<string>, exclude_domains?: list<string>}  $options
     * @return array{results: list<array{title: string, url: string, snippet: string, score?: float, published_at?: ?string}>, cost_vnd: float, provider: string}
     *
     * @throws WebSearchUnavailable khi không tìm được (lỗi mạng, khóa sai); thông báo đã ẩn khóa
     */
    public function search(string $query, array $options = []): array;
}
