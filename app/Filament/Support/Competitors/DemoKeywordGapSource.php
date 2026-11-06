<?php

namespace App\Filament\Support\Competitors;

use App\Filament\Support\Demo\CompetitorsDemoData;

/** Nguồn MINH HỌA: thứ hạng giả lập xác định, không gọi mạng. $failing mô phỏng lỗi/hết hạn mức từ nhà cung cấp. */
final class DemoKeywordGapSource implements KeywordGapSource
{
    public const ROWS_PER_DOMAIN = 1000;

    public function __construct(private readonly bool $failing = false) {}

    public function estimateRows(int $domainCount): int
    {
        return $domainCount * self::ROWS_PER_DOMAIN;
    }

    public function fetch(array $domains): array
    {
        if ($this->failing) {
            throw new SourceUnavailableException('Nhà cung cấp từ khóa (minh họa) từ chối yêu cầu: mã 429, hết hạn mức.');
        }

        return CompetitorsDemoData::rankings($domains);
    }
}
