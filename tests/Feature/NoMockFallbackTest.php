<?php

namespace Tests\Feature;

use App\Filament\Support\Demo\DemoMode;
use App\Services\Agent\Settings\AgentAi;
use App\Services\Images\ImageGeneratorResolver;
use App\Services\Images\ImageGeneratorUnavailable;
use App\Services\Keywords\KeywordAiResolver;
use App\Services\Keywords\KeywordAiUnavailable;
use App\Services\Search\WebSearchResolver;
use App\Services\Search\WebSearchUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Mặc định (STUDIO_DEMO tắt): thiếu cấu hình thì báo rõ, KHÔNG rơi về AI/tìm web/ảnh giả; chế độ minh họa không bật được ở production. */
class NoMockFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_tat_demo_thi_khong_roi_ve_ban_gia(): void
    {
        config(['studio.demo' => false, 'search.tavily.key' => '']);
        $this->assertFalse(DemoMode::enabled());
        $this->assertNull(AgentAi::forInteractiveUse(), 'không có AI thật thì không có AI (không dùng AI giả lập)');

        try {
            (new WebSearchResolver)->resolve();
            $this->fail('Tìm web phải báo chưa cấu hình, không dùng kết quả mô phỏng');
        } catch (WebSearchUnavailable $e) {
            $this->assertStringContainsString('TAVILY_API_KEY', $e->getMessage());
        }
        $this->expectException(KeywordAiUnavailable::class);
        KeywordAiResolver::resolve('keyword_suggest');
    }

    public function test_tat_demo_anh_khong_co_route_thi_bao_chua_cau_hinh(): void
    {
        config(['studio.demo' => false]);
        $this->expectException(ImageGeneratorUnavailable::class);
        ImageGeneratorResolver::resolve();
    }

    public function test_che_do_minh_hoa_khong_bat_duoc_o_production(): void
    {
        config(['studio.demo' => true]);
        $this->assertTrue(DemoMode::enabled());
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertFalse(DemoMode::enabled());
    }
}
