<?php

namespace App\Contracts;

use App\Services\Keywords\KeywordMetricsFailed;

/**
 * Nguồn số liệu lượt tìm THẬT cho từ khóa (không phải AI đoán). Đổi nhà cung cấp bằng cách viết lớp mới theo hợp đồng này.
 */
interface KeywordMetricsProvider
{
    /**
     * Khóa rows = Keyword::keyFor($keyword). Từ khóa không có số liệu: volume null (không bao giờ điền số giả).
     * cpc tính bằng USD; cost_vnd là ƯỚC TÍNH theo cấu hình.
     *
     * @param  list<string>  $keywords
     * @param  array<string,mixed>  $options  location_code, language_code (mặc định lấy ở config/keywords.php)
     * @return array{rows: array<string, array{volume:?int, competition:?float, cpc:?float}>, cost_vnd: float, provider: string, fetched_at: string}
     *
     * @throws KeywordMetricsFailed khi nhà cung cấp lỗi (thông báo đã ẩn thông tin đăng nhập)
     */
    public function metrics(array $keywords, array $options = []): array;

    /** Tên ngắn để ghi vào keywords.volume_source (tối đa 12 ký tự). */
    public function name(): string;
}
