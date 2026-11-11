<?php

namespace Tests\Feature\Agent;

use App\Filament\Pages\TrustPolicy;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Article;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Pipeline\PipelineBlueprint;
use App\Services\Agent\Trust\RoleMatrix;
use App\Services\Agent\Trust\TrustGate;
use App\Services\Agent\Trust\TrustMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** AG-S12: tab Chính sách (thang tin cậy, chỉ số thật, khóa Mức 2, ai làm gì) và tab Pipeline (9 giai đoạn trung thực). */
class TrustPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function run_(string $status, array $attrs = []): AgentRun
    {
        static $site = null;
        $site = Website::query()->first() ?? Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $article = Article::create(['website_id' => $site->id, 'primary_keyword' => 'kw '.uniqid(), 'workflow_status' => 'planned']);

        return AgentRun::create($attrs + ['website_id' => $site->id, 'article_id' => $article->id, 'status' => $status, 'ai_mode' => 'real',
            'started_at' => now()->subMinutes(10), 'finished_at' => now()->subMinutes(8)]);
    }

    public function test_khong_co_phien_thi_chua_co_du_lieu_khong_chia_0(): void
    {
        $m = TrustMetrics::compute(now()->subDays(30));
        foreach (['completion_rate', 'repair_success_rate', 'avg_repair_rounds', 'hold_rate', 'fail_rate', 'cost_per_draft', 'seconds_to_draft', 'mock_in_drafts'] as $k) {
            $this->assertNull($m[$k]['value'], $k);
        }
        $this->actingAs($this->user('approver'));
        $this->get('/admin/chinh-sach-tin-cay')->assertOk()->assertSee('Chưa có dữ liệu')->assertDontSee('(0 phiên)');
        // Gói B #6: một trạng thái trống duy nhất thay cho từng dòng "Chưa có dữ liệu" (7 chỉ số + 3 chưa đo được).
        $html = $this->get('/admin/chinh-sach-tin-cay')->getContent();
        $this->assertSame(1, substr_count(strip_tags(substr($html, 0, strpos($html, 'Điều kiện để lên Mức 2'))), 'Chưa có dữ liệu'));
        $this->assertStringContainsString('data-testid="metrics-empty"', $html);
        $this->assertStringNotContainsString('data-m="completion_rate"', $html);
    }

    public function test_chi_hien_chi_so_co_so(): void
    {
        $this->run_('ready_for_review', ['repair_rounds' => 0, 'cost_vnd' => 1000]);
        $this->actingAs($this->user('approver'));
        $html = $this->get('/admin/chinh-sach-tin-cay')->getContent();
        $this->assertStringContainsString('data-m="completion_rate"', $html);
        $this->assertStringNotContainsString('data-testid="metrics-empty"', $html);
        $this->assertStringContainsString('Chưa đo được: Kiểm mẫu sau đăng', $html);
    }

    public function test_tinh_dung_tren_du_lieu_mau(): void
    {
        $this->run_('ready_for_review', ['repair_rounds' => 0, 'cost_vnd' => 1000]);
        $this->run_('ready_for_review', ['repair_rounds' => 2, 'cost_vnd' => 3000]);
        $this->run_('held', ['repair_rounds' => 3, 'cost_vnd' => 2000]);
        $this->run_('failed', ['repair_rounds' => 0, 'cost_vnd' => 0]);
        $this->run_('blocked');   // không tính
        $this->run_('running');   // không tính
        $m = TrustMetrics::compute(now()->subDays(30));

        $this->assertSame(4, $m['finished']['n']);
        $this->assertEqualsWithDelta(0.5, $m['completion_rate']['value'], 1e-9);
        $this->assertEqualsWithDelta(1 / 2, $m['repair_success_rate']['value'], 1e-9);   // 2 phiên có sửa: 1 đạt
        $this->assertEqualsWithDelta(1.25, $m['avg_repair_rounds']['value'], 1e-9);
        $this->assertEqualsWithDelta(0.25, $m['hold_rate']['value'], 1e-9);
        $this->assertEqualsWithDelta(0.25, $m['fail_rate']['value'], 1e-9);
        $this->assertEqualsWithDelta(3000.0, $m['cost_per_draft']['value'], 1e-9);        // 6000 chi / 2 bản nháp
        $this->assertEqualsWithDelta(120.0, $m['seconds_to_draft']['value'], 1.0);
        $this->assertEquals(0.0, $m['mock_in_drafts']['value']);
    }

    public function test_chi_so_chua_do_duoc_luon_la_chua_co_du_lieu(): void
    {
        $this->assertSame(['Kiểm mẫu sau đăng', 'Số lần gỡ bài', 'Hiệu quả Search Console'], array_keys(TrustMetrics::unmeasured()));
    }

    public function test_muc_2_khong_bao_gio_dat_khi_thieu_van_ban_chinh_sach(): void
    {
        // 25 phiên đều đạt, tự sửa đều thành công, không giả lập: các điều kiện số liệu đều "đạt".
        for ($i = 0; $i < 25; $i++) {
            $this->run_('ready_for_review', ['repair_rounds' => 1]);
        }
        $gate = TrustGate::evaluate(TrustMetrics::compute());
        $states = array_column($gate['conditions'], 'state', 'label');
        $this->assertSame('met', $states['Tỉ lệ hoàn tất không cần người']);
        $this->assertSame('met', $states['Tỉ lệ tự sửa thành công']);
        $this->assertSame('locked', $states['Văn bản chính sách tự đăng của bạn']);
        $this->assertSame('locked', $states['Website cho phép gỡ bài']);
        $this->assertFalse($gate['canUpgrade']);
        $this->assertGreaterThanOrEqual(2, $gate['unmet']);

        $this->actingAs($this->user('admin'));
        $this->get('/admin/chinh-sach-tin-cay')->assertOk()->assertSee('văn bản chính sách của bạn')->assertDontSee('Nâng lên Mức 2</', false)->assertDontSee('wire:click="upgrade');
    }

    public function test_it_mau_thi_khong_ket_luan_dat(): void
    {
        $this->run_('ready_for_review');
        $gate = TrustGate::evaluate(TrustMetrics::compute());
        $this->assertSame('nodata', $gate['conditions'][0]['state']);
    }

    public function test_bang_quyen_khop_policies_that(): void
    {
        $rows = collect(RoleMatrix::rows())->keyBy('label');
        $this->assertSame(['admin' => true, 'approver' => true, 'writer' => true], $rows['Xem và viết bài']['cells']);
        $this->assertSame(['admin' => true, 'approver' => true, 'writer' => false], $rows['Duyệt / từ chối bài']['cells']);
        $this->assertSame(['admin' => true, 'approver' => true, 'writer' => false], $rows['Xem lịch xuất bản']['cells']);
        $this->assertSame(['admin' => true, 'approver' => false, 'writer' => false], $rows['Xóa bài']['cells']);
        $this->assertSame(['admin' => true, 'approver' => false, 'writer' => false], $rows['Quản lý website kết nối']['cells']);
        // Biên tập viên vào cụm để dùng tab Kế hoạch; các tab khác tự kiểm quyền.
        $this->assertSame(['admin' => true, 'approver' => true, 'writer' => true], $rows['Xem hub Đội AI (cả trang này)']['cells']);
        $this->assertSame(['admin' => true, 'approver' => false, 'writer' => false], $rows['Nhập / đổi khóa AI']['cells']);
        $this->assertSame(['admin' => false, 'approver' => false, 'writer' => false], $rows['Nâng lên Mức 2 (tự đăng)']['cells']);
    }

    public function test_bang_quyen_khong_lam_doi_nguoi_dang_nhap(): void
    {
        $u = $this->user('approver');
        $this->actingAs($u);
        RoleMatrix::rows();
        $this->assertTrue(auth()->user()->is($u));
    }

    public function test_quyen_truy_cap_hai_tab(): void
    {
        $this->actingAs($this->user('approver'));
        $this->get('/admin/chinh-sach-tin-cay')->assertOk();
        $this->get('/admin/doi-ai/pipeline')->assertOk();
    }

    public function test_bien_tap_vien_bi_chan_o_hai_tab(): void
    {
        $this->actingAs($this->user('writer'));
        $this->get('/admin/chinh-sach-tin-cay')->assertForbidden();
        $this->get('/admin/doi-ai/pipeline')->assertForbidden();
    }

    public function test_trang_chinh_sach_hien_nhom_chu_de_va_bac_hien_tai(): void
    {
        $this->actingAs($this->user('approver'));
        $this->get('/admin/chinh-sach-tin-cay')->assertOk()
            ->assertSee('Đang áp dụng: Mức 1 (T1)')->assertSee('Chỉ lưu nội bộ')->assertSee('Mở rộng')
            ->assertSee('suc khoe', false)->assertSee('Cao (cần chuyên gia duyệt)');
    }

    public function test_pipeline_co_9_giai_doan_va_khong_giai_doan_nao_dang_hoat_dong_khi_thieu_bang_chung(): void
    {
        $stages = PipelineBlueprint::stages();
        $this->assertCount(9, $stages);
        foreach ($stages as $s) {
            $this->assertNotSame('active', $s['readiness'], $s['title']);
        }
        $by = collect($stages)->keyBy('id');
        $this->assertSame('demo', $by['audit']['readiness']);
        $this->assertSame('demo', $by['keywords']['readiness']);
        $this->assertSame('not_connected', $by['measure']['readiness']);
        $this->assertSame('in_development', $by['write']['readiness']);
        $this->assertSame('in_development', $by['quality']['readiness']);
        $this->assertSame('in_development', $by['publish']['readiness']);
    }

    public function test_pipeline_dem_phien_that_va_nut_chay_lai_chua_noi(): void
    {
        $run = $this->run_('ready_for_review');
        AgentRunStep::create(['agent_run_id' => $run->id, 'seq' => 1, 'stage' => 'grade', 'role' => 'editor', 'status' => 'ok', 'title' => 't']);
        $by = collect(PipelineBlueprint::stages())->keyBy('id');
        $this->assertSame(1, $by['quality']['runs']);
        $this->assertNull($by['audit']['runs']);

        $this->actingAs($this->user('approver'));
        $this->get('/admin/doi-ai/pipeline')->assertOk()
            ->assertDontSee('Chạy lại / Tạm dừng pipeline: chưa nối', false)
            ->assertDontSee('Chạy toàn bộ')->assertDontSee('Bản đồ 9 giai đoạn');
        $this->assertFalse(PipelineBlueprint::controlsWired());
    }

    public function test_trang_chinh_sach_render_bang_livewire(): void
    {
        $this->actingAs($this->user('approver'));
        Livewire::test(TrustPolicy::class)->assertSuccessful();
    }
}
