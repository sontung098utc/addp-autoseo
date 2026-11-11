<?php

namespace Tests\Feature\Agent;

use App\Filament\Resources\AgentRunResource;
use App\Filament\Support\Agent\RunFlow;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** AG-S15: trang chi tiết phiên chạy: tiêu đề là tên bài, luồng theo dữ liệu thật, không bịa trường thiếu. */
class AgentRunDetailTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'approver'): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function run_(string $status, array $steps, array $run = [], string $keyword = 'toi uu seo'): AgentRun
    {
        $w = Website::query()->first() ?? Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $a = Article::create(['website_id' => $w->id, 'primary_keyword' => $keyword, 'workflow_status' => 'planned']);
        $r = AgentRun::create($run + ['website_id' => $w->id, 'article_id' => $a->id, 'trigger' => 'manual', 'status' => $status, 'trust_tier' => 'T1', 'ai_mode' => 'real', 'started_at' => now()]);
        foreach ($steps as $i => $st) {
            [$stage, $stStatus, $data] = $st + [2 => []];
            AgentRunStep::create(['agent_run_id' => $r->id, 'seq' => $i + 1, 'stage' => $stage, 'role' => 'system', 'status' => $stStatus, 'title' => 'Buoc '.$stage, 'detail' => 'chi tiet '.$stage, 'data' => $data ?: null]);
        }

        return $r->refresh();
    }

    private function page(AgentRun $r): string
    {
        auth()->check() || $this->actingAs($this->user());

        return $this->get(AgentRunResource::getUrl('view', ['record' => $r]))->assertOk()->getContent();
    }

    private function state(string $html, string $stage): string
    {
        preg_match('/data-stage="'.$stage.'" data-state="([a-z]+)"/', $html, $m);

        return $m[1] ?? 'missing';
    }

    public function test_tieu_de_la_ten_bai_khong_con_chuoi_cu(): void
    {
        $r = $this->run_('ready_for_review', [['guard', 'ok']], [], 'từ khóa chính abc');
        $html = $this->page($r);
        $this->assertStringContainsString('từ khóa chính abc', $html);
        $this->assertStringNotContainsString('Diễn biến phiên chạy', $html);
        $this->assertStringContainsString('#'.$r->id.' · Viết bài', $html);

        $v = ArticleVersion::create(['article_id' => $r->article_id, 'version' => 1, 'title' => 'Tiêu đề bản bài thật', 'slug' => 'tieu-de', 'html_content' => '<p>x</p>', 'review_status' => 'needs_review']);
        $r->update(['final_version_id' => $v->id]);
        $this->assertStringContainsString('Tiêu đề bản bài thật', $this->page($r));
    }

    public function test_tieu_de_chua_html_bi_escape(): void
    {
        $r = $this->run_('ready_for_review', [['guard', 'ok']], [], '<script>alert(1)</script><b>x</b>');
        $html = $this->page($r);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_phien_xong_co_diem_nguong_chuoi_vong_sua_va_trang_thai_chang(): void
    {
        $g = fn (int $round, int $score) => ['grade', $score >= 80 ? 'ok' : 'warn', ['score' => $score, 'round' => $round, 'threshold' => 80, 'failed' => $score < 80 ? [['code' => 'H1_01', 'severity' => 'P0', 'message' => 'Thiếu H1']] : []]];
        $r = $this->run_('ready_for_review', [
            ['guard', 'ok'], ['risk', 'ok'], ['brief', 'ok'], ['write', 'ok'], $g(0, 62), ['repair', 'ok'], $g(1, 78), ['repair', 'ok'], $g(2, 91), ['handoff', 'ok'],
        ], ['repair_rounds' => 2, 'tokens_used' => 1200, 'cost_vnd' => 5000]);
        $html = $this->page($r);

        $this->assertStringContainsString('data-testid="run-score"', $html);
        $this->assertStringContainsString('ngưỡng tham khảo 80/100', $html);
        $this->assertStringContainsString('v1 62 → sửa 1 78 → sửa 2 91', $html);
        $this->assertStringContainsString('▲ +16', $html);
        $this->assertStringContainsString('▲ +13', $html);
        foreach (['guard', 'risk', 'brief', 'write', 'grade', 'repair', 'handoff'] as $s) {
            $this->assertSame('done', $this->state($html, $s), $s);
        }
        $this->assertStringContainsString('H1_01', $html);
        $this->assertStringContainsString('Thiếu H1', $html);
    }

    public function test_vong_sua_giam_diem_hien_mui_ten_do(): void
    {
        $r = $this->run_('held', [['grade', 'warn', ['score' => 80, 'round' => 0]], ['repair', 'ok'], ['grade', 'warn', ['score' => 70, 'round' => 1]]]);
        $html = $this->page($r);
        $this->assertStringContainsString('rv-arrow--down', $html);
        $this->assertStringContainsString('▼ -10', $html);
    }

    public function test_phien_giu_chang_sau_diem_dung_mo_khong_chay(): void
    {
        $r = $this->run_('held', [['guard', 'ok'], ['risk', 'warn'], ['brief', 'ok'], ['write', 'ok'], ['grade', 'warn', ['score' => 55, 'round' => 0]], ['decision', 'ok', ['decision' => 'no_improvement', 'score_before' => 55, 'score_after' => 50]], ['handoff', 'warn']]);
        $html = $this->page($r);
        $this->assertSame('warn', $this->state($html, 'risk'));
        $this->assertSame('warn', $this->state($html, 'grade'));
        $this->assertSame('idle', $this->state($html, 'repair'));
        $this->assertStringContainsString('không chạy', $html);
        $this->assertStringContainsString('data-testid="decision"', $html);
        $this->assertStringContainsString('(trước → sau)', $html);
    }

    public function test_phien_loi_chang_cuoi_bao_loi_va_chang_giua_mo(): void
    {
        $r = $this->run_('failed', [['guard', 'ok'], ['risk', 'ok'], ['brief', 'ok'], ['handoff', 'failed']]);
        $html = $this->page($r);
        $this->assertSame('failed', $this->state($html, 'handoff'));
        $this->assertSame('idle', $this->state($html, 'write'));
        $this->assertSame('idle', $this->state($html, 'grade'));
    }

    public function test_phien_bi_chan_o_guard(): void
    {
        $r = $this->run_('blocked', [['guard', 'blocked']]);
        $html = $this->page($r);
        $this->assertSame('blocked', $this->state($html, 'guard'));
        $this->assertSame('idle', $this->state($html, 'risk'));
        $this->assertStringNotContainsString('data-testid="score-timeline"', $html);
    }

    public function test_phien_dang_chay_pulse_o_chang_dang_chay_va_chang_sau_chua_toi(): void
    {
        $r = $this->run_('running', [['guard', 'ok'], ['risk', 'ok'], ['brief', 'ok']]);
        $html = $this->page($r);
        $this->assertSame('running', $this->state($html, 'write'));
        $this->assertSame('pending', $this->state($html, 'handoff'));
        $this->assertSame('done', $this->state($html, 'brief'));
        $this->assertStringContainsString('data-live-stage="write"', $html);
    }

    public function test_su_co_thu_lai_la_nhanh_da_tu_xu_ly(): void
    {
        $r = $this->run_('ready_for_review', [['guard', 'ok'], ['risk', 'ok'], ['brief', 'ok'], ['retry', 'warn', ['attempt' => 1, 'max' => 3, 'wait_seconds' => 2, 'error' => 'timeout']], ['write', 'ok'], ['grade', 'ok', ['score' => 90, 'round' => 0]], ['handoff', 'ok']]);
        $html = $this->page($r);
        $this->assertStringContainsString('data-testid="flow-incident"', $html);
        $this->assertStringContainsString('Thử lại', $html);
        $this->assertStringContainsString('đã tự xử lý', $html);
    }

    public function test_truong_thieu_khong_vo_trang_va_khong_bia(): void
    {
        $r = $this->run_('ready_for_review', [], ['ai_mode' => 'mock']);
        $html = $this->page($r);
        $score = substr(strstr($html, 'data-testid="run-score"'), 0, 1800);
        $this->assertStringNotContainsString('/100 <', str_replace("\n", ' ', $score));
        $this->assertStringContainsString('—', $score);
        $this->assertStringNotContainsString('data-testid="score-timeline"', $html);
        $this->assertStringContainsString('AI giả lập', $html);
        $this->assertStringContainsString('Chưa nối', $html);
        $this->assertStringNotContainsString('ngưỡng tham khảo', $html);
    }

    public function test_chi_phi_so_voi_ngan_sach_va_thanh_tien_do(): void
    {
        $r = $this->run_('ready_for_review', [['guard', 'ok']], ['cost_vnd' => 25000, 'tokens_used' => 3000]);
        $flow = RunFlow::build($r, $r->steps, false, false, null);
        $this->assertSame(50, $flow['budgetPct']);
        $html = $this->page($r);
        $this->assertStringContainsString('data-testid="run-budget"', $html);
        $this->assertStringContainsString('aria-valuenow="50"', $html);
    }

    public function test_bien_tap_vien_bi_chan(): void
    {
        $r = $this->run_('ready_for_review', [['guard', 'ok']]);
        $this->actingAs($this->user('writer'));
        $this->get(AgentRunResource::getUrl('view', ['record' => $r]))->assertForbidden();
    }

    public function test_trang_phien_co_bon_tab_ngang_dung_khoa_va_mac_dinh_theo_trang_thai(): void
    {
        $done = $this->page($this->run_('ready_for_review', [['guard', 'ok']]));
        preg_match_all('/role="tab" class="ss-tab" data-key="([^"]+)"/', $done, $m);
        $this->assertSame(['dien-bien', 'bao-cao', 'chi-phi', 'du-lieu'], $m[1]);
        $this->assertMatchesRegularExpression('/studioTabs\\([\'"]bao-cao/', $done);

        foreach (['failed', 'held', 'blocked', 'running'] as $st) {
            $html = $this->page($this->run_($st, [['guard', 'ok']], $st === 'failed' ? ['outcome_reason' => 'Hết tiền'] : []));
            $this->assertMatchesRegularExpression('/studioTabs\\([\'"]dien-bien/', $html, $st);
        }
        foreach (['stage-rail', 'run-verdict', 'run-report', 'run-cost', 'run-data'] as $id) {
            $this->assertStringContainsString('data-testid="'.$id.'"', $done, $id);
        }
        $this->assertStringContainsString('Chi phí và model', $done);
    }

    public function test_tab_dien_bien_hien_du_chang_dung_trang_thai_va_ket_luan_mot_dong(): void
    {
        $r = $this->run_('failed', [['guard', 'ok'], ['risk', 'ok'], ['brief', 'failed']], ['outcome_reason' => 'Nhà cung cấp lỗi', 'cost_vnd' => 1500]);
        $html = $this->page($r);
        preg_match_all('/data-stage="([a-z]+)" data-state="/', $html, $m);
        $this->assertSame(RunFlow::main(), $m[1]);
        $this->assertSame('done', $this->state($html, 'guard'));
        $this->assertSame('failed', $this->state($html, 'brief'));
        $this->assertSame('idle', $this->state($html, 'write'));
        $verdict = substr(strstr($html, 'data-testid="run-verdict"'), 0, 600);
        $this->assertStringContainsString('Lỗi ở «Dàn ý»', $verdict);
        $this->assertStringContainsString('Nhà cung cấp lỗi', $verdict);
        $this->assertStringContainsString('1.500 ₫', $verdict);
        $this->assertStringContainsString('data-testid="run-verdict-action"', $html);
        // một khung chi tiết cho mỗi chặng, chỉ chặng lỗi mở sẵn
        $this->assertMatchesRegularExpression('/sel: [\'"]brief/', $html);
    }

    public function test_vong_sua_hien_so_lan_o_noi_cham_sua(): void
    {
        $g = fn (int $round, int $score) => ['grade', $score >= 80 ? 'ok' : 'warn', ['score' => $score, 'round' => $round, 'threshold' => 80]];
        $r = $this->run_('ready_for_review', [['guard', 'ok'], ['write', 'ok'], $g(0, 60), ['repair', 'ok'], $g(1, 70), ['repair', 'ok'], $g(2, 90), ['handoff', 'ok']], ['repair_rounds' => 2]);
        $html = $this->page($r);
        $this->assertStringContainsString('data-loop', $html);
        $this->assertStringContainsString('↻ 2 vòng', $html);
    }

    public function test_chang_checkpoint_hien_cho_ban(): void
    {
        $r = $this->run_('held', [['guard', 'ok'], ['risk', 'ok'], ['checkpoint', 'warn', ['waiting' => true]]], ['cost_vnd' => 800]);
        $html = $this->page($r);
        $this->assertSame('waiting', $this->state($html, 'checkpoint'));
        $this->assertStringContainsString('data-testid="flow-waiting"', $html);
        $this->assertStringContainsString('Chờ bạn', $html);
        $this->assertStringContainsString('Dừng ở «', substr(strstr($html, 'data-testid="run-verdict"'), 0, 600));
        $order = RunFlow::build($r, $r->steps, false, false, null)['nodes'];
        $codes = array_column($order, 'code');
        $this->assertContains('checkpoint', $codes);
        $this->assertSame(array_search('risk', $codes, true) + 1, array_search('checkpoint', $codes, true));
    }

    public function test_chi_phi_tung_chang_va_du_lieu_chi_quan_tri(): void
    {
        $r = $this->run_('ready_for_review', [['guard', 'ok']], ['input_refs' => ['purpose' => 'xyz'], 'output_ref' => ['kq' => 1]]);
        $this->actingAs($this->user('approver'));
        $html = $this->page($r);
        $this->assertStringContainsString('data-cost-stage="guard"', $html);
        $this->assertStringContainsString('Dữ liệu kỹ thuật chỉ hiện cho quản trị', $html);
        $this->assertStringNotContainsString('data-testid="run-input-ref"', $html);
    }

    public function test_quan_tri_thay_du_lieu_tho(): void
    {
        $r = $this->run_('ready_for_review', [['guard', 'ok']], ['input_refs' => ['purpose' => 'xyz']]);
        $this->actingAs($this->user('admin'));
        $html = $this->page($r);
        $this->assertStringContainsString('data-testid="run-input-ref"', $html);
        $this->assertStringContainsString('xyz', substr(strstr($html, 'data-testid="run-input-ref"'), 0, 300));
    }
}
