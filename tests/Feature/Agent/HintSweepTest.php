<?php

namespace Tests\Feature\Agent;

use App\Filament\Resources\AgentRunResource;
use App\Filament\Resources\CampaignResource;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\Campaign;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** AG-S18: chữ giải thích phụ nằm trong (i)/(!), việc cần làm vẫn hiện chữ; Chiến dịch được giải thích rõ. */
class HintSweepTest extends TestCase
{
    use RefreshDatabase;

    private function login(): void
    {
        $this->actingAs(User::create(['name' => 'a', 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => 'approver', 'is_active' => true]));
    }

    private function site(): Website
    {
        return Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    public function test_tab_chien_dich_co_hint_khai_niem_va_khong_con_doan_mo_ta_dai(): void
    {
        $this->login();
        $html = $this->get(CampaignResource::getUrl('index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="help"', $html);
        $this->assertStringContainsString('Chiến dịch không phải chuỗi phiên', $html);
        $this->assertStringNotContainsString('fi-header-subheading', $html);
    }

    public function test_bang_chien_dich_dem_that_bai_da_giao_cho_duyet_giu_nhap(): void
    {
        $this->login();
        $site = $this->site();
        $c = Campaign::create(['website_id' => $site->id, 'name' => 'CD1', 'status' => 'paused', 'daily_limit' => 2, 'keywords' => ['a']]);
        foreach (['ready_for_review', 'ready_for_review', 'held'] as $st) {
            $art = Article::create(['website_id' => $site->id, 'primary_keyword' => 'k'.uniqid(), 'workflow_status' => 'planned']);
            AgentRun::create(['website_id' => $site->id, 'article_id' => $art->id, 'campaign_id' => $c->id, 'status' => $st, 'ai_mode' => 'real', 'started_at' => now(), 'finished_at' => now()]);
        }
        $this->get(CampaignResource::getUrl('index'))->assertOk()->assertSee('Đã giao 3 bài · 2 chờ duyệt · 1 giữ nháp');
    }

    public function test_form_chien_dich_dung_thuat_ngu_de_hieu_va_hint(): void
    {
        $this->login();
        $html = $this->get(CampaignResource::getUrl('create'))->assertOk()->assertSee('Nguồn từ khóa')->assertSee('Kế hoạch nội dung')->assertSee('Danh sách nhập tay')->getContent();
        $this->assertStringNotContainsString('Chọn một chế độ là đủ', $html);
    }

    public function test_danh_sach_phien_chay_chuyen_mo_ta_vao_hint(): void
    {
        $this->login();
        $html = $this->get(AgentRunResource::getUrl('index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-hint="info"', $html);
        $this->assertStringContainsString('MỘT bài', $html);
        $this->assertStringNotContainsString('fi-header-subheading', $html);
    }

    public function test_tong_quan_canh_bao_chua_ket_noi_ai_van_hien_chu(): void
    {
        $this->login();
        $this->get('/admin/doi-ai/tong-quan')->assertOk()->assertSee('Chưa kết nối AI, lượt tự động không chạy')->assertDontSee('lượt tự động chưa chạy được');
    }

    public function test_pipeline_va_ky_nang_dung_hint(): void
    {
        $this->login();
        $p = $this->get('/admin/doi-ai/pipeline')->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="help"', $p);
        $this->assertStringContainsString('Chạy lại / Tạm dừng pipeline: chưa nối', $p);
        $k = $this->get('/admin/doi-ai/ky-nang')->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="help"', $k);
    }
}
