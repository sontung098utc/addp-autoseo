<?php

namespace App\Filament\Support\Gsc;

use Carbon\CarbonImmutable;

/**
 * Nguồn số liệu Search Console. Hiện có bản minh họa (GscDemoSource); khi Dev1 có OAuth + bảng gsc_metrics
 * thì thêm bản đọc DB/Google và đổi GscService::make(), giao diện KHÔNG đổi.
 */
interface GscSource
{
    /**
     * Các dòng theo NGÀY của một chiều. Mỗi dòng: date (Y-m-d), key, page (url trang; chiều truy vấn có,
     * chiều khác có thể null), clicks, impressions (int), position (float, trung bình ngày đó).
     *
     * @param  'query'|'page'|'device'|'country'  $dimension
     * @return list<array{date:string,key:string,page:?string,clicks:int,impressions:int,position:float}>
     */
    public function rows(string $dimension, CarbonImmutable $from, CarbonImmutable $to): array;

    /** Có dữ liệu thật nào không (nguồn rỗng -> "Chưa có dữ liệu"). */
    public function isEmpty(): bool;
}
