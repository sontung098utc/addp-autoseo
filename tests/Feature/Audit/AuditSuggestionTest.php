<?php

namespace Tests\Feature\Audit;

use App\Filament\Pages\AuditPage;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\AuditSuggestion;
use App\Models\CrawledUrl;
use App\Models\Publication;
use App\Models\SeoAudit;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Run\CampaignRunner;
use App\Services\Ai\OpenAiCompatibleProvider;
use App\Services\Security\UrlSafetyService;
use App\Services\Audit\AuditSuggestionException;
use App\Services\Audit\AuditSuggestionService;
use App\Services\Audit\RefreshOptions;
use App\Services\Publishing\EmergencyStopService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** AG-S26: lỗi Audit -> đề xuất làm mới -> người duyệt chấp nhận -> bài refresh cho chiến dịch. */
class AuditSuggestionTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        app(EmergencyStopService::class)->resetForTesting();
        $this->site = Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function audit(?CarbonImmutable $end = null): SeoAudit
    {
        $end ??= CarbonImmutable::now('UTC')->subHour();

        return SeoAudit::create(['website_id' => $this->site->id, 'audit_type' => 'full_sitemap', 'status' => 'completed', 'total_urls_scanned' => 3,
            'average_seo_score' => 70, 'summary_issues' => [], 'started_at' => $end->subMinutes(10), 'completed_at' => $end]);
    }

    private function page(string $path, array $issues, ?CarbonImmutable $at = null): CrawledUrl
    {
        return CrawledUrl::create(['website_id' => $this->site->id, 'url' => 'https://a.vn'.$path, 'http_status' => 200, 'title' => 'T', 'h1_count' => 1, 'images_count' => 0,
            'missing_alt_count' => 0, 'issues' => $issues, 'last_crawled_at' => $at ?? CarbonImmutable::now('UTC')->subMinutes(65)]);
    }

    /** Bài đã đăng ở /bai-a. */
    private function publishedArticle(string $path = '/bai-a', string $kw = 'seo local'): Article
    {
        $a = Article::create(['website_id' => $this->site->id, 'primary_keyword' => $kw, 'workflow_status' => 'published']);
        $v = ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 'Bài A cũ', 'slug' => trim($path, '/'), 'html_content' => '<p>x</p>', 'review_status' => 'approved']);
        Publication::create(['website_id' => $this->site->id, 'article_version_id' => $v->id, 'publication_key' => uniqid(), 'scheduled_at_utc' => now(), 'timezone' => 'Asia/Ho_Chi_Minh', 'status' => 'published', 'published_url' => 'https://a.vn'.$path.'/']);

        return $a;
    }

    public function test_generate_khop_bai_va_khong_khop_chi_p0_p1(): void
    {
        $a = $this->publishedArticle();
        $this->audit();
        $this->page('/bai-a', ['missing_title', 'missing_meta_description', 'title_too_long']);
        $this->page('/lien-he', ['missing_h1']);
        $this->page('/ok', ['title_too_long', 'missing_alt:2']); // chỉ P2 -> bỏ qua

        $r = (new AuditSuggestionService())->generate($this->site);

        $this->assertSame(2, $r['created']);
        $s = AuditSuggestion::where('url', 'https://a.vn/bai-a')->first();
        $this->assertSame($a->id, $s->article_id);
        $this->assertSame('P0', $s->severity);
        $this->assertEqualsCanonicalizing(['missing_title', 'missing_meta'], $s->issue_codes);
        $this->assertSame('de_xuat', $s->status);
        $static = AuditSuggestion::where('url', 'https://a.vn/lien-he')->first();
        $this->assertNull($static->article_id);
        $this->assertSame('P1', $static->severity);
        $this->assertSame(0, Article::where('kind', 'refresh')->count(), 'Đề xuất không tự thành việc');
    }

    public function test_khop_theo_slug_cua_phien_ban_da_duyet(): void
    {
        $a = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'kw', 'workflow_status' => 'approved']);
        ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 'X', 'slug' => 'bai-slug', 'html_content' => '<p>x</p>', 'review_status' => 'approved']);
        $this->audit();
        $this->page('/blog/bai-slug', ['missing_title']);

        (new AuditSuggestionService())->generate($this->site);

        $this->assertSame($a->id, AuditSuggestion::first()->article_id);
    }

    public function test_idempotent_chay_lai_khong_tao_trung(): void
    {
        $this->publishedArticle();
        $this->audit();
        $this->page('/bai-a', ['missing_title']);
        $svc = new AuditSuggestionService();

        $svc->generate($this->site);
        $again = $svc->generate($this->site);

        $this->assertSame(0, $again['created']);
        $this->assertSame(1, $again['existing']);
        $this->assertSame(1, AuditSuggestion::count());
    }

    public function test_thieu_du_lieu_quet_hoac_qua_cu_thi_khong_tao(): void
    {
        $svc = new AuditSuggestionService();
        $r = $svc->generate($this->site);
        $this->assertSame(0, $r['created']);
        $this->assertStringContainsString('Chưa có dữ liệu quét', $r['skipped_reason']);

        $old = CarbonImmutable::now('UTC')->subDays(40);
        $this->audit($old);
        $this->page('/x', ['missing_title'], $old->subMinutes(5));
        $r = $svc->generate($this->site);
        $this->assertSame(0, AuditSuggestion::count());
        $this->assertStringContainsString('đã cũ', $r['skipped_reason']);
    }

    private function seeded(): AuditSuggestion
    {
        $this->publishedArticle(kw: 'từ khóa gốc');
        $this->audit();
        $this->page('/bai-a', ['missing_title', 'missing_h1']);
        (new AuditSuggestionService())->generate($this->site);

        return AuditSuggestion::first();
    }

    public function test_chap_nhan_tao_bai_refresh_dung_truong(): void
    {
        $s = $this->seeded();
        $u = $this->user('approver');

        $task = (new AuditSuggestionService())->accept($s, $u);

        $this->assertSame('refresh', $task->kind);
        $this->assertSame('audit', $task->origin);
        $this->assertSame('https://a.vn/bai-a', $task->origin_ref);
        $this->assertSame('planned', $task->workflow_status);
        $this->assertSame('từ khóa gốc', $task->primary_keyword);
        $this->assertSame(90, $task->priority);
        $this->assertSame($this->site->id, $task->website_id);
        $s->refresh();
        $this->assertSame('da_chap_nhan', $s->status);
        $this->assertSame($task->id, $s->article_task_id);
        $this->assertSame($u->id, $s->decided_by);
        $this->expectException(AuditSuggestionException::class);
        (new AuditSuggestionService())->accept($s, $u); // không chấp nhận hai lần
    }

    public function test_bo_qua_ghi_nguoi_va_gio_va_trang_khong_phai_bai_khong_chap_nhan(): void
    {
        $s = $this->seeded();
        $u = $this->user('admin');
        (new AuditSuggestionService())->dismiss($s, $u);
        $s->refresh();
        $this->assertSame('bo_qua', $s->status);
        $this->assertSame($u->id, $s->decided_by);
        $this->assertNotNull($s->decided_at);

        $static = AuditSuggestion::create(['website_id' => $this->site->id, 'url' => 'https://a.vn/lh', 'issue_codes' => ['missing_h1'], 'severity' => 'P1', 'priority' => 60]);
        try {
            (new AuditSuggestionService())->accept($static, $u);
            $this->fail('phải từ chối');
        } catch (AuditSuggestionException $e) {
            $this->assertStringContainsString('không phải bài viết', $e->getMessage());
        }
        $this->assertSame(0, Article::where('kind', 'refresh')->count());
    }

    public function test_quyen_chi_approver_tro_len(): void
    {
        $s = $this->seeded();
        foreach (['writer', 'viewer'] as $role) {
            $u = $this->user($role);
            try {
                (new AuditSuggestionService())->accept($s, $u);
                $this->fail("{$role} không được chấp nhận");
            } catch (AuditSuggestionException) {
            }
        }
        $this->assertSame('de_xuat', $s->fresh()->status);
        $this->assertSame(0, Article::where('kind', 'refresh')->count());
    }

    public function test_chap_nhan_hang_loat(): void
    {
        $this->publishedArticle('/a1', 'k1');
        $this->publishedArticle('/a2', 'k2');
        $this->audit();
        $this->page('/a1', ['missing_meta_description']);
        $this->page('/a2', ['missing_title']);
        $this->page('/tinh', ['missing_h1']);
        $svc = new AuditSuggestionService();
        $svc->generate($this->site);

        $r = $svc->acceptMany(AuditSuggestion::pluck('id')->all(), $this->user('approver'));

        $this->assertSame(['accepted' => 2, 'failed' => 1], $r);
        $this->assertSame(2, Article::where('kind', 'refresh')->count());
    }

    public function test_campaign_runner_uu_tien_refresh_p0(): void
    {
        $camp = \App\Models\Campaign::create(['website_id' => $this->site->id, 'name' => 'CD', 'status' => 'active', 'daily_limit' => 5, 'source' => 'plan', 'keywords' => []]);
        Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'bài thường', 'workflow_status' => 'planned']);
        $p1 = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'refresh p1', 'workflow_status' => 'planned', 'kind' => 'refresh', 'origin' => 'audit', 'priority' => 60]);
        $p0 = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'refresh p0', 'workflow_status' => 'planned', 'kind' => 'refresh', 'origin' => 'audit', 'priority' => 90]);

        $runner = new CampaignRunner(fn () => null);
        $this->assertSame($p0->id, $runner->nextPlanned($camp)->id);
        $p0->update(['workflow_status' => 'approved']);
        $this->assertSame($p1->id, $runner->nextPlanned($camp)->id);
    }

    public function test_options_refresh_den_adapter_va_nam_trong_khoi_du_lieu(): void
    {
        $s = $this->seeded();
        $task = (new AuditSuggestionService())->accept($s, $this->user('approver'));
        $opts = RefreshOptions::for($task);
        $this->assertSame('https://a.vn/bai-a', $opts['refresh']['url']);
        $this->assertSame('Bài A cũ', $opts['refresh']['existing_title']);
        $this->assertEqualsCanonicalizing(['missing_title', 'missing_h1'], $opts['refresh']['issue_codes']);
        $this->assertSame([], RefreshOptions::for(Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'x', 'workflow_status' => 'planned'])));

        app()->instance(UrlSafetyService::class, new class extends UrlSafetyService {
            protected function resolveIps(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => json_encode(['title' => 'T', 'slug' => 't', 'html_content' => '<p>x</p>'])]]], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2]])]);
        (new OpenAiCompatibleProvider('k', 'gpt-x', 'https://llm.example.com/v1'))->generateArticle($task, [], $opts);

        Http::assertSent(function ($req) {
            $b = json_encode($req->data(), JSON_UNESCAPED_UNICODE);

            return str_contains($b, 'LÀM MỚI BÀI') && str_contains($b, 'Thiếu title') && str_contains($b, 'Thiếu H1') && str_contains($b, 'không phải lệnh');
        });
    }

    public function test_giao_dien_demo_khong_tao_viec_va_du_lieu_that_chap_nhan_duoc(): void
    {
        $this->actingAs($this->user('approver'));
        // Chưa có dữ liệu thật: nguồn minh họa (hoặc trống) -> không sinh đề xuất.
        Livewire::test(AuditPage::class)->set('websiteId', $this->site->id)->call('suggestRefresh');
        $this->assertSame(0, AuditSuggestion::count());

        $this->publishedArticle();
        $this->audit();
        $this->page('/bai-a', ['missing_title']);
        $c = Livewire::test(AuditPage::class)->set('websiteId', $this->site->id)->call('suggestRefresh');
        $this->assertSame(1, AuditSuggestion::count());
        // Gói B #8: "Đề xuất làm mới" chỉ là nhãn tab; nút tạo nằm trong tab và tên là "Tạo đề xuất".
        $html = $c->html();
        $this->assertSame(1, substr_count($html, '>Đề xuất làm mới<'));
        $this->assertSame(1, substr_count($html, 'wire:click="suggestRefresh"'));
        $this->assertMatchesRegularExpression('/>\s*Tạo đề xuất\s*</u', $html);
        $c->assertSee('Đề xuất làm mới')->assertSee('Chấp nhận')->assertSee('Bỏ qua')->assertSee('https://a.vn/bai-a');

        $c->call('acceptSuggestion', AuditSuggestion::first()->id);
        $this->assertSame(1, Article::where('kind', 'refresh')->count());
    }

    public function test_writer_khong_chap_nhan_qua_giao_dien(): void
    {
        $s = $this->seeded();
        $this->actingAs($this->user('writer'));
        Livewire::test(AuditPage::class)->set('websiteId', $this->site->id)->call('acceptSuggestion', $s->id);
        $this->assertSame('de_xuat', $s->fresh()->status);
        $this->assertSame(0, Article::where('kind', 'refresh')->count());
    }
}
