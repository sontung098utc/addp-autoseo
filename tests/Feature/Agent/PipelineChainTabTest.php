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

/** G1: tab Pipeline hiện chuỗi 7 mắt xích với thẻ loại việc, giữ bản đồ 9 giai đoạn. */
class PipelineChainTabTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    public function test_hien_du_7_mat_xich_theo_thu_tu_va_the_viet_bai(): void
    {
        $this->actingAs($this->user('approver'));
        $labels = array_values(JobTypeRegistry::CHAIN);
        $this->assertCount(7, $labels);
        $res = $this->get('/admin/doi-ai/pipeline')->assertOk()->assertSeeInOrder($labels)
            ->assertSee('Viết bài')->assertSee('Dùng được')->assertSee('Việc bạn cần làm')
            ->assertSee('Bản đồ 9 giai đoạn (trạng thái bằng chứng)')
            ->assertDontSee('Chạy toàn bộ');
        $this->assertFalse(PipelineBlueprint::controlsWired());
        $res->assertDontSee('Văn phòng AI')->assertSee('data-testid="help"', false);
        $this->assertCount(9, PipelineBlueprint::stages());
        $res->assertSee('data-stage="quality"', false);
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

    public function test_the_ngoai_khong_in_ten_bang_va_co_nut_mo_trang(): void
    {
        $this->actingAs($this->user('approver'));
        $html = $this->get('/admin/doi-ai/pipeline')->assertOk()->getContent();
        $chain = substr($html, strpos($html, 'data-testid="pipeline-chain"'), strpos($html, 'data-testid="pipeline-map9"') - strpos($html, 'data-testid="pipeline-chain"'));
        $this->assertStringNotContainsString('agent_runs', $chain);
        $this->assertStringNotContainsString('source=ai', $chain);
        $this->assertStringContainsString('open-work-site_audit', $chain);
        $this->assertStringContainsString('Mở trang làm việc này', $chain);
        Livewire::test(PipelineMap::class)->call('mountAction', 'job', ['job' => 'article_write'])
            ->assertSee('Chi tiết kỹ thuật (dành cho quản trị)')->assertSee('agent_runs');
    }

    public function test_dong_tom_tat_dung_so(): void
    {
        $t = PipelineMap::tally();
        $all = JobTypeRegistry::all();
        $this->assertSame(count($all), $t['total']);
        $this->assertSame($t['total'], $t['ready'] + $t['partial'] + $t['none']);
        $this->assertSame(count(array_filter($all, fn ($j) => $j['status'] !== 'active')), $t['none']);
        $this->actingAs($this->user('approver'));
        $this->get('/admin/doi-ai/pipeline')->assertSee("{$t['ready']}/{$t['total']} việc dùng được, {$t['partial']} dùng được một phần, {$t['none']} chưa có.", false);
    }

    public function test_quy_tac_muc_dung_duoc(): void
    {
        $this->assertSame('none', PipelineMap::usability(['status' => 'planned', 'summary' => 'x']));
        $this->assertSame('partial', PipelineMap::usability(['status' => 'active', 'summary' => 'Có A. Chưa làm: B']));
        $this->assertSame('partial', PipelineMap::usability(['status' => 'active', 'summary' => 'Sửa MỘT PHẦN']));
        $this->assertSame('ready', PipelineMap::usability(['status' => 'active', 'summary' => 'Viết bài.']));
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
