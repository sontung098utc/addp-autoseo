<?php

namespace Tests\Feature\Agent;

use App\Services\Security\UrlSafetyService;
use InvalidArgumentException;
use Tests\TestCase;

/** Danh sách cho phép Base URL nội bộ của nhà cung cấp AI (cầu nối CLI thử nghiệm): mặc định chặn, chỉ mở đúng host:cổng. */
class AiPrivateBaseUrlTest extends TestCase
{
    private function ok(string $url): bool
    {
        try {
            return app(UrlSafetyService::class)->validateAiBaseUrl($url);
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function test_mac_dinh_chan_dia_chi_noi_bo(): void
    {
        config(['ai_models.private_base_url_allowlist' => []]);
        $this->assertFalse($this->ok('http://172.17.0.1:8765/v1'));
    }

    public function test_chi_mo_dung_host_va_cong_trong_danh_sach(): void
    {
        config(['ai_models.private_base_url_allowlist' => ['172.17.0.1:8765']]);
        $this->assertTrue($this->ok('http://172.17.0.1:8765/v1'));
        $this->assertFalse($this->ok('http://172.17.0.1:9999/v1'), 'khác cổng');
        $this->assertFalse($this->ok('http://172.17.0.2:8765/v1'), 'khác host');
        $this->assertFalse($this->ok('http://169.254.169.254:8765/v1'), 'metadata đám mây vẫn chặn');
        $this->assertFalse($this->ok('http://10.0.0.5/v1'), 'không cổng, không khớp');
        $this->assertFalse($this->ok('ftp://172.17.0.1:8765/v1'), 'sai giao thức');
    }

    public function test_validateUrl_thuong_van_chan_du_co_danh_sach(): void
    {
        config(['ai_models.private_base_url_allowlist' => ['172.17.0.1:8765']]);
        $this->expectException(InvalidArgumentException::class);
        app(UrlSafetyService::class)->validateUrl('http://172.17.0.1:8765/v1');
    }

    public function test_bo_tao_anh_openai_compat_dung_danh_sach_cho_phep(): void
    {
        $png = base64_encode("\x89PNG\r\n\x1a\n".str_repeat("\0", 32));
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['data' => [['b64_json' => $png]]])]);
        $gen = new \App\Services\Images\OpenAiCompatImageGenerator('k', 'agy-image', 'http://172.17.0.1:8765/v1', 1000);

        config(['ai_models.private_base_url_allowlist' => []]);
        try {
            $gen->generate('một cảnh văn phòng');
            $this->fail('Mặc định phải chặn địa chỉ nội bộ.');
        } catch (\App\Services\Images\ImageGenerationError $e) {
            $this->assertStringContainsString('không an toàn', $e->getMessage());
        }

        config(['ai_models.private_base_url_allowlist' => ['172.17.0.1:8765']]);
        try {
            $gen->generate('một cảnh văn phòng');
        } catch (\App\Services\Images\ImageGenerationError $e) {
            $this->assertStringNotContainsString('không an toàn', $e->getMessage(), 'địa chỉ trong danh sách cho phép không được bị chặn SSRF');
        }
    }
}
