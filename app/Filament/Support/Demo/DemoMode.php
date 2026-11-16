<?php

namespace App\Filament\Support\Demo;

/**
 * Chế độ minh họa cho các màn chưa có nguồn dữ liệu thật (Từ khóa, Kế hoạch nội dung, Audit, Search Console,
 * Đối thủ...). QUY TẮC TRUNG THỰC: dữ liệu minh họa CHỈ hiện ngoài production; ở production màn phải hiện
 * trạng thái "chưa kết nối"/"chưa có dữ liệu" thật, tuyệt đối không bày số mẫu như thể số thật.
 */
final class DemoMode
{
    /**
     * Mặc định TẮT: các màn hiện dữ liệu thật hoặc nói rõ "chưa kết nối/chưa có dữ liệu", và AI/tìm web/ảnh/chỉ số từ khóa thiếu cấu hình thì báo rõ,
     * không rơi về bản giả. Bật (STUDIO_DEMO=true) chỉ để dựng dữ liệu minh họa khi phát triển; không bao giờ bật được ở production.
     */
    public static function enabled(): bool
    {
        return (bool) config('studio.demo', false) && ! app()->isProduction();
    }

    /**
     * Trạng thái minh họa chọn qua ?demo=... (chỉ khi bật chế độ minh họa), để xem các trạng thái của mockup
     * (đang quét, lỗi, chưa kết nối...). Giá trị lạ hoặc ở production thì dùng $default.
     *
     * @param  list<string>  $allowed
     */
    public static function state(?string $requested, array $allowed, string $default): string
    {
        if (! self::enabled() || $requested === null || ! in_array($requested, $allowed, true)) {
            return $default;
        }

        return $requested;
    }
}
