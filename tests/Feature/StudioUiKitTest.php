<?php

namespace Tests\Feature;

use App\Filament\Pages\UiKitPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Tests\TestCase;

class StudioUiKitTest extends TestCase
{
    use RefreshDatabase;

    public function test_tabs_co_role_nut_dem_cham_mau_va_panel(): void
    {
        $html = Blade::render(<<<'B'
            <x-studio.tabs persist="t" :tabs="[['key'=>'a','label'=>'Danh sách','count'=>40],['key'=>'b','label'=>'Cảnh báo','tone'=>'warn'],['key'=>'c','label'=>'Khác','tone'=>'bậy']]">
                <x-studio.panel key="a">A</x-studio.panel><x-studio.panel key="b">B</x-studio.panel>
            </x-studio.tabs>
        B);

        $this->assertStringContainsString('role="tablist"', $html);
        $this->assertSame(3, substr_count($html, 'role="tab"'));
        $this->assertStringContainsString('>40<', $html);
        $this->assertStringContainsString('ss-dot--warn', $html);
        $this->assertStringNotContainsString('ss-dot--bậy', $html, 'tone lạ không được vào class');
        $this->assertSame(2, substr_count($html, 'role="tabpanel"'));
        $this->assertStringContainsString('studioTabs(', $html);
    }

    public function test_bang_du_lieu_khong_in_html_tho_tu_du_lieu(): void
    {
        $payload = '<script>alert(1)</script><img src=x onerror=alert(2)>';
        $html = Blade::render(
            '<x-studio.data-table id="x" :columns="$c" :rows="$r" />',
            ['c' => [['key' => 'name', 'label' => 'Tên', 'type' => 'text']], 'r' => [['id' => 1, 'name' => $payload]]]
        );

        $this->assertStringContainsString('studioTable(', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('x-html', $html, 'bảng chỉ dùng x-text');
    }

    public function test_delta_mau_theo_huong_tot_xau(): void
    {
        $render = fn (string $tpl) => Blade::render($tpl);

        $this->assertStringContainsString('ss-delta--ok', $render('<x-studio.delta :value="5" good="up" />'));
        $this->assertStringContainsString('ss-delta--danger', $render('<x-studio.delta :value="-5" good="up" />'));
        $this->assertStringContainsString('ss-delta--ok', $render('<x-studio.delta :value="-0.6" good="down" />'), 'vị trí giảm là tốt');
        $this->assertStringContainsString('ss-delta--danger', $render('<x-studio.delta :value="2" good="down" />'));
        $this->assertStringContainsString('ss-delta--flat', $render('<x-studio.delta :value="0" />'));
        $this->assertStringContainsString('ss-delta--none', $render('<x-studio.delta :value="null" />'));
        $this->assertStringContainsString('giảm 14,0%', $render('<x-studio.delta :value="-14" unit="%" />'));
    }

    public function test_badge_chan_tone_la(): void
    {
        $html = Blade::render('<x-studio.badge tone="javascript:x">Mẫu</x-studio.badge>');
        $this->assertStringContainsString('ss-badge--muted', $html);
        $this->assertStringNotContainsString('javascript', $html);
    }

    public function test_demo_banner_khong_hien_gi(): void
    {
        $this->assertSame('', trim(Blade::render('<x-demo-banner source="x" real="y" />')));
    }

    public function test_panel_nap_script_va_full_chieu_rong_va_trang_thanh_phan_chay(): void
    {
        $this->assertFileExists(public_path('js/studio-ui.js'));
        $user = User::create(['name' => 'a', 'email' => 'a@x.vn', 'password' => 'secret123', 'role' => 'writer', 'is_active' => true]);
        $this->actingAs($user);

        $this->get('/admin/thanh-phan-ui')->assertOk()->assertSee('js/studio-ui.js', false)->assertSee('ss-tabs', false);
        Livewire::test(UiKitPage::class)->assertSuccessful();

        $css = (string) file_get_contents(public_path('css/seo-studio.css'));
        foreach (['.ss-tab.is-active', '.ss-delta--ok', '.ss-badge--danger', '.ss-dt__table', '.ss-alert--warn'] as $needle) {
            $this->assertStringContainsString($needle, $css);
        }
    }

    public function test_trang_thanh_phan_bi_an_o_production(): void
    {
        $this->app['env'] = 'production';
        $this->actingAs(User::create(['name' => 'a', 'email' => 'b@x.vn', 'password' => 'secret123', 'role' => 'admin', 'is_active' => true]));
        $this->assertFalse(UiKitPage::canAccess());
    }

    public function test_bang_chon_nhieu_dong_xuat_cau_hinh_chon(): void
    {
        $html = Blade::render(
            '<x-studio.data-table id="g" :columns="$c" :rows="$r" :selectable="true" select-model="picked" :selected="[2]" />',
            ['c' => [['key' => 'k', 'label' => 'Từ khóa', 'type' => 'text']], 'r' => [['id' => 1, 'k' => 'a'], ['id' => 2, 'k' => 'b']]]
        );

        $this->assertStringContainsString('\\u0022selectable\\u0022:true', $html);
        $this->assertStringContainsString('\\u0022selectModel\\u0022:\\u0022picked\\u0022', $html);
        $this->assertStringContainsString('aria-label="Chọn tất cả các dòng đang lọc"', $html);
    }
}
