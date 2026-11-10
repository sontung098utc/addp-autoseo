<?php

namespace Tests\Feature\Knowledge\Build;

use App\Models\User;
use App\Models\Website;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BrainBuildCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_khong_tim_thay_website(): void
    {
        $this->artisan('brain:build 999')->expectsOutputToContain('Không tìm thấy website')->assertFailed();
    }

    public function test_khong_co_nguoi_duyet(): void
    {
        $w = Website::create(['name' => 'A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->artisan('brain:build '.$w->id)->expectsOutputToContain('Không có người duyệt')->assertFailed();
    }

    public function test_thieu_ai_in_loi_ro_va_khong_tai_trang(): void
    {
        Http::fake();
        $w = Website::create(['name' => 'A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        User::create(['name' => 'ad', 'email' => 'ad@x.vn', 'password' => 'secret123', 'role' => 'admin', 'is_active' => true]);
        $this->artisan('brain:build '.$w->id.' --max-pages=5 --url=https://a.vn/x')->expectsOutputToContain('Chưa kết nối AI')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_lenh_duoc_dang_ky_voi_tuy_chon(): void
    {
        $cmd = $this->app->make(Kernel::class)->all()['brain:build'] ?? null;
        $this->assertNotNull($cmd);
        $def = $cmd->getDefinition();
        $this->assertSame('30', (string) $def->getOption('max-pages')->getDefault());
        $this->assertTrue($def->getOption('url')->isArray());
    }
}
