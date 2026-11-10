<?php

namespace Tests\Feature\Agent;

use App\Filament\Clusters\AgentTeam\Pages\AgentOverview;
use App\Filament\Resources\AgentRunResource;
use App\Filament\Resources\AgentRunResource\Pages\ListAgentRuns;
use App\Filament\Support\AiUsage\UsageAggregator;
use App\Filament\Support\AiUsage\UsageReader;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Article;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Trust\TrustMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** G2: phiên chạy KHÔNG gắn bài (job_type khác article_write, article_id null) không làm hỏng trang nào và hiện đúng. */
class AgentRunWithoutArticleTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->actingAs(User::create(['name' => 'a', 'email' => 'a@x.vn', 'password' => 'secret123', 'role' => 'approver', 'is_active' => true]));
    }

    private function mkRun(string $status = 'ready_for_review', float $cost = 1234): AgentRun
    {
        $run = AgentRun::create([
            'website_id' => $this->site->id, 'job_type' => 'market_research', 'article_id' => null, 'status' => $status, 'ai_mode' => 'mock',
            'trust_tier' => 1, 'tokens_used' => 500, 'cost_vnd' => $cost, 'started_at' => now(),
            'input_refs' => ['seed' => 'giày chạy bộ'], 'output_ref' => ['keywords' => [1, 2]],
        ]);
        foreach ([['collect', 'Gom từ khóa', 'ok'], ['suggest_kw', 'Đề xuất từ khóa', 'ok'], ['cluster', 'Gom cụm', 'warn']] as $i => [$stage, $title, $st]) {
            AgentRunStep::create(['agent_run_id' => $run->id, 'seq' => $i + 1, 'stage' => $stage, 'role' => 'system', 'status' => $st, 'title' => $title, 'tokens_used' => 100, 'cost_vnd' => 10]);
        }

        return $run;
    }

    public function test_danh_sach_va_chi_tiet_phien_khong_co_bai_tra_200_va_hien_nhan_loai_viec(): void
    {
        $run = $this->mkRun();

        $this->get(AgentRunResource::getUrl('index'))->assertOk()->assertSee('Khảo sát thị trường');
        $this->get(AgentRunResource::getUrl('view', ['record' => $run]))->assertOk()
            ->assertSee('Khảo sát thị trường')->assertSee('Đề xuất từ khóa')->assertSee('Gom cụm')
            ->assertDontSee('Từ khóa:');
    }

    public function test_phien_dang_chay_khong_co_bai_van_ve_duoc(): void
    {
        $run = $this->mkRun('running');

        $this->get(AgentRunResource::getUrl('index'))->assertOk();
        $this->get(AgentRunResource::getUrl('view', ['record' => $run]))->assertOk();
        Livewire::test(AgentOverview::class)->assertSuccessful();
    }

    public function test_bo_loc_loai_viec(): void
    {
        $mr = $this->mkRun();
        $article = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'bài thường']);
        $aw = AgentRun::create(['website_id' => $this->site->id, 'article_id' => $article->id, 'status' => 'ready_for_review', 'ai_mode' => 'mock', 'trust_tier' => 1]);
        $this->assertSame('article_write', $aw->fresh()->job_type);

        Livewire::test(ListAgentRuns::class)
            ->assertCanSeeTableRecords([$mr, $aw])
            ->filterTable('job_type', 'market_research')
            ->assertCanSeeTableRecords([$mr])->assertCanNotSeeTableRecords([$aw])
            ->filterTable('job_type', 'article_write')
            ->assertCanSeeTableRecords([$aw])->assertCanNotSeeTableRecords([$mr]);
    }

    public function test_chi_phi_ai_cong_ca_phien_khong_co_bai(): void
    {
        $this->mkRun(cost: 4321);

        $rows = UsageReader::rows();
        $this->assertCount(1, $rows);
        $this->assertSame(0, $rows[0]['article_id']);
        $this->assertSame('Khảo sát thị trường', $rows[0]['title']);

        $agg = new UsageAggregator($rows);
        $from = CarbonImmutable::now()->subDay()->startOfDay();
        $to = CarbonImmutable::now()->addDay();
        $s = $agg->summary($from, $to);
        $this->assertEqualsWithDelta(4321.0, $s['cost'], 0.01);
        $this->assertSame(0, $s['articles']);
        $this->assertSame([], $agg->topArticles($from, $to));

        $this->get('/admin/chi-phi-ai')->assertOk();
        $this->get('/admin/chi-phi-ai?site='.$this->site->id)->assertOk();
    }

    public function test_xoa_bai_khong_xoa_phien_khong_gan_bai(): void
    {
        $run = $this->mkRun();
        $article = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'sẽ xóa']);
        $withArticle = AgentRun::create(['website_id' => $this->site->id, 'article_id' => $article->id, 'status' => 'failed', 'ai_mode' => 'mock', 'trust_tier' => 1]);

        $article->forceDelete();

        $this->assertNotNull(AgentRun::find($run->id));
        $this->assertCount(3, $run->fresh()->steps);
        $this->assertNull(AgentRun::find($withArticle->id));
    }

    public function test_chi_so_tu_dong_chi_tinh_viec_viet_bai(): void
    {
        $this->mkRun('failed');

        $this->assertSame(0, TrustMetrics::compute()['finished']['n']);
    }
}
