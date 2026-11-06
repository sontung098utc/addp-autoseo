<?php

namespace App\Filament\Support\Competitors;

/**
 * Nguồn thứ hạng từ khóa của đối thủ. Hiện chỉ có bản minh họa; khi chốt nhà cung cấp API từ khóa trả phí chỉ cần
 * thêm một lớp triển khai interface này và đổi CompetitorService::source() — giao diện không đổi.
 */
interface KeywordGapSource
{
    /** Số dòng dự kiến tiêu tốn cho một lần làm mới. */
    public function estimateRows(int $domainCount): int;

    /**
     * @param  list<string>  $domains
     * @return list<array{keyword:string, intent:string, volume:int, difficulty:int, ranks:array<string,int>}>
     *
     * @throws SourceUnavailableException khi nhà cung cấp lỗi/hết hạn mức
     */
    public function fetch(array $domains): array;
}
