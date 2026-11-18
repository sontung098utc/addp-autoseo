<?php

namespace Tests\Feature\Agent;

use App\Models\AgentRun;
use App\Models\Article;
use App\Models\Campaign;
use App\Models\Website;
use App\Services\Agent\Run\CampaignRunner;
use App\Services\Ai\DemoScriptedAiProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Chiến dịch (nguồn 'plan', vd nhập từ file) chỉ chạy bài THUỘC CHIẾN DỊCH; bài planned không chiến dịch (bài thử cũ) chỉ bị lấy khi bật rõ ràng. */
class CampaignOnlyOwnArticlesTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $spawned = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['agent.campaign_concurrency' => 5, 'agent.max_parallel_runs' => 10]);
        $this->spawned = [];
        CampaignRunner::$spawner = function (Article $a, int $campaignId) {
            $this->spawned[] = (string) $a->primary_keyword;

            return AgentRun::create(['article_id' => $a->id, 'website_id' => $a->website_id, 'campaign_id' => $campaignId, 'status' => 'running', 'trigger' => 'schedule', 'started_at' => now(), 'ai_mode' => 'real']);
        };
    }

    protected function tearDown(): void
    {
        CampaignRunner::$spawner = null;
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** Website có bài thử cũ (ưu tiên cao, id nhỏ) + chiến dịch nhập từ file 36 bài priority NULL. */
    private function fixture(array $settings = [], int $own = 36): Campaign
    {
        $w = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://'.uniqid().'.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        Article::create(['website_id' => $w->id, 'primary_keyword' => 'cách viết thư xin học bổng', 'workflow_status' => 'planned', 'origin' => 'plan', 'priority' => 90]);
        Article::create(['website_id' => $w->id, 'primary_keyword' => 'Bệnh tiểu đường', 'workflow_status' => 'planned', 'origin' => 'plan']);
        $c = Campaign::create(['website_id' => $w->id, 'name' => 'test thử upfile kế hoạch', 'status' => 'active', 'source' => 'plan', 'daily_limit' => 50, 'keywords' => [], 'settings' => $settings]);
        for ($i = 1; $i <= $own; $i++) {
            Article::create(['website_id' => $w->id, 'primary_keyword' => "từ khóa file {$i}", 'workflow_status' => 'planned', 'origin' => 'manual', 'origin_ref' => 'file:kehoach.xlsx', 'campaign_id' => $c->id]);
        }

        return $c;
    }

    private function runner(): CampaignRunner
    {
        return new CampaignRunner(fn () => new DemoScriptedAiProvider);
    }

    public function test_mac_dinh_chi_chay_bai_cua_chien_dich_va_khong_keo_bai_cu(): void
    {
        $c = $this->fixture();
        $this->runner()->tick($c, CarbonImmutable::now());

        $this->assertNotEmpty($this->spawned);
        foreach ($this->spawned as $k) {
            $this->assertStringStartsWith('từ khóa file', $k);
        }
        $this->assertSame('planned', Article::where('primary_keyword', 'cách viết thư xin học bổng')->value('workflow_status'));
        $this->assertNull(Article::where('primary_keyword', 'Bệnh tiểu đường')->value('campaign_id'));
    }

    public function test_het_bai_cua_chien_dich_thi_dung_khong_lay_bai_noi_khac(): void
    {
        $c = $this->fixture(own: 0);
        $r = $this->runner()->tick($c, CarbonImmutable::now());

        $this->assertSame('no_keywords', $r['result']);
        $this->assertSame([], $this->spawned);
        $this->assertSame(2, Article::count(), 'không âm thầm tạo bài mới');
        $this->assertNull(Article::where('primary_keyword', 'Bệnh tiểu đường')->value('campaign_id'));
    }

    public function test_bai_cua_chien_dich_khac_khong_bao_gio_bi_lay(): void
    {
        $c = $this->fixture(['pull_unassigned_plan' => true], own: 0);
        $other = Campaign::create(['website_id' => $c->website_id, 'name' => 'khác', 'status' => 'paused', 'source' => 'plan', 'keywords' => []]);
        Article::create(['website_id' => $c->website_id, 'primary_keyword' => 'bài chiến dịch khác', 'workflow_status' => 'planned', 'campaign_id' => $other->id]);

        $this->runner()->tick($c, CarbonImmutable::now());

        $this->assertNotContains('bài chiến dịch khác', $this->spawned);
    }

    public function test_bat_tuy_chon_thi_moi_lay_them_bai_chua_thuoc_chien_dich(): void
    {
        $c = $this->fixture(['pull_unassigned_plan' => true], own: 0);
        $this->runner()->tick($c, CarbonImmutable::now());

        $this->assertEqualsCanonicalizing(['cách viết thư xin học bổng', 'Bệnh tiểu đường'], $this->spawned);
    }

    public function test_chien_dich_keywords_rong_khong_tao_bai_moi(): void
    {
        $c = $this->fixture(own: 0);
        $c->update(['source' => 'keywords', 'keywords' => []]);
        $r = $this->runner()->tick($c->fresh(), CarbonImmutable::now());

        $this->assertSame('no_keywords', $r['result']);
        $this->assertSame(2, Article::count());
    }
}
