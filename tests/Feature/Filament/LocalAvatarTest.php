<?php

namespace Tests\Feature\Filament;

use App\Filament\Support\LocalAvatarProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Preview #1: ảnh đại diện dựng cục bộ, không gọi ui-avatars.com. */
class LocalAvatarTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name): User
    {
        return User::create(['name' => $name, 'email' => uniqid().'@a.vn', 'password' => 'secret123', 'role' => 'admin', 'is_active' => true]);
    }

    public function test_trang_admin_khong_con_ui_avatars(): void
    {
        $this->actingAs($this->user('Phạm Dũng'));
        $html = $this->get('/admin')->assertOk()->getContent();
        $this->assertStringNotContainsString('ui-avatars.com', $html);
        $this->assertStringContainsString('data:image/svg+xml;base64,', $html);
    }

    private function svg(string $name): string
    {
        $uri = (new LocalAvatarProvider())->get($this->user($name));
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $uri);

        return base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')));
    }

    public function test_svg_lay_chu_cai_dau_cua_ten(): void
    {
        $this->assertStringContainsString('>ĐA<', $this->svg('đặng An'));
    }

    public function test_svg_khong_chua_ma_doc_tu_ten(): void
    {
        $svg = $this->svg('<script>alert(1)</script>');
        $this->assertStringNotContainsString('<script', $svg);
        $this->assertStringContainsString('>?<', $this->svg('<>'));
    }
}
