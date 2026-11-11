<?php

namespace Tests\Feature\Agent;

use App\Contracts\AiProviderInterface;
use App\Filament\Resources\ArticleResource;
use App\Filament\Resources\ArticleResource\Pages\ViewArticle;
use App\Filament\Support\Dashboard\DashboardQueries;
use App\Jobs\PublishArticleJob;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Campaign;
use App\Models\CampaignWait;
use App\Models\Keyword;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Autopilot\AutopilotRunner;
use App\Services\Agent\Checkpoints\OutlineHold;
use App\Services\Agent\Refresh\AgentRunDispatcher;
use App\Services\Agent\Run\CampaignRunner;
use App\Services\Publishing\EmergencyStopService;
use App\Services\Publishing\PublicationHold;
use App\Services\Publishing\PublisherManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Unit\QualityFixtures;

/** Điểm dừng chờ người của chiến dịch (outline | draft | plan | publish) và dừng/tiếp tục từng lượt đăng. AI giả tất định. */
class CampaignCheckpointsTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    /** @var array{outline:int, article:int, got:?array} */
    private array $calls;

    protected function setUp(): void
    {
        parent::setUp();
        app(EmergencyStopService::class)->resetForTesting();
        Cache::flush();
        $this->site = Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://'.uniqid().'.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->calls = ['outline' => 0, 'article' => 0, 'got' => null];
    }

    protected function tearDown(): void
    {
        app(EmergencyStopService::class)->resetForTesting();
        parent::tearDown();
    }

    private function user(string $role = 'writer'): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function campaign(array $checkpoints, array $over = []): Campaign
    {
        return Campaign::create($over + ['website_id' => $this->site->id, 'created_by' => $this->user()->id, 'name' => 'ADDP tiểu đường', 'status' => 'active', 'mode' => 'balanced', 'source' => 'keywords',
            'daily_limit' => 3, 'keywords' => ['học bổng toàn phần'], 'checkpoints' => $checkpoints]);
    }

    private function ai(): AiProviderInterface
    {
        $calls = &$this->calls;

        return new class($calls) implements AiProviderInterface
        {
            public function __construct(private array &$calls) {}

            public function generateOutline(Article $article, array $options = []): array
            {
                $this->calls['outline']++;

                return ['outline' => ['Mở đầu', 'Nội dung chính', 'Kết luận'], 'tokens_used' => 10, 'model' => 'fake'];
            }

            public function generateArticle(Article $article, array $outline, array $options = []): array
            {
                $this->calls['article']++;
                $this->calls['got'] = $outline;

                return QualityFixtures::goodAttrs($article->primary_keyword) + ['tokens_used' => 100, 'cost_vnd' => 10.0, 'model' => 'fake', 'prompt_version' => 'test'];
            }

            public function calculateCostVnd(int $promptTokens, int $completionTokens, string $model): float
            {
                return 0.0;
            }
        };
    }

    private function runner(): CampaignRunner
    {
        $ai = $this->ai();

        return new CampaignRunner(fn () => $ai);
    }

    public function test_dung_sau_dan_y_khong_goi_ai_viet_va_ghi_buoc_checkpoint(): void
    {
        $r = $this->runner()->tick($this->campaign(['outline']));

        $this->assertSame('held_outline', $r['result'], $r['message']);
        $run = $r['run']->fresh(['article', 'steps']);
        $this->assertSame('held', $run->status);
        $this->assertSame(1, $this->calls['outline']);
        $this->assertSame(0, $this->calls['article'], 'không gọi bước viết');
        $this->assertSame('drafting', $run->article->workflow_status);
        $this->assertSame(0, ArticleVersion::count());
        $step = $run->steps->firstWhere('stage', 'checkpoint');
        $this->assertNotNull($step);
        $this->assertSame('warn', $step->status);
        $this->assertSame('system', $step->role);
        $this->assertSame('Dừng sau dàn ý: chờ bạn xem', $step->title);
        $this->assertSame('outline', $step->data['checkpoint']);
        $this->assertSame(['Mở đầu', 'Nội dung chính', 'Kết luận'], $step->data['outline']);
    }

    public function test_khong_dat_diem_dung_thi_van_viet_ngay(): void
    {
        $r = $this->runner()->tick($this->campaign([]));

        $this->assertSame('ran', $r['result'], $r['message']);
        $this->assertSame(1, $this->calls['article']);
    }

    public function test_duyet_dan_y_da_sua_thi_dung_dan_y_do_va_khong_dung_lan_nua(): void
    {
        $c = $this->campaign(['outline']);
        $article = $this->runner()->tick($c)['run']->article;
        $this->assertNotNull(OutlineHold::forArticle($article));
        $outlineCalls = $this->calls['outline'];

        $run = (new OutlineHold)->approve($article, ['  Mở đầu mới ', '', 'Phần thêm vào', 'Kết luận'], $this->user(), $this->ai());

        $this->assertSame('ready_for_review', $run->status);
        $this->assertSame($outlineCalls, $this->calls['outline'], 'dàn ý có sẵn: không gọi AI lập lại');
        $this->assertSame(1, $this->calls['article']);
        $this->assertSame(['Mở đầu mới', 'Phần thêm vào', 'Kết luận'], $this->calls['got']);
        $this->assertNull(OutlineHold::forArticle($article->fresh()), 'có bản mới thì hết chờ');
    }

    public function test_duyet_dan_y_can_quyen_writer_va_dan_y_khong_rong(): void
    {
        $article = $this->runner()->tick($this->campaign(['outline']))['run']->article;

        $this->expectException(\InvalidArgumentException::class);
        (new OutlineHold)->approve($article, ['  ', ''], $this->user(), $this->ai());
    }

    public function test_giao_tay_bai_cua_chien_dich_cung_dung_sau_dan_y(): void
    {
        $c = $this->campaign(['outline']);
        $a = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'đường huyết', 'workflow_status' => 'planned', 'campaign_id' => $c->id]);

        $run = AgentRunDispatcher::run($a, $this->ai(), $this->user());

        $this->assertSame('held', $run->status);
        $this->assertSame(0, $this->calls['article']);
    }

    public function test_diem_dung_ban_nhap_chan_tu_duyet_che_do_toan_quyen(): void
    {
        $held = $this->runner()->tick($this->campaign(['draft'], ['mode' => 'autonomous']));
        $this->assertSame('ran', $held['result'], $held['message']);
        $run = $held['run']->fresh(['article', 'steps']);
        $this->assertSame('needs_review', $run->article->workflow_status);
        $this->assertTrue($run->steps->contains(fn ($s) => ($s->data['decision'] ?? null) === 'auto_approve_skipped' && str_contains($s->detail, 'Dừng sau bản nháp theo cài đặt chiến dịch')));

        // Đối chứng: không đặt điểm dừng thì Toàn quyền vẫn tự duyệt.
        $this->site = Website::create(['name' => 'Site B', 'cms_type' => 'wordpress', 'base_url' => 'https://'.uniqid().'.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $ctl = $this->runner()->tick($this->campaign([], ['mode' => 'autonomous']));
        $this->assertSame('auto_approved', $ctl['result'], $ctl['message']);
    }

    private function plannerKeywords(int $n): void
    {
        foreach (range(1, $n) as $i) {
            Keyword::create(['website_id' => $this->site->id, 'keyword' => "chủ đề số $i", 'keyword_key' => "chủ đề số $i", 'volume' => 1000 - $i * 10, 'source' => 'csv', 'status' => 'new']);
        }
    }

    private function autopilot(): AutopilotRunner
    {
        return new AutopilotRunner(null, fn () => new class
        {
            public function suggest($s)
            {
                return ['added' => 0, 'metrics' => []];
            }
        });
    }

    public function test_diem_dung_ke_hoach_khong_tao_bai_ma_ghi_de_xuat_cho_nguoi_nhan(): void
    {
        $c = $this->campaign(['plan'], ['mode' => 'autonomous', 'source' => 'plan', 'keywords' => []]);
        $this->plannerKeywords(6);
        $ap = $this->autopilot();

        $r = $ap->tick($c);

        $this->assertSame('plan_review', $r['result']);
        $this->assertSame(0, Article::count(), 'không tự tạo bài planned');
        $wait = CampaignWait::where('campaign_id', $c->id)->where('code', 'plan_review')->firstOrFail();
        $this->assertSame('open', $wait->status);
        $this->assertStringContainsString('đề xuất kế hoạch chờ bạn nhận', $wait->title);

        // Tick lại không nhân đôi mục chờ.
        $ap->tick($c);
        $this->assertSame(1, CampaignWait::where('code', 'plan_review')->count());

        $pending = $ap->pendingProposals($c);
        $this->assertCount(3, $pending);
        $this->assertSame(['keyword_id', 'keyword', 'reason', 'priority'], array_keys($pending[0]));

        $res = $ap->acceptProposals($c, [$pending[0]['keyword_id'], $pending[1]['keyword_id']], $this->user());
        $this->assertSame(2, $res['created']);
        $made = Article::where('campaign_id', $c->id)->where('workflow_status', 'planned')->pluck('primary_keyword')->all();
        $this->assertEqualsCanonicalizing([$pending[0]['keyword'], $pending[1]['keyword']], $made);
    }

    public function test_khong_dat_diem_dung_ke_hoach_thi_autopilot_tao_bai_nhu_cu(): void
    {
        $c = $this->campaign([], ['mode' => 'autonomous', 'source' => 'plan', 'keywords' => []]);
        $this->plannerKeywords(6);

        $this->assertSame('acted', $this->autopilot()->tick($c)['result']);
        $this->assertSame(3, Article::where('campaign_id', $c->id)->count());
    }

    private function scheduled(Article $a, string $key, string $status = 'scheduled'): Publication
    {
        $v = ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 't', 'slug' => 's', 'html_content' => '<p>x</p>', 'review_status' => 'approved']);

        return Publication::create(['website_id' => $this->site->id, 'article_version_id' => $v->id, 'publication_key' => $key, 'scheduled_at_utc' => now()->subMinute(), 'status' => $status]);
    }

    public function test_diem_dung_dang_bai_cua_chien_dich_cho_xac_nhan_va_khong_bao_gio_duoc_gui(): void
    {
        $c = $this->campaign(['publish']);
        $a = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'a', 'workflow_status' => 'approved', 'campaign_id' => $c->id]);
        $b = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'b', 'workflow_status' => 'approved']);
        $pa = $this->scheduled($a, 'ka');
        $pb = $this->scheduled($b, 'kb');

        $this->assertSame('awaiting_confirm', $pa->fresh()->status);
        $this->assertSame('scheduled', $pb->fresh()->status, 'bài lẻ không bị giữ');

        Queue::fake();
        $this->artisan('autoseo:publish-due-posts')->assertSuccessful();
        Queue::assertPushed(PublishArticleJob::class, 1);
        $this->assertSame('awaiting_confirm', $pa->fresh()->status);

        // Dù job đã nằm sẵn trong hàng đợi, lượt chờ xác nhận cũng không được gửi.
        (new PublishArticleJob($pa))->handle(app(PublisherManager::class));
        $this->assertSame('awaiting_confirm', $pa->fresh()->status);
        $this->assertSame(0, $pa->fresh()->attempt_count);

        // Người xác nhận → về lịch.
        (new PublicationHold)->resume($this->user('approver'), $pa);
        $this->assertSame('scheduled', $pa->fresh()->status);
    }

    public function test_tam_dung_va_tiep_tuc_tung_luot_dang_chi_tac_dong_dung_bai_do(): void
    {
        $a = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'a', 'workflow_status' => 'scheduled']);
        $b = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'b', 'workflow_status' => 'scheduled']);
        $pa = $this->scheduled($a, 'ka');
        $pb = $this->scheduled($b, 'kb');
        $approver = $this->user('approver');

        (new PublicationHold)->pause($approver, $pa);
        $this->assertSame('paused', $pa->fresh()->status);
        $this->assertSame('scheduled', $pb->fresh()->status);

        Queue::fake();
        $this->artisan('autoseo:publish-due-posts')->assertSuccessful();
        Queue::assertPushed(PublishArticleJob::class, fn ($j) => $j->publication->id === $pb->id);
        Queue::assertPushed(PublishArticleJob::class, 1);

        (new PublishArticleJob($pa))->handle(app(PublisherManager::class));
        $this->assertSame('paused', $pa->fresh()->status, 'worker bỏ qua lượt đã dừng');

        (new PublicationHold)->resume($approver, $pa);
        $this->assertSame('scheduled', $pa->fresh()->status);
    }

    public function test_tam_dung_can_quyen_nguoi_duyet_va_khong_dung_duoc_luot_dang_gui(): void
    {
        $a = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'a', 'workflow_status' => 'scheduled']);
        $p = $this->scheduled($a, 'ka');
        try {
            (new PublicationHold)->pause($this->user('writer'), $p);
            $this->fail('writer không được dừng');
        } catch (\InvalidArgumentException) {
            $this->assertSame('scheduled', $p->fresh()->status);
        }
        $p->update(['status' => 'publishing']);
        $this->expectException(\InvalidArgumentException::class);
        (new PublicationHold)->pause($this->user('approver'), $p);
    }

    public function test_trang_bai_nut_tam_dung_tung_bai_va_khong_dung_bai_khac(): void
    {
        $approver = $this->user('approver');
        $this->actingAs($approver);
        $a = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'a', 'workflow_status' => 'scheduled']);
        $b = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'b', 'workflow_status' => 'scheduled']);
        $pa = $this->scheduled($a, 'ka');
        $pb = $this->scheduled($b, 'kb');

        Livewire::test(ViewArticle::class, ['record' => $a->getKey()])
            ->assertSee('data-testid="aw-pub-pause"', false)
            ->call('mountAction', 'pausePublication', ['id' => $pa->id])->callMountedAction()
            ->assertSee('data-testid="aw-pub-resume"', false);
        $this->assertSame('paused', $pa->fresh()->status);

        // Tham số trỏ sang lượt của bài khác: bị từ chối, lượt kia không đổi.
        try {
            Livewire::test(ViewArticle::class, ['record' => $a->getKey()])->call('mountAction', 'pausePublication', ['id' => $pb->id])->callMountedAction();
        } catch (\Throwable) {
        }
        $this->assertSame('scheduled', $pb->fresh()->status);

        Livewire::test(ViewArticle::class, ['record' => $a->getKey()])
            ->call('mountAction', 'resumePublication', ['id' => $pa->id])->callMountedAction();
        $this->assertSame('scheduled', $pa->fresh()->status);
    }

    public function test_trang_bai_khong_con_viec_tiep_theo_va_co_khoi_dan_y_cho_xem(): void
    {
        $this->actingAs($this->user('writer'));
        $c = $this->campaign(['outline']);
        $article = $this->runner()->tick($c)['run']->article;

        $html = Livewire::test(ViewArticle::class, ['record' => $article->getKey()])
            ->assertDontSee('data-testid="aw-next"', false)->assertDontSee('Việc tiếp theo')
            ->assertSee('data-testid="aw-outline-hold"', false)->assertSee('Dàn ý chờ bạn xem')
            ->assertSee('Nội dung chính')
            ->assertSee('Duyệt dàn ý và viết tiếp')->assertSee('Giao lại từ đầu')
            ->html();
        $this->assertStringNotContainsString('data-testid="aw-primary-bar"', $html, 'không còn thanh nút dưới hàng tab');

    }

    public function test_tong_quan_liet_ke_dan_y_cho_xem_cua_chien_dich(): void
    {
        $user = $this->user('approver');
        $article = $this->runner()->tick($this->campaign(['outline']))['run']->article;

        $rows = DashboardQueries::decisions($user, now());

        $row = $rows->firstWhere('kind', 'checkpoint');
        $this->assertNotNull($row);
        $this->assertSame($article->id, $row['article_id']);
        $this->assertStringContainsString('Dàn ý chờ bạn xem', $row['waiting_label']);
        $this->assertSame(ArticleResource::getUrl('view', ['record' => $article]), ArticleResource::getUrl('view', ['record' => $row['article_id']]));

        (new OutlineHold)->approve($article, ['Mở đầu'], $this->user(), $this->ai());
        $this->assertNull(DashboardQueries::decisions($user, now())->firstWhere('kind', 'checkpoint'), 'đã duyệt thì hết chờ');
    }
}
