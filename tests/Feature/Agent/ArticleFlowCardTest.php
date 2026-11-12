<?php

namespace Tests\Feature\Agent;

use App\Filament\Resources\AgentRunResource;
use App\Filament\Resources\ArticleResource\Pages\ViewArticle;
use App\Filament\Support\Agent\RunActivity;
use App\Filament\Support\Schedule\VnTime;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Article;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Tab Tổng quan của một bài hiện luồng chạy chi tiết của bài (thay khối 6 ô), có nút mở trang phiên; trạng thái/giờ không sai. */
class ArticleFlowCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::create(['name' => 'a', 'email' => 'a@x.vn', 'password' => 'secret123', 'role' => 'admin', 'is_active' => true]));
    }

    private function article(): Article
    {
        $w = Website::query()->first() ?? Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);

        return Article::create(['website_id' => $w->id, 'primary_keyword' => 'thuoc vien nen', 'workflow_status' => 'planned']);
    }

    private function makeRun(Article $a, string $status, array $stages): AgentRun
    {
        $run = AgentRun::create(['website_id' => $a->website_id, 'article_id' => $a->id, 'trigger' => 'manual', 'status' => $status, 'trust_tier' => 'T1', 'ai_mode' => 'real', 'started_at' => now()]);
        foreach ($stages as $i => $st) {
            AgentRunStep::create(['agent_run_id' => $run->id, 'seq' => $i + 1, 'stage' => $st, 'role' => 'system', 'status' => 'ok', 'title' => $st, 'detail' => 'x']);
        }

        return $run->refresh();
    }

    public function test_phien_dang_chay_hien_luong_chi_tiet_va_nut_xem_chi_tiet(): void
    {
        $a = $this->article();
        $run = $this->makeRun($a, 'running', ['guard', 'risk', 'brief']);

        Livewire::test(ViewArticle::class, ['record' => $a->getRouteKey()])
            ->assertSeeHtml('data-testid="aw-flow"')
            ->assertSeeHtml('data-state="running"')
            ->assertSeeHtml('data-stage="write"')
            ->assertSee('Xem chi tiết luồng')
            ->assertSeeHtml('href="'.AgentRunResource::getUrl('view', ['record' => $run]).'"')
            ->assertDontSeeHtml('data-testid="aw-chain"');
    }

    public function test_phien_loi_hien_dung_trang_thai_khong_noi_co_ban_nhap(): void
    {
        $a = $this->article();
        $this->makeRun($a, 'failed', ['guard', 'risk']);

        Livewire::test(ViewArticle::class, ['record' => $a->getRouteKey()])
            ->assertSee('Lỗi, chưa có bản nháp')
            ->assertDontSee('Chờ chuyên gia xem lại');
    }

    public function test_bai_chua_co_phien_van_hien_khoi_tien_trinh_cu(): void
    {
        $a = $this->article();

        Livewire::test(ViewArticle::class, ['record' => $a->getRouteKey()])
            ->assertSeeHtml('data-testid="aw-chain"')
            ->assertDontSeeHtml('data-testid="aw-flow"');
    }

    public function test_gio_cua_cot_thuong_doc_theo_app_timezone_con_cot_utc_van_la_utc(): void
    {
        config(['app.timezone' => 'Asia/Ho_Chi_Minh']);
        date_default_timezone_set('Asia/Ho_Chi_Minh');
        $a = $this->article();
        $run = $this->makeRun($a, 'running', ['guard']);

        // Ghi bằng now() (giờ VN) thì thời lượng phải vài giây, không phải 7 giờ.
        $this->assertLessThan(60, RunActivity::elapsedSeconds($run));
        $this->assertTrue(RunActivity::isLive($run));

        $p = new \App\Models\Publication;
        $p->setRawAttributes(['scheduled_at_utc' => '2026-11-12 02:00:00']);
        $this->assertSame('09:00', VnTime::fromRaw($p, 'scheduled_at_utc')?->setTimezone(VnTime::TZ)->format('H:i'));
    }
}
