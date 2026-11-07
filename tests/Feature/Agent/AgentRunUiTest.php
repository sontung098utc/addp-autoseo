<?php

namespace Tests\Feature\Agent;

use App\Filament\Resources\AgentRunResource;
use App\Filament\Resources\ArticleResource\Pages\ListArticles;
use App\Filament\Support\Agent\AgentFlowBlueprint;
use App\Filament\Support\Agent\FeatureRegistry;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Services\Ai\MockAiProvider;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** AG-S01: giao diện của lát dọc, quyền theo vai trò và độ trung thực của bản thiết kế sống. */
class AgentRunUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(EmergencyStopService::class)->resetForTesting();
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function article(): Article
    {
        $w = Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);

        return Article::create(['website_id' => $w->id, 'primary_keyword' => 'tối ưu seo cho website', 'workflow_status' => 'planned']);
    }

    private function realRun(): AgentRun
    {
        return (new AgentRunOrchestrator(new MockAiProvider()))->run($this->article(), null);
    }

    public function test_trang_danh_sach_va_chi_tiet_hien_nhat_ky_va_nhan_ai_gia_lap(): void
    {
        $run = $this->realRun();
        $this->actingAs($this->user('approver'));

        $this->get(AgentRunResource::getUrl('index'))->assertOk()->assertSee('Phiên chạy của đội AI')->assertSee('tối ưu seo cho website')->assertSee('AI giả lập');
        $this->get(AgentRunResource::getUrl('view', ['record' => $run]))->assertOk()
            ->assertSee('Luồng đã đi qua')->assertSee('Nhật ký từng bước')->assertSee('Dev1 cần')
            ->assertSee('AI giả lập')->assertSee('chỉ tạo bản nháp');
    }

    public function test_bien_tap_vien_khong_thay_phien_chay(): void
    {
        $run = $this->realRun();
        $this->actingAs($this->user('writer'));

        $this->get(AgentRunResource::getUrl('index'))->assertForbidden();
        $this->get(AgentRunResource::getUrl('view', ['record' => $run]))->assertForbidden();
    }

    public function test_phien_chay_chi_xem_khong_co_tao_sua_xoa(): void
    {
        $this->actingAs($this->user('admin'));

        $this->assertFalse(AgentRunResource::canCreate());
        $run = $this->realRun();
        $this->assertFalse(AgentRunResource::canEdit($run));
        $this->assertFalse(AgentRunResource::canDelete($run));
    }

    public function test_nut_giao_doi_ai_tao_phien_chay_va_khong_xuat_ban(): void
    {
        $a = $this->article();
        $this->actingAs($this->user('approver'));

        Livewire::test(ListArticles::class)->callTableAction('run_agent', $a)->assertHasNoTableActionErrors();

        $this->assertSame(1, AgentRun::count());
        $this->assertSame(0, \App\Models\Publication::count());
    }

    public function test_bien_tap_vien_khong_thay_nut_giao_doi_ai(): void
    {
        $a = $this->article();
        $this->actingAs($this->user('writer'));

        Livewire::test(ListArticles::class)->assertTableActionHidden('run_agent', $a);
    }

    public function test_trang_cach_hoat_dong_hien_ban_thiet_ke_song(): void
    {
        $this->actingAs($this->user('writer'));

        $this->get('/admin/cach-hoat-dong')->assertOk()->assertSee('Dev1 cần')->assertSee('AG-B06')->assertSee('Luồng của đội AI');
    }

    public function test_ban_thiet_ke_khong_to_xanh_khi_chua_co_bang_chung(): void
    {
        $valid = FeatureRegistry::READINESS;
        foreach (AgentFlowBlueprint::stages() as $s) {
            $this->assertContains($s['readiness'], $valid, $s['stage']);
            $this->assertNotSame('', trim($s['dev1']), "{$s['stage']} thiếu phần Dev1 cần làm");
            $this->assertMatchesRegularExpression('/^AG-[A-Z]\d+[a-z]?$/', $s['task']);
        }
        // chỉ chặng "chấm bằng luật" (đã chạy thật trong hệ thống) được là Đang hoạt động
        $active = array_values(array_filter(AgentFlowBlueprint::stages(), fn ($s) => $s['readiness'] === 'active'));
        $this->assertSame(['grade'], array_column($active, 'stage'));
    }

    public function test_cac_chang_cua_ban_thiet_ke_khop_cac_buoc_bo_dieu_phoi_ghi(): void
    {
        $run = $this->realRun();
        $known = array_keys(AgentFlowBlueprint::byStage());

        foreach ($run->steps as $s) {
            $this->assertContains($s->stage, $known, "bước {$s->stage} không có trong bản thiết kế");
        }
    }
}
