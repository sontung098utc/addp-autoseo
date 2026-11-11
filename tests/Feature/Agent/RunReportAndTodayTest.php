<?php

namespace Tests\Feature\Agent;

use App\Filament\Pages\UiKitPage;
use App\Filament\Resources\AgentRunResource;
use App\Filament\Support\Agent\RunReport;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RunReportAndTodayTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Website
    {
        return Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://'.uniqid().'.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function user(): User
    {
        return User::create(['name' => 'a', 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => 'approver', 'is_active' => true]);
    }

    public function test_bao_cao_khong_lo_ma_trang_thai_ky_thuat(): void
    {
        $w = $this->site();
        $a = Article::create(['website_id' => $w->id, 'primary_keyword' => 'abc', 'workflow_status' => 'needs_review']);
        $run = AgentRun::create(['website_id' => $w->id, 'article_id' => $a->id, 'status' => 'ready_for_review', 'cost_vnd' => 10]);

        $text = implode(' ', RunReport::for($run)['lines']);
        $this->assertStringNotContainsString('ready_for_review', $text);
        $this->assertStringContainsString('đã có bản nháp chờ duyệt', $text);
    }

    public function test_bao_cao_khao_sat_noi_that_ve_luot_tim_va_co_lien_ket(): void
    {
        $w = $this->site();
        $run = AgentRun::create(['website_id' => $w->id, 'job_type' => 'market_research', 'status' => 'ready_for_review', 'cost_vnd' => 120, 'output_ref' => ['added' => 7, 'keyword_ids' => [1, 2]]]);
        $r = RunReport::for($run);

        $this->assertStringContainsString('Khảo sát thị trường', $r['title']);
        $this->assertStringContainsString('Đã thêm 7 từ khóa', $r['lines'][0]);
        $this->assertStringContainsString('lượt tìm để trống', $r['lines'][0]);
        $this->assertNotEmpty($r['links']);
    }

    public function test_bao_cao_bai_viet_va_trang_phien_hien_bao_cao(): void
    {
        $w = $this->site();
        $a = Article::create(['website_id' => $w->id, 'primary_keyword' => 'abc', 'workflow_status' => 'drafting']);
        $run = AgentRun::create(['website_id' => $w->id, 'article_id' => $a->id, 'status' => 'failed', 'cost_vnd' => 0, 'outcome_reason' => 'AI lỗi']);
        $this->actingAs($this->user());

        $r = RunReport::for($run);
        $this->assertStringContainsString('Chưa có bản nháp', $r['lines'][0]);
        $this->get(AgentRunResource::getUrl('view', ['record' => $run]))->assertOk()->assertSee('run-report', false)->assertSee('Lý do kết thúc: AI lỗi');
    }

    public function test_writer_khong_thay_so_lieu_chi_phi_cua_quan_ly(): void
    {
        $w = $this->site();
        Article::create(['website_id' => $w->id, 'primary_keyword' => 'abc', 'workflow_status' => 'needs_review']);
        $this->actingAs(User::create(['name' => 'w', 'email' => 'w@x.vn', 'password' => 'secret123', 'role' => 'writer', 'is_active' => true]));

        $this->get('/admin')->assertOk()->assertSee('Đội AI hôm nay')->assertSee('bài chờ duyệt')->assertDontSee('chi phí AI hôm nay')->assertDontSee('gợi ý bổ sung kho');
        $this->assertFalse(UiKitPage::shouldRegisterNavigation());
    }

    public function test_khoi_doi_ai_hom_nay_trong_thi_chi_mot_dong(): void
    {
        $this->site();
        $this->actingAs($this->user());

        $this->get('/admin')->assertOk()->assertDontSee('Hôm nay chưa có việc nào cần bạn xử lý.')->assertDontSee('class="at-card', false)->assertDontSee('gợi ý bổ sung kho');
    }

    public function test_tong_quan_hien_khoi_doi_ai_hom_nay(): void
    {
        $w = $this->site();
        AgentRun::create(['website_id' => $w->id, 'status' => 'blocked', 'cost_vnd' => 500]);
        Article::create(['website_id' => $w->id, 'primary_keyword' => 'abc', 'workflow_status' => 'needs_review']);
        $this->actingAs($this->user());

        $this->get('/admin')->assertOk()->assertSee('Đội AI hôm nay')->assertSee('agent-today', false)->assertSee('bài chờ duyệt')->assertSee('500');
    }
}
