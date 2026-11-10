<?php

namespace Tests\Feature\Agent;

use App\Contracts\AiProviderInterface;
use App\Filament\Resources\CampaignResource;
use App\Filament\Support\Review\ReviewWorkflow;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Campaign;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Run\CampaignRunner;
use App\Services\Content\Quality\ContentQualityChecker;
use App\Services\Content\Quality\QualityReport;
use App\Services\Content\Quality\RuleResult;
use App\Services\Publishing\EmergencyStopService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Unit\QualityFixtures;

/** Chế độ Toàn quyền: chiến dịch tự giao việc và tự duyệt có điều kiện (AI giả tất định; mức bằng chứng: test giả lập). */
class CampaignAutonomousTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(EmergencyStopService::class)->resetForTesting();
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

    private function campaign(array $kw = ['học bổng toàn phần'], array $over = []): Campaign
    {
        $w = Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://'.uniqid().'.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);

        return Campaign::create($over + ['website_id' => $w->id, 'created_by' => $this->user('writer')->id, 'name' => 'CD', 'status' => 'active', 'mode' => 'autonomous', 'source' => 'keywords', 'daily_limit' => 3, 'keywords' => $kw]);
    }

    private function runner(float $cost = 10.0): CampaignRunner
    {
        $ai = new class($cost) implements AiProviderInterface
        {
            public function __construct(private float $cost) {}

            public function generateOutline(Article $article, array $options = []): array
            {
                return ['outline' => ['Mở đầu'], 'tokens_used' => 10, 'model' => 'fake'];
            }

            public function generateArticle(Article $article, array $outline, array $options = []): array
            {
                return QualityFixtures::goodAttrs($article->primary_keyword) + ['tokens_used' => 100, 'cost_vnd' => $this->cost, 'model' => 'fake', 'prompt_version' => 'test'];
            }

            public function calculateCostVnd(int $promptTokens, int $completionTokens, string $model): float
            {
                return 0.0;
            }
        };

        return new CampaignRunner(fn () => $ai);
    }

    public function test_giao_viec_va_tu_duyet_bai_thuong(): void
    {
        $c = $this->campaign();
        $r = $this->runner()->tick($c);

        $this->assertSame('auto_approved', $r['result'], $r['message']);
        $this->assertStringContainsString('Đã tự duyệt bài', $r['message']);
        $run = $r['run']->fresh(['article', 'finalVersion']);
        $this->assertSame('approved', $run->article->workflow_status);
        $this->assertSame('approved', $run->finalVersion->review_status);
        $sys = User::where('email', CampaignRunner::SYSTEM_EMAIL)->firstOrFail();
        $this->assertSame($sys->id, (int) $run->finalVersion->reviewed_by);
        $this->assertStringContainsString('Tự duyệt bởi đội AI (chế độ Toàn quyền)', (string) $run->finalVersion->review_notes);
        $this->assertTrue($run->steps->contains(fn ($s) => ($s->data['decision'] ?? null) === 'auto_approved'));
        $this->assertSame(0, Publication::count(), 'không tự xếp lịch/đăng công khai');

        // Tái dùng một người dùng hệ thống duy nhất
        $this->runner()->tick($c);
        $this->assertSame(1, User::where('email', CampaignRunner::SYSTEM_EMAIL)->count());
    }

    public function test_bai_y_te_rui_ro_cao_o_lai_cho_nguoi(): void
    {
        $c = $this->campaign(['cách điều trị tiểu đường']);
        $r = $this->runner()->tick($c);

        $this->assertSame('ran', $r['result'], $r['message']);
        $this->assertStringContainsString('chờ người xem', $r['message']);
        $run = $r['run']->fresh(['article', 'finalVersion']);
        $this->assertSame('ready_for_review', $run->status);
        $this->assertSame('needs_review', $run->article->workflow_status);
        $this->assertSame('needs_review', $run->finalVersion->review_status);
        $this->assertNull($run->finalVersion->reviewed_by);
    }

    public function test_con_loi_p0_thi_khong_tu_duyet(): void
    {
        $this->app->bind(ReviewWorkflow::class, fn () => new ReviewWorkflow(quality: new class extends ContentQualityChecker
        {
            public function check(ArticleVersion $version, ?Article $article = null, ?array $brainIds = null): QualityReport
            {
                return new QualityReport([new RuleResult('X_P0', RuleResult::P0, false, 'l', 'm')]);
            }
        }));
        $r = $this->runner()->tick($this->campaign());

        $this->assertSame('ran', $r['result']);
        $this->assertSame('needs_review', $r['run']->fresh('finalVersion')->finalVersion->review_status);
    }

    public function test_han_muc_ngay_tran_chi_phi_va_dung_khan_cap_van_chan(): void
    {
        $now = CarbonImmutable::parse('2026-11-07 03:00:00', 'UTC');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        $c = $this->campaign(['a b c', 'd e f', 'g h i'], ['daily_limit' => 1]);
        $this->assertSame('auto_approved', $this->runner()->tick($c, $now)['result']);
        AgentRun::query()->update(['started_at' => $now]);
        $this->assertSame('limit', $this->runner()->tick($c, $now->addHour())['result']);
        $this->assertSame(1, AgentRun::count());

        $c2 = $this->campaign(['k1 k2 k3'], ['daily_cost_cap_vnd' => 5]);
        $this->assertSame('auto_approved', $this->runner(50.0)->tick($c2, $now)['result']);
        AgentRun::query()->where('campaign_id', $c2->id)->update(['started_at' => $now, 'cost_vnd' => 50]);
        $this->assertSame('cost_cap', $this->runner()->tick($c2, $now->addHour())['result']);

        $c3 = $this->campaign(['z y x']);
        app(EmergencyStopService::class)->stop($this->user('admin'), 'thử', $c3->website_id);
        $this->assertSame('stopped', $this->runner()->tick($c3, $now)['result']);
        $this->assertSame(0, AgentRun::where('campaign_id', $c3->id)->count());

        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }

    public function test_o_toi_da_doc_bat_buoc_va_ghi_nhat_ky(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin);
        $w = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://b.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $form = ['name' => 'X', 'website_id' => $w->id, 'keywords' => ['a'], 'daily_limit' => 1, 'status' => 'paused', 'mode' => 'autonomous', 'source' => 'plan', 'daily_cost_cap_vnd' => 1000, 'daily_draft_cap' => 1];

        Livewire::test(CampaignResource\Pages\CreateCampaign::class)->fillForm($form + ['policy_ack' => false])
            ->call('create')->assertHasFormErrors(['policy_ack']);
        $this->assertSame(0, Campaign::count());

        Log::spy();
        Livewire::test(CampaignResource\Pages\CreateCampaign::class)->fillForm($form + ['policy_ack' => true])
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame('autonomous', Campaign::firstOrFail()->mode);
        Log::shouldHaveReceived('info')->withArgs(fn ($m, $ctx = []) => $m === 'campaign.autonomous_policy_ack' && $ctx['user_id'] === $admin->id && ! empty($ctx['policy_version']))->once();
    }

    public function test_form_hien_toan_quyen_va_hop_chinh_sach_va_writer_khong_vao_duoc(): void
    {
        $this->actingAs($this->user('admin'));
        Livewire::test(CampaignResource\Pages\CreateCampaign::class)->fillForm(['mode' => 'autonomous'])
            ->assertSee('Toàn quyền cho đội AI')->assertSee('Tôi đã đọc')->assertSee('dừng khẩn cấp')->assertDontSee('chưa mở');

        $this->actingAs($this->user('writer'));
        $this->get(CampaignResource::getUrl('create'))->assertForbidden();
    }
}
