<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\DigestPage;
use App\Filament\Support\Digest\DigestQueries;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use App\Services\Publishing\EmergencyStopService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * AG-U19 (v1): Bản tin hằng ngày từ dữ liệu THẬT. Không bịa "AI đã tự xử lý" hay hiệu quả SEO.
 */
class DigestTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        app(EmergencyStopService::class)->resetForTesting();
        $this->site = Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        app(EmergencyStopService::class)->resetForTesting();
        parent::tearDown();
    }

    /** 07/11/2026 10:00 giờ VN = 03:00 UTC → "hôm qua" là 06/11 theo giờ VN. */
    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-11-07 03:00:00', 'UTC');
    }

    /** Cột Eloquent tự ghi (created_at, reviewed_at, started_at) lưu theo app.timezone; test viết mốc UTC cho dễ đọc rồi đổi sang đó. */
    private function appTime(?string $utc): ?string
    {
        return $utc === null ? null : CarbonImmutable::parse($utc, 'UTC')->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s');
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function version(string $review, string $createdUtc, float $cost = 0, ?string $reviewedUtc = null, ?User $by = null): ArticleVersion
    {
        $this->n++;
        $a = Article::create(['website_id' => $this->site->id, 'created_by' => $by?->id, 'primary_keyword' => "kw{$this->n}", 'workflow_status' => $review === 'approved' ? 'approved' : 'needs_review']);
        $v = ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => "t{$this->n}", 'slug' => "s{$this->n}", 'html_content' => '<p>x</p>', 'review_status' => $review, 'cost_vnd' => $cost]);
        $v->forceFill(['created_at' => $this->appTime($createdUtc), 'reviewed_at' => $this->appTime($reviewedUtc)])->save();

        return $v;
    }

    private function pub(ArticleVersion $v, string $status, string $atUtc, ?string $publishedUtc = null): Publication
    {
        return Publication::create([
            'website_id' => $this->site->id, 'article_version_id' => $v->id, 'publication_key' => 'k-'.$v->id,
            'scheduled_at_utc' => $atUtc, 'status' => $status, 'published_at_utc' => $publishedUtc,
        ]);
    }

    public function test_cua_so_hom_qua_theo_gio_viet_nam(): void
    {
        // 06/11 VN = [2026-11-05 17:00, 2026-11-06 17:00) UTC
        $this->version('needs_review', '2026-11-05 16:59:00', 100); // 05/11 23:59 VN -> ngoài
        $this->version('needs_review', '2026-11-05 17:00:00', 200); // 06/11 00:00 VN -> trong
        $this->version('needs_review', '2026-11-06 16:59:00', 300); // 06/11 23:59 VN -> trong
        $this->version('needs_review', '2026-11-06 17:00:00', 400); // 07/11 00:00 VN -> ngoài

        $d = DigestQueries::build($this->user('approver'), $this->now());

        $this->assertSame('2', $d['stats'][0]['value']);          // 2 bản bài mới hôm qua
        $this->assertStringContainsString('500', $d['stats'][3]['value']); // 200 + 300 đ
    }

    public function test_dem_duyet_tu_choi_va_da_dang(): void
    {
        $this->version('approved', '2026-11-06 01:00:00', 0, '2026-11-06 02:00:00');
        $this->version('approved', '2026-11-06 01:00:00', 0, '2026-11-06 03:00:00');
        $this->version('rejected', '2026-11-06 01:00:00', 0, '2026-11-06 04:00:00');
        $pv = $this->version('approved', '2026-11-04 01:00:00', 0, '2026-11-04 02:00:00');
        $this->pub($pv, 'published', '2026-11-06 05:00:00', '2026-11-06 05:00:05');

        $d = DigestQueries::build($this->user('admin'), $this->now());

        $this->assertSame('2 / 1', $d['stats'][1]['value']);
        $this->assertSame('1', $d['stats'][2]['value']);
    }

    public function test_nhom_viec_theo_thu_tu_va_gioi_han_toi_da_4(): void
    {
        $ap = $this->user('approver');
        $this->version('needs_review', '2026-11-01 00:00:00');                    // quá hạn
        $this->version('needs_review', '2026-11-07 02:00:00');                    // chưa quá hạn
        $v = $this->version('approved', '2026-11-05 00:00:00', 0, '2026-11-05 01:00:00');
        $this->pub($v, 'failed', '2026-11-06 01:00:00');                          // lượt đăng lỗi
        Website::create(['name' => 'Site B', 'cms_type' => 'wordpress', 'base_url' => 'https://b.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'error']);

        $d = DigestQueries::build($ap, $this->now());

        $this->assertLessThanOrEqual(DigestQueries::MAX_GROUPS, count($d['groups']));
        $this->assertSame(['publication_problems', 'website_issue', 'review_queue'], array_column($d['groups'], 'key'));
    }

    public function test_bien_tap_vien_khong_thay_chi_phi_hay_luot_dang(): void
    {
        $w = $this->user('writer');
        $this->version('rejected', '2026-11-06 01:00:00', 999, '2026-11-06 02:00:00', $w);

        $d = DigestQueries::build($w, $this->now());

        $this->assertFalse($d['is_approver']);
        $labels = array_column($d['stats'], 'label');
        $this->assertNotContains('Chi phí viết bài', $labels);
        $this->assertNotContains('Đã đăng', $labels);
        $this->assertStringNotContainsString('999', json_encode($d, JSON_UNESCAPED_UNICODE));
    }

    public function test_trang_thai_trong_khi_chua_co_gi(): void
    {
        $d = DigestQueries::build($this->user('approver'), $this->now());

        $this->assertSame('empty', $d['state']);
        $this->assertSame('Chưa có hoạt động nào để báo', $d['headline']);
    }

    public function test_ngay_nhieu_viec_gom_nhom_khong_liet_ke_tung_muc(): void
    {
        $ap = $this->user('approver');
        for ($i = 0; $i < DigestQueries::BUSY_THRESHOLD + 1; $i++) {
            $this->version('needs_review', '2026-11-01 00:00:00'); // đều quá hạn
        }

        $d = DigestQueries::build($ap, $this->now());

        $this->assertSame('busy', $d['state']);
        $this->assertCount(1, $d['groups']);
        $this->assertSame('review_queue', $d['groups'][0]['key']);
        $this->assertSame(DigestQueries::BUSY_THRESHOLD + 1, $d['groups'][0]['count']);
    }

    public function test_ghi_chu_trung_thuc_luon_co(): void
    {
        $d = DigestQueries::build($this->user('admin'), $this->now());
        $text = implode(' ', $d['notes']);

        $this->assertStringContainsString('Đội AI chưa chạy phiên nào', $text);
        $this->assertStringNotContainsString('Chưa có nhật ký chạy của AI', $text);
        $this->assertStringContainsString('Chưa đo được hiệu quả SEO', $text);
    }

    private function agentRun(string $status, string $startedUtc, array $extra = []): \App\Models\AgentRun
    {
        $this->n++;
        $a = Article::create(['website_id' => $this->site->id, 'primary_keyword' => "ag{$this->n}", 'workflow_status' => 'needs_review']);

        return \App\Models\AgentRun::create(array_merge(['website_id' => $this->site->id, 'article_id' => $a->id, 'status' => $status, 'ai_mode' => 'real', 'started_at' => $this->appTime($startedUtc)], $extra));
    }

    public function test_so_lieu_doi_ai_lay_tu_nhat_ky_phien_chay(): void
    {
        $in = '2026-11-06 02:00:00'; // trong "hôm qua" theo giờ VN
        $this->agentRun('ready_for_review', $in, ['repair_rounds' => 1, 'cost_vnd' => 1500]);
        $r2 = $this->agentRun('held', $in, ['repair_rounds' => 2, 'cost_vnd' => 2500, 'ai_mode' => 'mock']);
        $this->agentRun('failed', $in, ['cost_vnd' => 0]);
        $this->agentRun('ready_for_review', '2026-11-01 02:00:00', ['repair_rounds' => 3, 'cost_vnd' => 9999]); // ngoài ngày
        foreach ([1, 2] as $seq) {
            \App\Models\AgentRunStep::create(['agent_run_id' => $r2->id, 'seq' => $seq, 'stage' => 'retry', 'role' => 'system', 'status' => 'warn', 'title' => 't']);
        }

        $d = DigestQueries::build($this->user('approver'), $this->now());
        $by = collect($d['stats'])->keyBy('label');

        $this->assertSame('3', $by['Phiên chạy của đội AI']['value']);
        $this->assertStringContainsString('1 phiên lỗi', $by['Phiên chạy của đội AI']['sub']);
        $this->assertStringContainsString('1 phiên dùng AI giả lập', $by['Phiên chạy của đội AI']['sub']);
        $this->assertSame('1', $by['Đội AI đưa tới chờ duyệt']['value']);
        $this->assertSame('1', $by['Đội AI giữ nháp']['value']);
        $this->assertSame('2 / 3', $by['Tự thử lại / tự sửa']['value'], '2 bước thử lại / 1+2 vòng sửa');
        $this->assertSame(\App\Filament\Support\AiUsage\Vnd::format(4000), $by['Chi phí đội AI']['value']);
        $this->assertStringContainsString('lấy từ nhật ký phiên chạy', implode(' ', $d['notes']));
        $this->assertSame(\App\Filament\Support\AiUsage\Vnd::format(0), $by['Chi phí viết bài']['value'], 'chi phí viết bài (phiên bản) tách khỏi chi phí cả luồng đội AI');
        $this->assertStringNotContainsString('Đội AI chưa chạy phiên nào', implode(' ', $d['notes']));
    }

    public function test_bien_tap_vien_khong_thay_so_lieu_chi_phi_doi_ai(): void
    {
        $this->agentRun('ready_for_review', '2026-11-06 02:00:00', ['cost_vnd' => 1500]);
        $d = DigestQueries::build($this->user('writer'), $this->now());

        $this->assertNotContains('Chi phí đội AI', array_column($d['stats'], 'label'));
    }

    public function test_hien_dung_khi_dang_dung_xuat_ban(): void
    {
        app(EmergencyStopService::class)->stop($this->user('approver'), 'Kiểm tra bản tin', null);

        $d = DigestQueries::build($this->user('admin'), $this->now());

        $this->assertNotNull($d['stop']);
    }

    public function test_chi_doc_khong_doi_du_lieu(): void
    {
        $v = $this->version('needs_review', '2026-11-01 00:00:00');
        $before = [ArticleVersion::count(), Publication::count(), $v->fresh()->review_status];

        DigestQueries::build($this->user('approver'), $this->now());

        $this->assertSame($before, [ArticleVersion::count(), Publication::count(), $v->fresh()->review_status]);
    }

    public function test_trang_hien_thi_theo_vai_tro(): void
    {
        foreach (['admin', 'approver', 'writer'] as $role) {
            $this->actingAs($this->user($role));
            Livewire::test(DigestPage::class)
                ->assertOk()
                ->assertSee('Đội AI chưa chạy phiên nào')
                ->assertSee('Chưa đo được hiệu quả SEO');
        }
    }

    public function test_nguoi_dung_bi_khoa_khong_vao_duoc(): void
    {
        $u = $this->user('approver');
        $u->update(['is_active' => false]);
        $this->actingAs($u);

        $this->assertFalse(DigestPage::canAccess());
    }

    // ---- Cửa sổ, đếm "Đã đăng", chi phí, chờ duyệt, sắp đăng — dữ liệu gieo quanh nửa đêm giờ VN (17:00 UTC) ----

    public function test_nhan_cua_so_ghi_ro_ngay_hom_qua_gio_vn(): void
    {
        $d = DigestQueries::build($this->user('approver'), $this->now());

        $this->assertSame('Thứ Bảy, 07/11/2026', $d['date_label']);
        $this->assertStringContainsString('00:00 đến 24:00 ngày 06/11/2026 (giờ Việt Nam)', $d['window_label']);
    }

    public function test_cua_so_cat_dung_o_nua_dem_gio_vn_voi_moi_cot(): void
    {
        // Cửa sổ 06/11 VN = [05/11 17:00, 06/11 17:00) UTC. Mọi cột (UTC thật lẫn cột theo app.timezone) phải cắt cùng một mốc.
        $inV = $this->version('approved', '2026-11-05 17:00:00', 10, '2026-11-05 17:00:00');   // 00:00 VN -> trong
        $outV = $this->version('approved', '2026-11-05 16:59:59', 20, '2026-11-05 16:59:59'); // 23:59:59 hôm kia -> ngoài
        $lateV = $this->version('approved', '2026-11-06 16:59:59', 40, '2026-11-06 16:59:59'); // 23:59:59 hôm qua -> trong
        $nextV = $this->version('approved', '2026-11-06 17:00:00', 80, '2026-11-06 17:00:00'); // 00:00 hôm nay -> ngoài
        $this->pub($inV, 'published', '2026-11-01 00:00:00', '2026-11-05 17:00:00');
        $this->pub($outV, 'published', '2026-11-01 00:00:00', '2026-11-05 16:59:59');
        $this->pub($lateV, 'published', '2026-11-01 00:00:00', '2026-11-06 16:59:59');
        $this->pub($nextV, 'published', '2026-11-01 00:00:00', '2026-11-06 17:00:00');
        $this->agentRun('ready_for_review', '2026-11-05 17:00:00', ['cost_vnd' => 1]);
        $this->agentRun('ready_for_review', '2026-11-05 16:59:59', ['cost_vnd' => 100]);
        $this->agentRun('ready_for_review', '2026-11-06 16:59:59', ['cost_vnd' => 2]);
        $this->agentRun('ready_for_review', '2026-11-06 17:00:00', ['cost_vnd' => 200]);

        $by = collect(DigestQueries::build($this->user('approver'), $this->now())['stats'])->keyBy('label');

        $this->assertSame('2', $by['Bản bài mới']['value']);
        $this->assertSame('2 / 0', $by['Đã duyệt / từ chối']['value']);
        $this->assertSame('2', $by['Đã đăng']['value']);
        $this->assertSame(\App\Filament\Support\AiUsage\Vnd::format(50), $by['Chi phí viết bài']['value']);
        $this->assertSame('2', $by['Phiên chạy của đội AI']['value']);
        $this->assertSame(\App\Filament\Support\AiUsage\Vnd::format(3), $by['Chi phí đội AI']['value']);
    }

    public function test_da_dang_dem_theo_gio_dang_that_khong_theo_gio_xep_lich(): void
    {
        $a = $this->version('approved', '2026-11-01 00:00:00');
        $b = $this->version('approved', '2026-11-01 00:00:00');
        $c = $this->version('approved', '2026-11-01 00:00:00');
        $e = $this->version('approved', '2026-11-01 00:00:00');
        $this->pub($a, 'published', '2026-11-07 01:00:00', '2026-11-06 03:00:00'); // xếp lịch hôm nay, đăng thật hôm qua -> tính
        $this->pub($b, 'published', '2026-11-06 03:00:00', '2026-11-07 01:00:00'); // xếp lịch hôm qua, đăng thật hôm nay -> không tính
        $this->pub($c, 'failed', '2026-11-06 03:00:00', '2026-11-06 03:00:00');    // không phải trạng thái đã đăng -> không tính
        $this->pub($e, 'scheduled', '2026-11-06 03:00:00');                         // chưa đăng -> không tính

        $by = collect(DigestQueries::build($this->user('approver'), $this->now())['stats'])->keyBy('label');

        $this->assertSame('1', $by['Đã đăng']['value']);
    }

    public function test_dong_dau_ghi_ngay_hom_qua_va_dung_so_da_dang(): void
    {
        $v = $this->version('approved', '2026-11-01 00:00:00');
        $this->pub($v, 'published', '2026-11-06 03:00:00', '2026-11-06 16:59:00');

        $d = DigestQueries::build($this->user('approver'), $this->now());

        $this->assertSame('Hôm qua (06/11): 0 bản bài mới, 1 bài đã đăng', $d['headline']);
    }

    public function test_chi_phi_doi_ai_dung_chung_ham_voi_tong_quan(): void
    {
        $this->agentRun('ready_for_review', '2026-11-06 02:00:00', ['cost_vnd' => 1200]);
        $this->agentRun('failed', '2026-11-06 03:00:00', ['cost_vnd' => 300]);
        $this->agentRun('ready_for_review', '2026-11-05 16:59:00', ['cost_vnd' => 7777]); // 23:59 hôm kia

        $by = collect(DigestQueries::build($this->user('approver'), $this->now())['stats'])->keyBy('label');
        [$from, $to] = \App\Filament\Support\Schedule\VnTime::dayRangeApp(CarbonImmutable::parse('2026-11-06 12:00:00', 'Asia/Ho_Chi_Minh'));

        $this->assertSame(1500.0, \App\Filament\Support\AiUsage\RunCost::between($from, $to));
        $this->assertSame(\App\Filament\Support\AiUsage\Vnd::format(1500), $by['Chi phí đội AI']['value']);
    }

    public function test_bai_cho_duyet_bang_so_chung_voi_tong_quan(): void
    {
        $this->version('needs_review', '2026-11-01 00:00:00'); // quá hạn
        $this->version('needs_review', '2026-11-07 02:00:00');
        $this->version('needs_review', '2026-11-07 02:30:00');
        $this->version('approved', '2026-11-05 00:00:00', 0, '2026-11-05 01:00:00'); // đã duyệt: không tính

        $d = DigestQueries::build($this->user('approver'), $this->now());
        $g = collect($d['groups'])->keyBy('key')['review_queue'];

        $this->assertSame(\App\Filament\Support\Review\ReviewQueue::waitingCount(), $g['count']);
        $this->assertSame(3, $g['count']);
        $this->assertStringContainsString('Trong đó 1 bài', $g['detail']);
    }

    public function test_sap_dang_24_gio_tinh_theo_gio_xep_lich_tuong_lai(): void
    {
        $now = $this->now(); // 2026-11-07 03:00 UTC
        $v = $this->version('approved', '2026-11-01 00:00:00');
        $this->pub($v, 'scheduled', '2026-11-07 03:00:00');                                     // đúng bây giờ -> tính (>=)
        $this->pub($this->version('approved', '2026-11-01 00:00:00'), 'scheduled', '2026-11-08 02:59:59'); // trong 24 giờ -> tính
        $this->pub($this->version('approved', '2026-11-01 00:00:00'), 'scheduled', '2026-11-08 03:00:00'); // đúng 24 giờ -> không tính
        $this->pub($this->version('approved', '2026-11-01 00:00:00'), 'scheduled', '2026-11-07 02:59:59'); // đã qua giờ -> không tính
        $this->pub($this->version('approved', '2026-11-01 00:00:00'), 'published', '2026-11-07 05:00:00', '2026-11-07 05:00:01'); // đã đăng -> không tính

        $this->assertSame(2, DigestQueries::upcomingCount($now));
        $by = collect(DigestQueries::build($this->user('approver'), $now)['stats'])->keyBy('label');
        $this->assertSame('2 lượt sắp đăng trong 24 giờ tới', $by['Đã đăng']['sub']);
    }

    public function test_trang_hien_cua_so_thoi_gian(): void
    {
        $this->actingAs($this->user('approver'));
        Livewire::test(DigestPage::class)->assertSee('00:00 đến 24:00 ngày')->assertSee('giờ Việt Nam');
    }
}
