<?php

namespace Tests\Feature\Keywords;

use App\Contracts\StructuredAiInterface;
use App\Filament\Support\Keywords\KeywordService;
use App\Models\AgentRun;
use App\Models\Keyword;
use App\Models\Website;
use App\Services\Agent\Run\JobRunRecorder;
use App\Services\Keywords\KeywordAiUnavailable;
use App\Services\Keywords\KeywordResearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class KeywordResearchRunRecordTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['studio.keywords_real' => true]);
        $this->site = Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function fake(array $data, float $cost = 10.0): StructuredAiInterface
    {
        return new class($data, $cost) implements StructuredAiInterface
        {
            public function __construct(private array $data, private float $cost) {}

            public function generateStructured(string $system, string $user): array
            {
                return ['data' => $this->data, 'tokens_used' => 100, 'cost_vnd' => $this->cost, 'model' => 'fake-m'];
            }
        };
    }

    private function suggest(float $cost = 10.0, ?float $cap = null, bool $demo = false)
    {
        $d = ['keywords' => [['keyword' => 'giày chạy bộ', 'intent' => 'commercial'], ['keyword' => 'cách chọn giày', 'intent' => 'informational']]];

        return (new KeywordResearchService($this->fake($d, $cost), $cap, 'AI thử', $demo))->suggest($this->site, ['giày'], 20);
    }

    public function test_de_xuat_ghi_dung_mot_phien(): void
    {
        $r = $this->suggest();
        $this->assertSame(2, $r['added']);
        $run = AgentRun::query()->where('job_type', 'market_research')->sole();
        $this->assertNull($run->article_id);
        $this->assertSame($this->site->id, $run->website_id);
        $this->assertSame('ready_for_review', $run->status);
        $this->assertSame('real', $run->ai_mode);
        $this->assertSame(100, $run->tokens_used);
        $this->assertEqualsWithDelta(10.0, $run->cost_vnd, 0.001);
        $this->assertSame(['guard', 'suggest', 'save'], $run->steps()->orderBy('seq')->pluck('stage')->all());
        $this->assertEqualsWithDelta($run->steps()->sum('cost_vnd'), $run->cost_vnd, 0.001);
        $this->assertSame('fake-m', $run->steps()->where('stage', 'suggest')->value('model'));
        $ids = Keyword::where('website_id', $this->site->id)->where('source', 'ai')->pluck('id')->all();
        $this->assertEqualsCanonicalizing($ids, $run->output_ref['keyword_ids']);
        $this->assertSame(2, $run->output_ref['added']);
        $this->assertSame(['giày'], $run->input_refs['seeds']);
    }

    public function test_chay_lai_ghi_phien_thu_hai_khong_hong(): void
    {
        $this->suggest();
        $r = $this->suggest();
        $this->assertSame(0, $r['added']);
        $this->assertSame(2, AgentRun::where('job_type', 'market_research')->count());
        $this->assertSame([], AgentRun::latest('id')->first()->output_ref['keyword_ids']);
    }

    public function test_ai_gia_lap_ghi_mock(): void
    {
        $this->suggest(0.0, null, true);
        $this->assertSame('mock', AgentRun::where('job_type', 'market_research')->sole()->ai_mode);
    }

    public function test_vuot_tran_chi_phi_la_blocked_va_van_ghi_chi_phi(): void
    {
        $r = $this->suggest(10000000.0);
        $this->assertSame('budget', $r['stopped']);
        $run = AgentRun::where('job_type', 'market_research')->sole();
        $this->assertSame('blocked', $run->status);
        $this->assertStringContainsString('vượt trần', (string) $run->outcome_reason);
        $this->assertEqualsWithDelta(10000000.0, $run->cost_vnd, 0.001);
        $this->assertSame(0, Keyword::count());
    }

    public function test_thieu_ai_ghi_blocked_khong_ton_chi_phi(): void
    {
        config(['app.env' => 'production']);
        $this->app['env'] = 'production';
        try {
            (new KeywordResearchService)->suggest($this->site, ['x']);
            $this->fail('phải ném KeywordAiUnavailable');
        } catch (KeywordAiUnavailable) {
        }
        $run = AgentRun::where('job_type', 'market_research')->sole();
        $this->assertSame('blocked', $run->status);
        $this->assertEquals(0, $run->cost_vnd);
    }

    public function test_gom_cum_ghi_phien_tung_lo(): void
    {
        $svc = KeywordService::make();
        $svc->addRows(array_map(fn ($i) => ['keyword' => "kw$i", 'volume' => null, 'intent' => 'informational'], range(1, 70)));
        $ai = $this->fake(['clusters' => [['name' => 'G', 'intent' => 'informational', 'keywords' => ['kw1']]]], 5.0);
        $r = $svc->proposeWithAi($ai);
        $this->assertArrayNotHasKey('before', $r);
        $run = AgentRun::where('job_type', 'market_research')->sole();
        $this->assertNull($run->article_id);
        $this->assertSame('ready_for_review', $run->status);
        $this->assertSame(['guard', 'cluster', 'cluster', 'save'], $run->steps()->orderBy('seq')->pluck('stage')->all());
        $this->assertSame(200, $run->tokens_used);
        $this->assertEqualsWithDelta(10.0, $run->cost_vnd, 0.001);
        $this->assertSame($r['proposals'], $run->output_ref['proposals']);
    }

    public function test_loi_ghi_phien_khong_lam_hong_viec_chinh(): void
    {
        Schema::drop('agent_run_steps');
        Schema::drop('agent_runs');
        $r = $this->suggest();
        $this->assertSame(2, $r['added']);
        $this->assertNull(JobRunRecorder::start('market_research', $this->site));
    }
}
