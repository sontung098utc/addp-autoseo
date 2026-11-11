<?php

namespace Tests\Feature\Agent;

use App\Contracts\AiProviderInterface;
use App\Contracts\PublisherInterface;
use App\Filament\Resources\AgentRunResource;
use App\Filament\Resources\ArticleVersionResource;
use App\Filament\Resources\ArticleVersionResource\Pages\ViewArticleVersion;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Campaign;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Handoff\CmsDraftHandoff;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Services\Publishing\DraftCapable;
use App\Services\Publishing\EmergencyStopService;
use App\Services\Publishing\PublisherManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Unit\QualityFixtures;

/** AG-S04: điều kiện đẩy nháp, lỗi không retry, hủy nháp, quyền, không lộ credential. Dùng connector GIẢ; chưa thử CMS thật. */
class CmsDraftHandoffTest extends TestCase
{
    use RefreshDatabase;

    private const KW = 'học bổng toàn phần';

    private int $n = 0;
    private object $cms;
    private PublisherManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        app(EmergencyStopService::class)->resetForTesting();
        $this->cms = $this->fakeCms();
        $this->manager = new PublisherManager();
        $this->manager->registerPublisher('wordpress', $this->cms);
        $this->app->instance(PublisherManager::class, $this->manager);
    }

    protected function tearDown(): void
    {
        app(EmergencyStopService::class)->resetForTesting();
        parent::tearDown();
    }

    private function fakeCms(): object
    {
        return new class implements PublisherInterface, DraftCapable {
            public int $drafts = 0;
            public int $deleted = 0;
            public ?\Throwable $fail = null;
            public ?\Throwable $failDelete = null;

            public function testConnection(Website $website): bool { return true; }
            public function uploadMedia(Website $website, string $filePath, string $altText = ''): array { return []; }
            public function publish(Publication $publication): array { return []; }
            public function getByPublicationKey(Website $website, string $publicationKey): ?array { return null; }

            public function createDraft(Website $website, ArticleVersion $version, ?string $publicationKey = null): array
            {
                $this->drafts++;
                if ($this->fail) {
                    throw $this->fail;
                }

                return ['remote_id' => '501', 'preview_url' => 'https://a.vn/?p=501&preview=true'];
            }

            public function deleteDraft(Website $website, string $remoteId): void
            {
                if ($this->failDelete) {
                    throw $this->failDelete;
                }
                $this->deleted++;
            }
        };
    }

    private function article(string $siteStatus = 'active', string $cms = 'wordpress'): Article
    {
        $w = Website::create(['name' => 'S', 'cms_type' => $cms, 'base_url' => 'https://a'.(++$this->n).'.vn', 'encrypted_credentials' => ['username' => 'u', 'application_password' => 'TOPSECRETpass1234567890abcdef'], 'status' => $siteStatus]);

        return Article::create(['website_id' => $w->id, 'primary_keyword' => self::KW, 'workflow_status' => 'planned']);
    }

    private function campaign(Article $a, string $mode = 'balanced'): Campaign
    {
        return Campaign::create(['website_id' => $a->website_id, 'name' => 'C', 'status' => 'active', 'keywords' => [self::KW], 'daily_limit' => 1, 'mode' => $mode]);
    }

    private function ai(): AiProviderInterface
    {
        return new class implements AiProviderInterface {
            public function generateOutline(Article $article, array $options = []): array
            {
                return ['outline' => ['Mở đầu', 'Nội dung'], 'tokens_used' => 100, 'model' => 'fake'];
            }

            public function generateArticle(Article $article, array $outline, array $options = []): array
            {
                return QualityFixtures::goodAttrs('học bổng toàn phần') + ['tokens_used' => 500, 'cost_vnd' => 1000.0, 'model' => 'fake', 'prompt_version' => 't'];
            }

            public function calculateCostVnd(int $promptTokens, int $completionTokens, string $model): float { return 0.0; }
        };
    }

    private function go(Article $a, ?int $campaignId, bool $withHandoff = true): AgentRun
    {
        $o = new AgentRunOrchestrator($this->ai(), handoff: $withHandoff ? new CmsDraftHandoff($this->manager, app(EmergencyStopService::class)) : null);

        return $o->run($a, null, $campaignId ? 'schedule' : 'manual', $campaignId);
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    // ---- điều kiện (a)-(e) ----

    public function test_chien_dich_balanced_dat_p0_day_nhap_va_luu_ba_cot(): void
    {
        $a = $this->article();
        $run = $this->go($a, $this->campaign($a)->id);

        $this->assertSame('ready_for_review', $run->status);
        $this->assertSame(1, $this->cms->drafts);
        $v = $run->finalVersion;
        $this->assertSame(['501', 'https://a.vn/?p=501&preview=true', 'pushed'], [$v->cms_draft_id, $v->cms_draft_url, $v->cms_draft_status]);
        $this->assertNotNull($v->cms_draft_pushed_at);
        $handoff = $run->steps->firstWhere('stage', 'handoff');
        $this->assertStringContainsString('Đã đẩy nháp lên WordPress', $handoff->detail);
        $this->assertStringContainsString('https://a.vn/?p=501', $handoff->detail);
        $this->assertSame(0, Publication::count(), 'đẩy nháp không phải xuất bản');
    }

    public function test_a_phien_thu_cong_khong_chien_dich_khong_day(): void
    {
        $run = $this->go($this->article(), null);
        $this->assertSame(0, $this->cms->drafts);
        $this->assertNull($run->finalVersion->cms_draft_status);
    }

    public function test_a_chien_dich_khong_phai_balanced_khong_day(): void
    {
        foreach (['cautious', 'autonomous'] as $mode) {
            $a = $this->article();
            $this->go($a, $this->campaign($a, $mode)->id);
        }
        $this->assertSame(0, $this->cms->drafts);
    }

    public function test_diem_noi_mac_dinh_tat_trong_orchestrator(): void
    {
        $a = $this->article();
        $this->go($a, $this->campaign($a)->id, false);
        $this->assertSame(0, $this->cms->drafts);
    }

    public function test_b_khong_dat_p0_khong_day(): void
    {
        $a = $this->article();
        $run = $this->go($a, $this->campaign($a)->id);
        $v = ArticleVersion::create(['article_id' => $a->id, 'version' => 9, 'title' => 't', 'slug' => 's', 'html_content' => '<p>x</p>']);
        $r = $this->handoff()->push($run, $v, $a->website, false, 'low');
        $this->assertSame('skipped', $r->status);
        $this->assertSame(1, $this->cms->drafts, 'chỉ lần của phiên đầu');
    }

    public function test_c_rui_ro_cao_khong_day(): void
    {
        $a = $this->article();
        $run = AgentRun::create(['website_id' => $a->website_id, 'article_id' => $a->id, 'campaign_id' => $this->campaign($a)->id, 'trigger' => 'schedule', 'status' => 'running', 'trust_tier' => 'T1', 'ai_mode' => 'real']);
        $v = ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 't', 'slug' => 's', 'html_content' => '<p>x</p>']);

        $r = $this->handoff()->push($run, $v, $a->website, true, 'high');
        $this->assertSame('skipped', $r->status);
        $this->assertStringContainsString('rủi ro cao', $r->note);
        $this->assertSame(0, $this->cms->drafts);
    }

    public function test_d_dung_khan_cap_khong_day(): void
    {
        $a = $this->article();
        $run = AgentRun::create(['website_id' => $a->website_id, 'article_id' => $a->id, 'campaign_id' => $this->campaign($a)->id, 'trigger' => 'schedule', 'status' => 'running', 'trust_tier' => 'T1', 'ai_mode' => 'real']);
        $v = ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 't', 'slug' => 's', 'html_content' => '<p>x</p>']);
        app(EmergencyStopService::class)->stop($this->user('admin'), 'test', null);

        $r = $this->handoff()->push($run, $v, $a->website, true, 'low');
        $this->assertSame('skipped', $r->status);
        $this->assertSame(0, $this->cms->drafts);
    }

    public function test_e_website_khong_active_hoac_cms_khong_ho_tro_khong_day(): void
    {
        $a = $this->article('revoked');
        $run = AgentRun::create(['website_id' => $a->website_id, 'article_id' => $a->id, 'campaign_id' => $this->campaign($a)->id, 'trigger' => 'schedule', 'status' => 'running', 'trust_tier' => 'T1', 'ai_mode' => 'real']);
        $v = ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 't', 'slug' => 's', 'html_content' => '<p>x</p>']);
        $this->assertSame('skipped', $this->handoff()->push($run, $v, $a->website, true, 'low')->status);

        // Magento: connector chưa hỗ trợ đăng từ nháp => không đẩy (tránh bài trùng)
        $m = $this->article('active', 'magento2');
        $m->website->update(['encrypted_credentials' => ['consumer_key' => 'k', 'consumer_secret' => 's']]);
        $run2 = AgentRun::create(['website_id' => $m->website_id, 'article_id' => $m->id, 'campaign_id' => $this->campaign($m)->id, 'trigger' => 'schedule', 'status' => 'running', 'trust_tier' => 'T1', 'ai_mode' => 'real']);
        $v2 = ArticleVersion::create(['article_id' => $m->id, 'version' => 1, 'title' => 't', 'slug' => 's', 'html_content' => '<p>x</p>']);
        $r = $this->handoff()->push($run2, $v2, $m->website, true, 'low');
        $this->assertSame('skipped', $r->status);
        $this->assertStringContainsString('chưa hỗ trợ đăng từ nháp', $r->note);
        $this->assertSame(0, $this->cms->drafts);
    }

    public function test_khong_day_hai_lan_cho_cung_mot_version(): void
    {
        $a = $this->article();
        $run = $this->go($a, $this->campaign($a)->id);
        $this->handoff()->push($run, $run->finalVersion->refresh(), $a->website, true, 'low');
        $this->assertSame(1, $this->cms->drafts);
    }

    // ---- lỗi ----

    public function test_loi_mang_ghi_failed_khong_retry_phien_van_ready_for_review_va_khong_lo_credential(): void
    {
        $this->cms->fail = new \Illuminate\Http\Client\ConnectionException('timeout https://a.vn/wp-json/wp/v2/posts?token=TOPSECRETpass1234567890abcdef Authorization: Basic dXNlcjpUT1BTRUNSRVRwYXNz');
        $a = $this->article();
        $run = $this->go($a, $this->campaign($a)->id);

        $this->assertSame(1, $this->cms->drafts, 'không retry');
        $this->assertSame('ready_for_review', $run->status);
        $this->assertSame('failed', $run->finalVersion->cms_draft_status);
        $this->assertNull($run->finalVersion->cms_draft_id);
        $this->assertStringContainsString('Không đẩy được nháp', $run->outcome_reason);
        $h = $run->steps->firstWhere('stage', 'handoff');
        $this->assertSame('warn', $h->status);
        $all = $run->outcome_reason.$h->detail.json_encode($h->data);
        $this->assertStringNotContainsString('TOPSECRET', $all);
        $this->assertStringNotContainsString('dXNlcjpUT1BTRUNSRVR', $all);
        $this->assertSame('needs_review', $run->finalVersion->review_status);
    }

    // ---- hủy nháp + quyền + UI ----

    private function pushed(): ArticleVersion
    {
        $a = $this->article();
        $run = $this->go($a, $this->campaign($a)->id);

        return $run->finalVersion;
    }

    public function test_huy_nhap_goi_connector_va_doi_trang_thai(): void
    {
        $v = $this->pushed();
        $this->handoff()->cancel($v);

        $this->assertSame(1, $this->cms->deleted);
        $this->assertSame('cancelled', $v->refresh()->cms_draft_status);
    }

    public function test_huy_nhap_loi_giu_nguyen_trang_thai_va_che_loi(): void
    {
        $v = $this->pushed();
        $this->cms->failDelete = new \RuntimeException('HTTP 500 https://a.vn/x?token=abc Bearer SECRETTOKENVALUE123');
        try {
            $this->handoff()->cancel($v);
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('SECRETTOKEN', $e->getMessage());
        }
        $this->assertSame('pushed', $v->refresh()->cms_draft_status);
    }

    public function test_khong_huy_nhap_khi_da_co_luot_dang_chinh_thuc(): void
    {
        $v = $this->pushed();
        Publication::create(['website_id' => $v->article->website_id, 'article_version_id' => $v->id, 'publication_key' => 'k1', 'scheduled_at_utc' => now(), 'status' => 'needs_reconcile', 'timezone' => 'UTC']);
        $this->expectException(\RuntimeException::class);
        try {
            $this->handoff()->cancel($v);
        } finally {
            $this->assertSame(0, $this->cms->deleted);
        }
    }

    public function test_trang_xem_ban_hien_dong_nhap_va_chi_nguoi_duyet_huy_duoc(): void
    {
        $v = $this->pushed();

        $this->actingAs($this->user('approver'));
        $this->get(ArticleVersionResource::getUrl('view', ['record' => $v]))->assertOk()->assertSee('Nháp trên CMS')->assertSee('link xem trước')->assertSee('đã đẩy, chờ duyệt')->assertSee('Huỷ nháp trên CMS');
        Livewire::test(ViewArticleVersion::class, ['record' => $v->getRouteKey()])->callAction('cancelCmsDraft');
        $this->assertSame('cancelled', $v->refresh()->cms_draft_status);
        $this->assertSame(1, $this->cms->deleted);
    }

    public function test_bien_tap_vien_khong_huy_duoc_nhap(): void
    {
        $v = $this->pushed();
        $this->actingAs($this->user('writer'));
        try {
            Livewire::test(ViewArticleVersion::class, ['record' => $v->getRouteKey()])->callAction('cancelCmsDraft');
        } catch (\Throwable) {
        }
        $this->assertSame('pushed', $v->refresh()->cms_draft_status);
        $this->assertSame(0, $this->cms->deleted);
    }

    public function test_chi_tiet_phien_chay_hien_dong_nhap(): void
    {
        $a = $this->article();
        $run = $this->go($a, $this->campaign($a)->id);
        $this->actingAs($this->user('approver'));
        $this->get(AgentRunResource::getUrl('view', ['record' => $run]))->assertOk()->assertSee('Nháp trên CMS')->assertSee('link xem trước');
    }

    public function test_link_khong_https_khong_duoc_render_thanh_lien_ket(): void
    {
        $v = $this->pushed();
        $v->update(['cms_draft_url' => 'javascript:alert(1)']);
        $this->actingAs($this->user('approver'));
        $this->get(ArticleVersionResource::getUrl('view', ['record' => $v]))->assertOk()->assertDontSee('javascript:alert', false);
    }

    private function handoff(): CmsDraftHandoff
    {
        return new CmsDraftHandoff($this->manager, app(EmergencyStopService::class));
    }
}
