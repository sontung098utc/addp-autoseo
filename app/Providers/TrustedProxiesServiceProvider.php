<?php

namespace App\Providers;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\ServiceProvider;

/**
 * Ứng dụng chạy sau proxy/tunnel (Cloudflare, cloudflared...) thì địa chỉ tài nguyên và biểu mẫu phải theo https người dùng đang dùng, không theo http nội bộ:
 * nếu không, trình duyệt chặn CSS/JS (nội dung hỗn hợp) và giao diện hiện trơ.
 *
 * Mặc định TẮT. Chỉ tin tiêu đề X-Forwarded-* khi đặt TRUSTED_PROXIES: danh sách IP/CIDR cách nhau dấu phẩy, hoặc * khi chắc chắn chỉ proxy mới tới được ứng dụng.
 * Đặt ở đây (lúc cấu hình đã nạp) chứ không ở bootstrap/app.php, vì nơi đó chạy trước khi .env được nạp.
 */
class TrustedProxiesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $proxies = trim((string) config('app.trusted_proxies', ''));
        if ($proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $proxies)))));
        }
    }
}
