<?php

namespace Tests\Feature\Agent;

use App\Filament\Clusters\AgentTeam\Pages\PipelineMap;
use App\Filament\Pages\AuditPage;
use App\Filament\Pages\KeywordsPage;
use App\Models\User;
use App\Services\Agent\Pipeline\JobTypeRegistry;
use App\Services\Agent\Pipeline\PipelineBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Tab Pipeline: hai tab ngang, mỗi loại việc một luồng; không còn chuỗi mắt xích và bản đồ 9 giai đoạn. */
class PipelineChainTabTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    public function test_khong_con_chuoi_mat_xich_va_ban_do_9_giai_doan(): void
    {
        $this->actingAs($this->user('approver'));
        $res = $this->get('/admin/doi-ai/pipeline')->assertOk()
            ->assertDontSee('Chuỗi làm ra một bài')->assertDontSee('Chuỗi 7 mắt xích')->assertDontSee('Mắt xích')
            ->assertDontSee('Bản đồ 9 giai đoạn')->assertDontSee('việc dùng được')
            ->assertDontSee('data-testid="pipeline-chain"', false)->assertDontSee('data-testid="pipeline-map9"', false)
            ->assertDontSee('Chạy toàn bộ')->assertDontSee('Văn phòng AI')
            ->assertSee('data-testid="help"', false);
        $this->assertFalse(PipelineBlueprint::controlsWired());
        $res->assertDontSee('data-stage="quality"', false);
    }

    public function test_chi_con_hai_tab_ngang(): void
    {
        $this->actingAs($this->user('approver'));
        $html = $this->get('/admin/doi-ai/pipeline')->assertOk()->getContent();
        preg_match_all('/role="tab" class="ss-tab" data-key="([^"]+)"/', $html, $m);
        $this->assertSame(['flows', 'runs'], $m[1]);
    }

    public function test_moi_luong_la_mot_the_lon_va_mot_popup_co_tieu_de_mo_ta_va_nut_mo_trang(): void
    {
        $this->actingAs($this->user('approver'));
        $html = $this->get('/admin/doi-ai/pipeline')->assertOk()->getContent();
        foreach (JobTypeRegistry::all() as $j) {
            $this->assertStringContainsString('data-card="'.$j['id'].'"', $html);
            $this->assertStringNotContainsString('data-flow-chip', $html);
            $a = strpos($html, '<section class="pv-flow pm__box" data-flow="'.$j['id'].'"');
            $this->assertNotFalse($a, $j['id']);
            $sec = substr($html, $a, strpos($html, '</section>', $a) - $a);
            $this->assertStringContainsString($j['title'], $sec);
            $this->assertStringContainsString('pv-flow__plain', $sec);
            $this->assertStringNotContainsString('agent_runs', $sec);
        }
        $this->assertStringContainsString('open-work-site_audit', $html);
        $this->assertStringContainsString('Mở trang làm việc', $html);
    }

    public function test_cua_so_truot_loai_viec(): void
    {
        $this->actingAs($this->user('approver'));
        Livewire::test(PipelineMap::class)
            ->call('mountAction', 'job', ['job' => 'article_write'])
            ->assertSee('Lập dàn ý')->assertSee('Chưa chạy')->assertSee('Ai dùng kết quả tiếp')->assertSee('Điểm người can thiệp')->assertSee('content.generate');
    }

    public function test_quyen_truy_cap_nhu_cu(): void
    {
        $this->actingAs($this->user('writer'));
        $this->assertFalse(PipelineMap::canAccess());
        $this->get('/admin/doi-ai/pipeline')->assertForbidden();
        $this->actingAs($this->user('approver'));
        $this->assertTrue(PipelineMap::canAccess());
    }

    public function test_cua_so_chi_tiet_ky_thuat_cho_quan_tri_con_nguyen(): void
    {
        $this->actingAs($this->user('approver'));
        Livewire::test(PipelineMap::class)->call('mountAction', 'job', ['job' => 'article_write'])
            ->assertSee('Chi tiết kỹ thuật (dành cho quản trị)')->assertSee('agent_runs')->assertDontSee('Mắt xích');
    }

    public function test_cau_mo_ta_tranh_ma_ky_thuat(): void
    {
        $this->assertSame('Viết bài ngắn.', PipelineMap::plainLine(['id' => 'x', 'summary' => 'Viết bài ngắn. Còn lại.']));
        $this->assertSame(PipelineMap::PLAIN['site_audit'], PipelineMap::plainLine(['id' => 'site_audit', 'summary' => 'Ghi vào agent_runs. Khác.']));
    }

    public function test_nut_an_khi_khong_co_class_hoac_khong_du_quyen(): void
    {
        $this->actingAs($this->user('approver'));
        $this->assertNull(PipelineMap::workUrl('khong_ton_tai'));
        $this->assertNotNull(PipelineMap::workUrl('site_audit'));
        $this->actingAs($this->user('writer'));
        foreach (['market_research' => KeywordsPage::class, 'site_audit' => AuditPage::class] as $id => $cls) {
            $this->assertSame($cls::canAccess() ? $cls::getUrl() : null, PipelineMap::workUrl($id));
        }
    }
}
