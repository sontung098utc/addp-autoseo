<?php

namespace Tests\Feature;

use App\Providers\TrustedProxiesServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Tests\TestCase;

/** Sau proxy/tunnel: mặc định KHÔNG tin tiêu đề X-Forwarded-*; chỉ tin khi đặt TRUSTED_PROXIES (tránh giả mạo https/địa chỉ). */
class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TrustProxies::flushState(); // .env của máy dev có thể đã đặt TRUSTED_PROXIES từ lúc ứng dụng khởi động
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        parent::tearDown();
    }

    private function secureAfterProxy(): bool
    {
        $req = Request::create('http://127.0.0.1/admin', 'GET', [], [], [], ['REMOTE_ADDR' => '172.18.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4']);
        $secure = null;
        (new TrustProxies)->handle($req, function (Request $r) use (&$secure) {
            $secure = $r->isSecure();

            return response('ok');
        });

        return (bool) $secure;
    }

    public function test_mac_dinh_khong_tin_tieu_de_proxy(): void
    {
        config(['app.trusted_proxies' => '']);
        (new TrustedProxiesServiceProvider($this->app))->boot();
        $this->assertFalse($this->secureAfterProxy());
    }

    public function test_dat_trusted_proxies_thi_theo_https_cua_nguoi_dung(): void
    {
        config(['app.trusted_proxies' => '*']);
        (new TrustedProxiesServiceProvider($this->app))->boot();
        $this->assertTrue($this->secureAfterProxy());
    }

    public function test_chi_tin_dung_dia_chi_proxy_da_khai_bao(): void
    {
        config(['app.trusted_proxies' => '10.0.0.1, 10.0.0.2']);
        (new TrustedProxiesServiceProvider($this->app))->boot();
        $this->assertFalse($this->secureAfterProxy(), 'Nguồn 172.18.0.1 không nằm trong danh sách nên không được tin.');
    }
}
