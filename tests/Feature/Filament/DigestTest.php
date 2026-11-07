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

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function version(string $review, string $createdUtc, float $cost = 0, ?string $reviewedUtc = null, ?User $by = null): ArticleVersion
    {
        $this->n++;
        $a = Article::create(['website_id' => $this->site->id, 'created_by' => $by?->id, 'primary_keyword' => "kw{$this->n}", 'workflow_status' => $review === 'approved' ? 'approved' : 'needs_review']);
        $v = ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => "t{$this->n}", 'slug' => "s{$this->n}", 'html_content' => '<p>x</p>', 'review_status' => $review, 'cost_vnd' => $cost]);
        $v->forceFill(['created_at' => $createdUtc, 'reviewed_at' => $reviewedUtc])->save();

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
        $this->assertSame(['publication_problems', 'overdue_review', 'website_issue', 'review_queue'], array_column($d['groups'], 'key'));
    }

    public function test_bien_tap_vien_khong_thay_chi_phi_hay_luot_dang(): void
    {
        $w = $this->user('writer');
        $this->version('rejected', '2026-11-06 01:00:00', 999, '2026-11-06 02:00:00', $w);

        $d = DigestQueries::build($w, $this->now());

        $this->assertFalse($d['is_approver']);
        $labels = array_column($d['stats'], 'label');
        $this->assertNotContains('Chi phí AI', $labels);
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
        $this->assertSame(DigestQueries::BUSY_THRESHOLD + 1, $d['groups'][0]['count']);
    }

    public function test_ghi_chu_trung_thuc_luon_co(): void
    {
        $d = DigestQueries::build($this->user('admin'), $this->now());
        $text = implode(' ', $d['notes']);

        $this->assertStringContainsString('Chưa có nhật ký chạy của AI', $text);
        $this->assertStringContainsString('Chưa đo được hiệu quả SEO', $text);
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
                ->assertSee('Chưa có nhật ký chạy của AI')
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
}
