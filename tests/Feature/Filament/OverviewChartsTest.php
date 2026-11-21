<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Support\Dashboard\ChartQueries as Q;
use App\Filament\Widgets\Charts\AiCostTrendChart;
use App\Filament\Widgets\Charts\CostByRoleChart;
use App\Filament\Widgets\Charts\CostGaugeChart;
use App\Filament\Widgets\Charts\KpiOverviewWidget;
use App\Filament\Widgets\Charts\LifecycleChart;
use App\Filament\Widgets\Charts\PublicationsTrendChart;
use App\Filament\Widgets\Charts\RepairRoundsChart;
use App\Filament\Widgets\Charts\RunOutcomesChart;
use App\Filament\Widgets\Charts\StageDurationChart;
use App\Filament\Widgets\Charts\UpcomingScheduleChart;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class OverviewChartsTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        // 10:00 giờ VN ngày 06/11/2026 (Thứ Sáu) = 03:00 UTC
        Carbon::setTestNow(Carbon::parse('2026-11-06 10:00:00', 'Asia/Ho_Chi_Minh'));
        $this->site = Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Model::reguard();
        parent::tearDown();
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function pub(string $status, string $utc): Publication
    {
        $a = Article::create(['website_id' => $this->site->id, 'primary_keyword' => uniqid(), 'workflow_status' => 'published']);
        $v = ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 't', 'slug' => uniqid(), 'html_content' => '<p>x</p>', 'review_status' => 'approved']);

        return Publication::create([
            'website_id' => $this->site->id, 'article_version_id' => $v->id, 'publication_key' => uniqid(), 'scheduled_at_utc' => $utc,
            'timezone' => 'Asia/Ho_Chi_Minh', 'status' => $status, 'published_at_utc' => $status === 'published' ? $utc : null,
        ]);
    }

    private function agentRun(string $status, array $x = []): AgentRun
    {
        return AgentRun::create(array_merge(['website_id' => $this->site->id, 'trigger' => 'manual', 'status' => $status, 'started_at' => now()], $x));
    }

    public function test_ngay_gom_theo_gio_vn_khong_phai_utc(): void
    {
        // 18:00 UTC ngày 05/11 = 01:00 giờ VN ngày 06/11 → phải tính vào 06/11, không phải 05/11
        $this->pub('published', '2026-11-05 18:00:00');
        $this->pub('published', '2026-11-05 16:59:00'); // 23:59 VN ngày 05/11
        $this->pub('failed', '2026-11-06 01:00:00');
        $s = Q::publicationsByDay(3, now());
        $this->assertSame(['04/11', '05/11', '06/11'], $s['labels']);
        $this->assertSame([0, 1, 1], $s['published']);
        $this->assertSame([0, 0, 1], $s['failed']);
        $this->assertSame(3, $s['total']);
    }

    public function test_lich_sap_toi_chi_dem_da_xep_lich_trong_7_ngay(): void
    {
        $this->pub('scheduled', '2026-11-06 06:00:00'); // hôm nay
        $this->pub('scheduled', '2026-11-08 06:00:00');
        $this->pub('scheduled', '2026-11-08 07:00:00');
        $this->pub('scheduled', '2026-11-13 06:00:00'); // ngày thứ 8: ngoài khung
        $this->pub('published', '2026-11-07 06:00:00'); // không phải lịch
        $s = Q::upcomingSchedule(7, now());
        $this->assertSame([1, 0, 2, 0, 0, 0, 0], $s['counts']);
        $this->assertSame(3, $s['total']);
    }

    public function test_vong_doi_dem_theo_trang_thai_va_tach_bai_bi_tu_choi(): void
    {
        foreach (['planned', 'needs_review', 'needs_review', 'published', 'rejected'] as $st) {
            Article::create(['website_id' => $this->site->id, 'primary_keyword' => uniqid(), 'workflow_status' => $st]);
        }
        $l = Q::lifecycle();
        $this->assertSame([1, 0, 2, 0, 0, 1], $l['counts']);
        $this->assertSame(1, $l['rejected']);
        $this->assertSame(5, $l['total']);
    }

    public function test_ket_qua_phien_gop_nhom_va_ty_le_chi_tinh_phien_da_ket_thuc(): void
    {
        foreach (['ready_for_review', 'ready_for_review', 'done', 'failed', 'held', 'blocked', 'running'] as $st) {
            $this->agentRun($st);
        }
        $r = Q::runOutcomes(7, now());
        $this->assertSame(['ok' => 3, 'held' => 1, 'failed' => 1, 'blocked' => 1], $r['groups']);
        $this->assertSame(1, $r['running']);
        $this->assertSame(7, $r['total']);
        $this->assertSame(50.0, $r['successRate']); // 3/6, không tính phiên đang chạy
    }

    public function test_chua_co_phien_thi_ty_le_la_null_khong_phai_0(): void
    {
        $this->assertNull(Q::runOutcomes(7, now())['successRate']);
        $this->assertNull(Q::repairRounds(30, now())['avg']);
    }

    public function test_chi_phi_theo_ngay_gom_theo_gio_vn(): void
    {
        $this->agentRun('done', ['cost_vnd' => 1500, 'tokens_used' => 100]);
        $this->agentRun('done', ['cost_vnd' => 500, 'tokens_used' => 50, 'started_at' => now()->subDay()]);
        $c = Q::costByDay(2, now());
        $this->assertSame([500.0, 1500.0], $c['cost']);
        $this->assertSame(2000.0, $c['total']);
        $this->assertSame(150, $c['tokens']);
    }

    public function test_chi_phi_theo_vai_tro_bo_vai_tro_khong_co_so_lieu(): void
    {
        $r = $this->agentRun('done');
        AgentRunStep::create(['agent_run_id' => $r->id, 'seq' => 1, 'stage' => 'write', 'role' => 'writer', 'status' => 'done', 'title' => 'a', 'cost_vnd' => 800, 'prompt_tokens' => 100, 'completion_tokens' => 50]);
        AgentRunStep::create(['agent_run_id' => $r->id, 'seq' => 2, 'stage' => 'grade', 'role' => 'editor', 'status' => 'done', 'title' => 'b', 'cost_vnd' => 200, 'prompt_tokens' => 10, 'completion_tokens' => 5]);
        AgentRunStep::create(['agent_run_id' => $r->id, 'seq' => 3, 'stage' => 'guard', 'role' => 'system', 'status' => 'done', 'title' => 'c', 'cost_vnd' => 0]);
        $rows = Q::costByRole(7, now());
        $this->assertSame(['writer', 'editor'], array_column($rows, 'role'));
        $this->assertSame(800.0, $rows[0]['cost']);
        $this->assertSame(150, $rows[0]['prompt'] + $rows[0]['completion']);
    }

    public function test_chi_phi_theo_model_gop_theo_model_va_gom_model_trong(): void
    {
        $r = $this->agentRun('done');
        AgentRunStep::create(['agent_run_id' => $r->id, 'seq' => 1, 'stage' => 'write', 'role' => 'w', 'status' => 'done', 'title' => 'a', 'model' => 'claude-x', 'cost_vnd' => 700, 'prompt_tokens' => 100, 'completion_tokens' => 20]);
        AgentRunStep::create(['agent_run_id' => $r->id, 'seq' => 2, 'stage' => 'write', 'role' => 'w', 'status' => 'done', 'title' => 'b', 'model' => 'claude-x', 'cost_vnd' => 300, 'prompt_tokens' => 10, 'completion_tokens' => 0]);
        AgentRunStep::create(['agent_run_id' => $r->id, 'seq' => 3, 'stage' => 'grade', 'role' => 'e', 'status' => 'done', 'title' => 'c', 'model' => 'gemini-y', 'cost_vnd' => 50, 'prompt_tokens' => 5, 'completion_tokens' => 5]);
        AgentRunStep::create(['agent_run_id' => $r->id, 'seq' => 4, 'stage' => 'guard', 'role' => 's', 'status' => 'done', 'title' => 'd', 'cost_vnd' => 0]); // không chi phí, không token: bỏ
        $rows = Q::costByModel(7, now());
        $this->assertSame(['claude-x', 'gemini-y'], array_column($rows, 'model'));
        $this->assertSame(1000.0, $rows[0]['cost']);
        $this->assertSame(130, $rows[0]['tokens']);
        $this->assertSame(2, $rows[0]['steps']);
        $this->actingAs($this->user('admin'));
        Livewire::test(\App\Filament\Widgets\Charts\CostByModelChart::class)->assertSee('claude-x');
    }

    public function test_chi_phi_theo_provider_doc_provider_id_va_khong_doan_phan_chua_ghi(): void
    {
        $p1 = \App\Models\AiProvider::create(['name' => 'Claude', 'kind' => 'anthropic']);
        $p2 = \App\Models\AiProvider::create(['name' => 'Gemini', 'kind' => 'gemini']);
        $r = $this->agentRun('done');
        $mk = fn (int $seq, float $cost, ?array $data) => AgentRunStep::create(['agent_run_id' => $r->id, 'seq' => $seq, 'stage' => 'write', 'role' => 'w', 'status' => 'done', 'title' => 't', 'cost_vnd' => $cost, 'prompt_tokens' => 10, 'completion_tokens' => 5, 'data' => $data]);
        $mk(1, 600, ['provider_id' => $p1->id, 'x' => 1]);
        $mk(2, 100, ['provider_id' => $p1->id]);          // provider_id ở cuối JSON
        $mk(3, 200, ['provider_id' => $p2->id, 'y' => 2]);
        $mk(4, 100, ['provider_id' => $p1->id * 10 + 7]); // id lạ có chứa chữ số của id thật: không được lẫn
        $mk(5, 50, null);                                 // chưa ghi
        $d = Q::costByProvider(7, now());
        $this->assertSame(['Claude', 'Gemini'], array_column($d['rows'], 'name'));
        $this->assertSame(700.0, $d['rows'][0]['cost']);
        $this->assertSame(2, $d['rows'][0]['steps']);
        $this->assertSame(200.0, $d['rows'][1]['cost']);
        $this->assertSame(150.0, $d['unrecorded']['cost']);   // 100 (provider đã xóa) + 50
        $this->assertSame(1050.0, $d['total']);
        $this->assertSame(86.0, $d['coverage']);              // 900/1050
        $this->actingAs($this->user('admin'));
        Livewire::test(\App\Filament\Widgets\Charts\CostByProviderChart::class)->assertSee('Claude');
    }

    public function test_thoi_luong_khau_chi_tinh_buoc_du_gio_va_cham_nhat_truoc(): void
    {
        $r = $this->agentRun('done');
        $t = now();
        AgentRunStep::create(['agent_run_id' => $r->id, 'seq' => 1, 'stage' => 'write', 'role' => 'w', 'status' => 'done', 'title' => 'a', 'started_at' => $t, 'finished_at' => $t->copy()->addSeconds(60)]);
        AgentRunStep::create(['agent_run_id' => $r->id, 'seq' => 2, 'stage' => 'write', 'role' => 'w', 'status' => 'done', 'title' => 'a', 'started_at' => $t, 'finished_at' => $t->copy()->addSeconds(20)]);
        AgentRunStep::create(['agent_run_id' => $r->id, 'seq' => 3, 'stage' => 'grade', 'role' => 'e', 'status' => 'done', 'title' => 'b', 'started_at' => $t, 'finished_at' => $t->copy()->addSeconds(5)]);
        AgentRunStep::create(['agent_run_id' => $r->id, 'seq' => 4, 'stage' => 'images', 'role' => 'e', 'status' => 'running', 'title' => 'c', 'started_at' => $t]); // chưa kết thúc: bỏ
        $d = Q::stageDurations(30, now());
        $this->assertSame(['write', 'grade'], array_column($d, 'stage'));
        $this->assertSame(40.0, $d[0]['seconds']);
        $this->assertSame(2, $d[0]['steps']);
    }

    public function test_so_voi_ky_truoc_khong_chia_cho_0(): void
    {
        $this->assertNull(Q::deltaPercent(5, 0));
        $this->assertSame(50.0, Q::deltaPercent(15, 10));
        $this->assertSame(-25.0, Q::deltaPercent(3, 4));
    }

    public function test_dang_trong_tuan_so_cung_ky_tuan_truoc(): void
    {
        // Thứ Sáu 06/11: tuần này (T2 02/11 → T6) và tuần trước cùng 5 ngày (T2 26/10 → T6 30/10)
        $this->pub('published', '2026-11-03 03:00:00');
        $this->pub('published', '2026-11-05 03:00:00');
        $this->pub('published', '2026-10-28 03:00:00');
        $this->pub('published', '2026-10-31 03:00:00'); // Thứ Bảy tuần trước: ngoài cùng kỳ
        $w = Q::publishedWeekly(now());
        $this->assertSame(2, $w['thisWeek']);
        $this->assertSame(1, $w['lastWeek']);
        $this->assertCount(7, $w['spark']);
    }

    public function test_tran_chi_phi_chi_tinh_chien_dich_dang_chay(): void
    {
        $this->assertSame(0.0, Q::dailyCap());
    }

    public function test_dashboard_ve_duoc_du_bieu_do_voi_du_lieu(): void
    {
        $this->pub('published', '2026-11-05 03:00:00');
        $this->pub('scheduled', '2026-11-07 03:00:00');
        $this->agentRun('done', ['cost_vnd' => 1234]);
        $this->actingAs($this->user('admin'));

        foreach ([LifecycleChart::class, PublicationsTrendChart::class, UpcomingScheduleChart::class, AiCostTrendChart::class,
            RunOutcomesChart::class, CostByRoleChart::class, StageDurationChart::class, RepairRoundsChart::class] as $w) {
            Livewire::test($w)->assertOk();
        }
        Livewire::test(KpiOverviewWidget::class)->assertOk()->assertSee('Đã đăng tuần này')->assertSee('Chi phí AI hôm nay');
    }

    public function test_bieu_do_rong_hien_thong_bao_chua_co_du_lieu_khong_dien_so_gia(): void
    {
        $this->actingAs($this->user('admin'));
        Livewire::test(PublicationsTrendChart::class)->assertSee('Chưa có lượt đăng nào');
        Livewire::test(UpcomingScheduleChart::class)->assertSee('Chưa có bài nào được xếp lịch');
        Livewire::test(AiCostTrendChart::class)->assertSee('Chưa có chi phí AI');
        Livewire::test(RunOutcomesChart::class)->assertSee('Chưa có phiên chạy nào');
    }

    public function test_bieu_do_chi_phi_chi_hien_cho_nguoi_duyet(): void
    {
        $this->actingAs($this->user('writer'));
        $this->assertFalse(AiCostTrendChart::canView());
        $this->assertFalse(CostGaugeChart::canView());
        $this->actingAs($this->user('admin'));
        $this->assertTrue(AiCostTrendChart::canView());
        $this->assertFalse(CostGaugeChart::canView()); // chưa đặt trần ngày thì không có gì để so
    }

    public function test_trang_tong_quan_chua_cac_bieu_do(): void
    {
        $ids = array_map(fn ($c) => $c, (new Dashboard)->getWidgets());
        $this->assertContains(KpiOverviewWidget::class, $ids);
        $this->assertContains(LifecycleChart::class, $ids);
        $this->assertContains(\App\Filament\Widgets\DecisionsWidget::class, $ids); // danh sách việc cần xử lý vẫn còn
    }
}
