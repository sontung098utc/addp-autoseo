<?php

namespace Tests\Feature\Knowledge;

use App\Filament\Pages\BrainBuildPage;
use App\Models\Brain;
use App\Models\KnowledgeGap;
use App\Models\User;
use App\Models\Website;
use App\Services\Brain\BrainTemplates;
use App\Services\Knowledge\Suggest\KnowledgeSuggestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/** Gợi ý "nên lập Trí tuệ cho lĩnh vực X" do điều phối ghi: hiện ở trang Xây kho và tạo được Trí tuệ bằng một nút. */
class BrainSuggestionTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->site = Website::create(['name' => 'Máy móc', 'cms_type' => 'wordpress', 'base_url' => 'https://m.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        KnowledgeGap::create(['website_id' => $this->site->id, 'query' => KnowledgeSuggestions::BRAIN_PREFIX.'Dinh dưỡng cho người tiểu đường', 'count' => 2, 'status' => 'open']);
    }

    private function admin(): User
    {
        return User::create(['name' => 'a', 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => 'admin', 'is_active' => true]);
    }

    public function test_goi_y_lap_tri_tue_hien_thanh_loai_brain_dung_dau(): void
    {
        $list = (new KnowledgeSuggestions)->forWebsite($this->site, 20);
        $this->assertSame('brain', $list[0]['type']);
        $this->assertSame('create_brain', $list[0]['action']);
        $this->assertSame('Dinh dưỡng cho người tiểu đường', $list[0]['query']);
        $this->assertStringContainsString('Nên lập Trí tuệ', $list[0]['title']);
    }

    public function test_nut_tao_tri_tue_tao_tu_mau_hop_nhat_gan_website_va_dong_goi_y(): void
    {
        $this->actingAs($this->admin());
        $key = (new KnowledgeSuggestions)->forWebsite($this->site, 20)[0]['key'];
        Livewire::test(BrainBuildPage::class)->set('websiteId', $this->site->id)->assertSee('Tạo Trí tuệ và gắn vào website')->call('createBrain', $key);

        $brain = Brain::where('name', 'Dinh dưỡng cho người tiểu đường')->first();
        $this->assertNotNull($brain);
        $this->assertSame('active', $brain->status);
        $this->assertTrue($brain->websites()->where('websites.id', $this->site->id)->exists());
        $this->assertSame('filled', KnowledgeGap::first()->status);
        $this->assertSame([], array_filter((new KnowledgeSuggestions)->forWebsite($this->site, 20), fn ($s) => $s['type'] === 'brain'), 'Gợi ý đã đóng.');
    }

    public function test_chon_mau_theo_tu_khoa_va_mac_dinh_la_mau_trong(): void
    {
        $this->assertSame('trong', BrainTemplates::suggestFor('Máy móc bào chế viên nén'));
        $this->assertNotSame('trong', BrainTemplates::suggestFor('Dinh dưỡng và chế độ ăn'));
    }

    public function test_nguoi_khong_co_quyen_khong_tao_duoc(): void
    {
        $this->actingAs(User::create(['name' => 'w', 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => 'writer', 'is_active' => true]));
        $this->get('/admin/kho-tri-tue/tao-tri-tue-doanh-nghiep')->assertForbidden();
        $this->assertSame(0, Brain::count());
    }
}
