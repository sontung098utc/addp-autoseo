<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\PublicationResource\Pages\ViewPublication;
use App\Filament\Support\Explain\PublishExplainer;
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
 * AG-U23: "Vì sao chưa đăng?" giải thích bằng các luật THẬT, chỉ đọc, không đổi dữ liệu.
 */
class PublishExplainerTest extends TestCase
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

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function pub(string $status, string $atUtc, ?string $error = null, string $review = 'approved'): Publication
    {
        $this->n++;
        $a = Article::create(['website_id' => $this->site->id, 'primary_keyword' => "kw{$this->n}", 'workflow_status' => 'approved']);
        $v = ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => "Bài {$this->n}", 'slug' => "s{$this->n}", 'html_content' => '<p>x</p>', 'review_status' => $review]);

        return Publication::create([
            'website_id' => $this->site->id, 'article_version_id' => $v->id, 'publication_key' => "k-{$this->n}",
            'scheduled_at_utc' => CarbonImmutable::parse($atUtc, 'UTC'), 'status' => $status, 'last_error' => $error,
        ]);
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-11-07 03:00:00', 'UTC');
    }

    private function find(array $r, string $label): array
    {
        foreach ($r['checks'] as $c) {
            if ($c['label'] === $label) {
                return $c;
            }
        }
        $this->fail("Không có kiểm tra '{$label}'");
    }

    public function test_chua_toi_gio_la_dang_cho_va_khong_chan(): void
    {
        $r = PublishExplainer::forPublication($this->pub('scheduled', '2026-11-07 10:00:00'), $this->now());

        $this->assertSame('waiting', $r['verdict']);
        $this->assertSame('Chưa tới giờ đăng', $r['headline']);
        $this->assertSame(PublishExplainer::OK, $this->find($r, 'Dừng xuất bản')['state']);
        $this->assertStringContainsString('17:00', $this->find($r, 'Giờ đăng')['value']); // 10:00 UTC = 17:00 VN
        $this->assertSame('Giờ đăng', $r['checks'][$r['decisive']]['label']);
    }

    public function test_da_toi_gio_nhung_chua_gui_van_la_dang_cho(): void
    {
        $r = PublishExplainer::forPublication($this->pub('scheduled', '2026-11-07 01:00:00'), $this->now());

        $this->assertSame('waiting', $r['verdict']);
        $this->assertStringContainsString('Đã tới giờ', $r['headline']);
    }

    public function test_dung_khan_cap_la_ly_do_quyet_dinh_va_nho_ly_do(): void
    {
        $p = $this->pub('scheduled', '2026-11-07 10:00:00');
        app(EmergencyStopService::class)->stop($this->user('approver'), 'Phát hiện lỗi số liệu', null);

        $r = PublishExplainer::forPublication($p, $this->now());

        $this->assertSame('blocked', $r['verdict']);
        $this->assertSame('Dừng xuất bản', $r['checks'][$r['decisive']]['label']);
        $this->assertSame(PublishExplainer::BLOCKING, $this->find($r, 'Dừng xuất bản')['state']);
        $this->assertStringContainsString('Phát hiện lỗi số liệu', $this->find($r, 'Dừng xuất bản')['detail']);
    }

    public function test_website_khong_hoat_dong_thi_bi_chan(): void
    {
        $p = $this->pub('scheduled', '2026-11-07 10:00:00');
        $this->site->update(['status' => 'revoked']);

        $r = PublishExplainer::forPublication($p->fresh(), $this->now());

        $this->assertSame('blocked', $r['verdict']);
        $this->assertSame(PublishExplainer::BLOCKING, $this->find($r, 'Website')['state']);
    }

    public function test_loi_gan_nhat_duoc_che_thong_tin_nhay_cam(): void
    {
        $p = $this->pub('failed', '2026-11-07 01:00:00', 'Auth failed: Bearer abcdef1234567890abcdef1234567890 password=hunter2');

        $r = PublishExplainer::forPublication($p, $this->now());

        $this->assertSame('failed', $r['verdict']);
        $json = json_encode($r, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('abcdef1234567890abcdef1234567890', $json);
        $this->assertStringNotContainsString('hunter2', $json);
    }

    public function test_cho_doi_soat_khong_noi_dang_lai(): void
    {
        $r = PublishExplainer::forPublication($this->pub('needs_reconcile', '2026-11-07 01:00:00'), $this->now());

        $this->assertSame('blocked', $r['verdict']);
        $this->assertStringContainsString('không đăng lại', $r['headline']);
    }

    public function test_da_dang_thi_la_done(): void
    {
        $r = PublishExplainer::forPublication($this->pub('published', '2026-11-07 01:00:00'), $this->now());

        $this->assertSame('done', $r['verdict']);
    }

    public function test_ban_chua_duyet_va_chua_co_luot_dang(): void
    {
        $this->n++;
        $a = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'kwx', 'workflow_status' => 'needs_review']);
        $v = ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 'Bài x', 'slug' => 'sx', 'html_content' => '<p>x</p>', 'review_status' => 'needs_review']);

        $r = PublishExplainer::forVersion($v, $this->now());

        $this->assertSame('blocked', $r['verdict']);
        $this->assertSame('Đang chờ người duyệt', $r['headline']);
    }

    public function test_chi_doc_khong_doi_du_lieu(): void
    {
        $p = $this->pub('scheduled', '2026-11-07 10:00:00');
        $before = $p->fresh()->only(['status', 'last_error', 'attempt_count', 'scheduled_at_utc']);

        PublishExplainer::forPublication($p, $this->now());

        $this->assertEquals($before, $p->fresh()->only(['status', 'last_error', 'attempt_count', 'scheduled_at_utc']));
    }

    public function test_hop_thoai_hien_thi_tren_trang_luot_dang(): void
    {
        $this->actingAs($this->user('approver'));
        $p = $this->pub('scheduled', '2099-01-01 10:00:00');

        Livewire::test(ViewPublication::class, ['record' => $p->id])
            ->mountAction('why')
            ->assertSee('Vì sao bài này chưa được đăng?')
            ->assertSee('Dừng xuất bản')
            ->assertSee('Chưa tới giờ đăng');
    }
}
