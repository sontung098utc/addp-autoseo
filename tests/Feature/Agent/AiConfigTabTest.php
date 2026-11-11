<?php

namespace Tests\Feature\Agent;

use App\Filament\Clusters\AgentTeam\Pages\AgentRoles;
use App\Filament\Pages\AgentConnection;
use App\Filament\Pages\AiUsagePage;
use App\Models\AiProvider;
use App\Models\User;
use App\Services\Agent\Ai\AiModelCatalog;
use App\Services\Agent\Roles\RoleIcons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Chi phí AI ▸ tab Cấu hình AI (trước là Tích hợp AI) và hồ sơ nhân sự (Agent Studio) sau khi gộp tab. */
class AiConfigTabTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $role = 'admin'): User
    {
        $u = User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
        $this->actingAs($u);

        return $u;
    }

    private function cfg()
    {
        return Livewire::withQueryParams(['tab' => 'cau-hinh'])->test(AiUsagePage::class);
    }

    public function test_menu_khong_con_tich_hop_ai_va_url_cu_chuyen_ve_tab_cau_hinh(): void
    {
        $this->login();
        $html = $this->get('/admin')->assertOk()->getContent();
        $this->assertSame(0, preg_match('/>\s*Tích hợp AI\s*</u', $html));
        $this->assertSame(1, preg_match('/>\s*Chi phí AI\s*</u', $html));
        $this->assertFalse(class_exists(AgentConnection::class));
        $this->get('/admin/tich-hop-ai')->assertStatus(301)->assertRedirect('/admin/chi-phi-ai?tab=cau-hinh');
        $this->get('/admin/doi-ai/ket-noi-ai')->assertStatus(301)->assertRedirect('/admin/chi-phi-ai?tab=cau-hinh');
        $this->get('/admin/chi-phi-ai?tab=cau-hinh')->assertOk()->assertSee('Cấu hình AI')->assertSee('data-tab="chi-phi"', false);
        $this->get('/admin/chi-phi-ai')->assertOk()->assertDontSee('data-testid="ai-connection"', false);
    }

    public function test_them_hai_tai_khoan_cung_nha_cung_cap_va_hien_logo_model_khoa_an(): void
    {
        $this->login();
        $this->cfg()
            ->callAction('addProvider', ['preset' => 'gemini', 'name' => 'Gemini – công ty', 'api_key' => 'AIzaCOMPANYKEY1111', 'models' => ['gemini-2.5-flash'], 'custom_models' => ['gemini-exp-x'], 'suggest' => false])->assertHasNoActionErrors();
        $this->cfg()
            ->callAction('addProvider', ['preset' => 'gemini', 'name' => 'Gemini – cá nhân', 'api_key' => 'AIzaPERSONALKEY2222', 'models' => ['gemini-2.5-pro'], 'suggest' => false])->assertHasNoActionErrors();
        $this->cfg()
            ->callAction('addProvider', ['preset' => 'openrouter', 'name' => 'OR', 'api_key' => 'sk-or-ZZZZZZZZZZZZ3333', 'models' => [], 'suggest' => false])->assertHasNoActionErrors();

        $this->assertSame(2, AiProvider::where('kind', 'gemini')->count());
        $or = AiProvider::where('name', 'OR')->first();
        $this->assertSame('openai_compat', $or->kind);
        $this->assertSame('https://openrouter.ai/api/v1', $or->base_url);
        $m = AiProvider::where('name', 'Gemini – công ty')->value('models');
        $this->assertContains('gemini-2.5-flash', $m);
        $this->assertContains('gemini-exp-x', $m);
        $this->assertStringNotContainsString('COMPANYKEY', (string) \DB::table('ai_providers')->where('name', 'Gemini – công ty')->value('api_key'));

        $html = $this->get('/admin/chi-phi-ai?tab=cau-hinh')->assertOk()->assertSee('Gemini – công ty')->assertSee('Gemini – cá nhân')->assertSee('Gemini 2.5 Flash')->assertSee('gemini-exp-x')->getContent();
        $this->assertStringContainsString('data-logo="google"', $html);
        $this->assertStringContainsString('data-logo="openrouter"', $html);
        $this->assertStringNotContainsString('COMPANYKEY1111', $html);
        $this->assertStringNotContainsString('AIzaPERSONALKEY2222', $html);
        $this->assertStringContainsString('…2222', $html, 'chỉ hiện đuôi khóa');
    }

    public function test_danh_muc_model_tao_san_va_tai_khoan_cu_dung_bo_mac_dinh(): void
    {
        foreach (['gemini', 'anthropic', 'openai', 'openrouter'] as $brand) {
            $this->assertNotEmpty(AiModelCatalog::catalog($brand), $brand);
            foreach (AiModelCatalog::catalog($brand) as $m) {
                $this->assertContains($m['tier'], ['cheap', 'standard', 'reasoning']);
                $this->assertNull($m['price'], 'không bịa giá');
            }
        }
        $p = AiProvider::create(['name' => 'Cũ', 'kind' => 'anthropic', 'api_key' => 'k-long-enough-key-1', 'enabled' => true]);
        $ids = array_column(AiModelCatalog::enabledModels($p), 'id');
        $this->assertContains('claude-sonnet-5-5', $ids);
        $this->assertFalse(AiModelCatalog::validCustomName('có dấu cách'));
        $this->assertSame('custom', AiModelCatalog::brandOf(AiProvider::create(['name' => 'X', 'kind' => 'openai_compat', 'base_url' => 'https://llm.example.com/v1', 'api_key' => 'k'])));
        $this->assertSame('openai', AiModelCatalog::brandOf(AiProvider::create(['name' => 'O', 'kind' => 'openai_compat', 'base_url' => 'https://api.openai.com/v1', 'api_key' => 'k'])));
    }

    public function test_xoa_tai_khoan_chi_admin_va_ten_model_sai_bi_tu_choi(): void
    {
        $this->login();
        $p = AiProvider::create(['name' => 'Xóa', 'kind' => 'gemini', 'api_key' => 'AIza-DELETE-1234567', 'enabled' => true]);
        $this->cfg()->callAction('editProvider', ['name' => 'Xóa', 'enabled' => true, 'models' => [], 'custom_models' => ['ok-model', 'sai ten']], ['id' => $p->id])->assertHasActionErrors();
        $this->assertNull($p->fresh()->models, 'tên model sai bị từ chối, không lưu');
        $this->cfg()->callAction('editProvider', ['name' => 'Xóa', 'enabled' => true, 'models' => [], 'custom_models' => ['ok-model']], ['id' => $p->id])->assertHasNoActionErrors();
        $this->assertContains('ok-model', $p->fresh()->models);
        $this->cfg()->callAction('deleteProvider', [], ['id' => $p->id])->assertHasNoActionErrors();
        $this->assertSame(0, AiProvider::count());
        $this->login('approver');
        $q = AiProvider::create(['name' => 'Q', 'kind' => 'gemini', 'api_key' => 'AIza-DELETE-1234567', 'enabled' => true]);
        $this->cfg()->assertActionHidden('deleteProvider', ['id' => $q->id]);
    }

    public function test_ho_so_khong_con_ngan_truot_ky_thuat_ma_co_tab_chi_tiet_ky_thuat(): void
    {
        $this->login();
        $this->assertFalse(method_exists(AgentRoles::class, 'detailAction'));
        $t = Livewire::test(AgentRoles::class)->call('openStudio', 'editor', 'overview');
        $t->assertSee('Chi tiết kỹ thuật')->assertDontSee("mountAction('detail'", false);
        $t->call('setStudioTab', 'tech')->assertSee('data-testid="role-detail"', false)->assertSee('Rubric')->assertSee('LEN01');
        $this->assertSame(['overview', 'persona', 'model', 'training', 'position', 'activity', 'tech'], array_keys(AgentRoles::STUDIO_TABS));
    }

    public function test_icon_co_dinh_khong_con_chon_avatar(): void
    {
        $this->login();
        $t = Livewire::test(AgentRoles::class)->call('openStudio', 'writer', 'persona');
        $t->assertDontSee('Emoji')->assertDontSee('fAvatar', false)->assertSee('studio-f-name', false)->assertSee('studio-f-title', false);
        $this->assertFalse(property_exists(AgentRoles::class, 'fAvatarType'));
        $html = $this->get('/admin/doi-ai/vai-tro')->assertOk()->getContent();
        foreach (array_keys(RoleIcons::MAP) as $k) {
            $this->assertStringContainsString('data-role-icon="'.$k.'"', $html);
        }
    }

    public function test_muc_mo_hinh_liet_ke_model_tu_tai_khoan_da_cau_hinh(): void
    {
        $this->login();
        Livewire::test(AgentRoles::class)->call('openStudio', 'writer', 'model')->assertSee('Chưa có tài khoản AI nào đang bật');
        AiProvider::create(['name' => 'Claude công ty', 'kind' => 'anthropic', 'api_key' => 'sk-ant-LONGKEY-0000', 'enabled' => true, 'models' => ['claude-sonnet-5-5', 'claude-opus-5-5']]);
        AiProvider::create(['name' => 'Đang tắt', 'kind' => 'gemini', 'api_key' => 'AIza-OFF-0000000000', 'enabled' => false]);
        $t = Livewire::test(AgentRoles::class)->call('openStudio', 'writer', 'model');
        $t->assertSee('Claude công ty')->assertSee('Claude Sonnet 5.5')->assertSee('Claude Opus 5.5')->assertSee('Suy luận')->assertSee('data-logo="anthropic"', false)
            ->assertDontSee('Đang tắt')->assertDontSee('claude-haiku-5-5');
    }

    public function test_huan_luyen_gop_cac_muc_va_vi_tri_lam_mo_luong_khong_lien_quan(): void
    {
        $this->login();
        $t = Livewire::test(AgentRoles::class)->call('openStudio', 'strategist', 'training');
        $t->assertSee('studio-train-tabs', false)->assertSee('Bài học')->assertSee('Tài liệu')->assertSee('Kỹ năng')->assertSee('Trí tuệ')->assertSee('Lần chạy cần dạy lại');
        foreach (['knowledge', 'brain', 'skills', 'lab', 'connect'] as $old) {
            $this->assertArrayNotHasKey($old, AgentRoles::STUDIO_TABS);
        }
        $html = $t->call('setStudioTab', 'position')->html();
        $this->assertStringContainsString('ags-pos--off', $html);
        $this->assertStringContainsString('ags-pos--on', $html);
        $this->assertStringContainsString('data-testid="studio-open-pipeline"', $html);
        $this->assertLessThan(strrpos($html, 'ags-pos--off'), strpos($html, 'ags-pos--on'), 'luồng tham gia xếp trước');
    }
}
