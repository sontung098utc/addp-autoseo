<?php

namespace Tests\Feature\Keywords;

use App\Contracts\StructuredAiInterface;
use App\Filament\Pages\KeywordsPage;
use App\Filament\Support\Keywords\KeywordService;
use App\Models\AgentRun;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\User;
use App\Models\Website;
use App\Services\Keywords\DataForSeoKeywordMetricsProvider;
use App\Services\Keywords\KeywordMetricsEnricher;
use App\Services\Keywords\KeywordMetricsFailed;
use App\Services\Keywords\KeywordMetricsResolver;
use App\Services\Keywords\KeywordMetricsUnavailable;
use App\Services\Keywords\KeywordResearchService;
use App\Services\Keywords\MockKeywordMetricsProvider;
use App\Services\Planning\ContentPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class KeywordMetricsTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['studio.keywords_real' => true, 'keywords.dataforseo.login' => 'user@x.vn', 'keywords.dataforseo.password' => 'S3cretPass', 'keywords.cost_per_100_keywords_vnd' => 1000]);
        $this->site = Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function kw(string $k, array $extra = []): Keyword
    {
        return Keyword::create($extra + ['website_id' => $this->site->id, 'keyword' => $k, 'keyword_key' => Keyword::keyFor($k), 'intent' => 'informational', 'source' => 'ai', 'status' => 'new']);
    }

    private function apiOk(array $items): array
    {
        return ['status_code' => 20000, 'tasks' => [['status_code' => 20000, 'result' => $items]]];
    }

    public function test_provider_doc_ket_qua_va_gui_dung_yeu_cau(): void
    {
        Http::fake(['api.dataforseo.com/*' => Http::response($this->apiOk([
            ['keyword' => 'giày chạy bộ', 'search_volume' => 1900, 'competition_index' => 45, 'cpc' => 0.5],
            ['keyword' => 'giày lạ', 'search_volume' => null, 'competition_index' => null, 'cpc' => null],
        ]))]);
        $r = (new DataForSeoKeywordMetricsProvider('user@x.vn', 'S3cretPass'))->metrics(['Giày chạy bộ', 'giày lạ', 'từ khóa không về']);

        $this->assertSame(['volume' => 1900, 'competition' => 0.45, 'cpc' => 0.5], $r['rows']['giày chạy bộ']);
        $this->assertNull($r['rows']['giày lạ']['volume']);
        $this->assertNull($r['rows']['từ khóa không về']['volume']);
        $this->assertSame('dataforseo', $r['provider']);
        $this->assertSame(1000.0, $r['cost_vnd']);
        Http::assertSent(function (Request $q) {
            return $q->url() === 'https://api.dataforseo.com/v3/keywords_data/google_ads/search_volume/live'
                && $q->hasHeader('Authorization', 'Basic '.base64_encode('user@x.vn:S3cretPass'))
                && $q->data()[0]['location_code'] === 2704 && $q->data()[0]['language_code'] === 'vi'
                && $q->data()[0]['keywords'] === ['giày chạy bộ', 'giày lạ', 'từ khóa không về'];
        });
    }

    public function test_provider_chia_lo_100(): void
    {
        Http::fake(['api.dataforseo.com/*' => Http::response($this->apiOk([]))]);
        $kws = array_map(fn ($i) => "từ khóa $i", range(1, 250));
        (new DataForSeoKeywordMetricsProvider('a', 'b'))->metrics($kws);
        Http::assertSentCount(3);
    }

    public function test_provider_loi_an_dang_nhap(): void
    {
        Http::fake(['api.dataforseo.com/*' => Http::response(['status_code' => 40100, 'status_message' => 'bad S3cretPass'], 200)]);
        try {
            (new DataForSeoKeywordMetricsProvider('user@x.vn', 'S3cretPass'))->metrics(['a']);
            $this->fail('phải ném lỗi');
        } catch (KeywordMetricsFailed $e) {
            $this->assertStringContainsString('40100', $e->getMessage());
            $this->assertStringNotContainsString('S3cretPass', $e->getMessage());
        }
        Http::fake(['api.dataforseo.com/*' => Http::response('x', 401)]);
        $this->expectException(KeywordMetricsFailed::class);
        (new DataForSeoKeywordMetricsProvider('user@x.vn', 'S3cretPass'))->metrics(['a']);
    }

    public function test_provider_loi_tac_vu(): void
    {
        Http::fake(['api.dataforseo.com/*' => Http::response(['status_code' => 20000, 'tasks' => [['status_code' => 40501, 'status_message' => 'Invalid Field']]])]);
        $this->expectExceptionMessage('40501');
        (new DataForSeoKeywordMetricsProvider('a', 'b'))->metrics(['a']);
    }

    public function test_resolver_thieu_dang_nhap_va_mock_chi_o_local(): void
    {
        config(['keywords.dataforseo.login' => null, 'keywords.dataforseo.password' => null]);
        $av = KeywordMetricsResolver::availability();
        $this->assertFalse($av['ok']);
        $this->assertStringContainsString('DATAFORSEO_LOGIN / DATAFORSEO_PASSWORD', $av['reason']);
        try {
            KeywordMetricsResolver::resolve();
            $this->fail();
        } catch (KeywordMetricsUnavailable) {
            $this->addToAssertionCount(1);
        }
        $this->app['env'] = 'local';
        $r = KeywordMetricsResolver::resolve();
        $this->assertTrue($r['demo']);
        $this->assertInstanceOf(MockKeywordMetricsProvider::class, $r['provider']);
        $this->app['env'] = 'testing';
        config(['keywords.dataforseo.login' => 'a', 'keywords.dataforseo.password' => 'b']);
        $this->assertFalse(KeywordMetricsResolver::resolve()['demo']);
    }

    public function test_mock_tat_dinh(): void
    {
        $p = new MockKeywordMetricsProvider;
        $this->assertSame($p->metrics(['abc']), array_replace($p->metrics(['abc']), ['fetched_at' => $p->metrics(['abc'])['fetched_at']]));
        $this->assertSame($p->metrics(['abc'])['rows'], $p->metrics(['abc'])['rows']);
        $this->assertSame('mock', $p->metrics(['abc'])['provider']);
    }

    public function test_enricher_chi_tu_khoa_thieu_so_khong_ghi_de_csv_null_giu_null(): void
    {
        $csv = $this->kw('từ khóa csv', ['volume' => 50, 'volume_source' => 'csv']);
        $legacy = $this->kw('số cũ', ['volume' => 70]);
        $new = $this->kw('giày chạy bộ');
        $none = $this->kw('không có số');
        $stale = $this->kw('số cũ của nhà cung cấp', ['volume' => 5, 'volume_source' => 'dataforseo', 'metrics_at' => now()->subDays(40)]);
        $fresh = $this->kw('số mới', ['volume' => 9, 'volume_source' => 'dataforseo', 'metrics_at' => now()->subDays(2)]);
        Http::fake(['api.dataforseo.com/*' => Http::response($this->apiOk([
            ['keyword' => 'giày chạy bộ', 'search_volume' => 1900, 'competition_index' => 45, 'cpc' => 0.5],
            ['keyword' => 'số cũ của nhà cung cấp', 'search_volume' => 80, 'competition_index' => 10, 'cpc' => 0.1],
            ['keyword' => 'không có số', 'search_volume' => null],
        ]))]);

        $r = (new KeywordMetricsEnricher)->enrich($this->site);

        $this->assertSame(2, $r['updated']);
        $this->assertSame(1, $r['skipped']);
        $this->assertSame(1000.0, $r['cost_vnd']);
        $this->assertNull($r['stopped_reason']);
        $this->assertSame(50, $csv->fresh()->volume);
        $this->assertSame('csv', $csv->fresh()->volume_source);
        $this->assertSame(70, $legacy->fresh()->volume);
        $this->assertSame(9, $fresh->fresh()->volume);
        $n = $new->fresh();
        $this->assertSame(1900, $n->volume);
        $this->assertSame('dataforseo', $n->volume_source);
        $this->assertSame(0.45, $n->competition);
        $this->assertSame(12500, $n->cpc_vnd);
        $this->assertNotNull($n->metrics_at);
        $this->assertSame(80, $stale->fresh()->volume);
        $this->assertNull($none->fresh()->volume);
        $this->assertNull($none->fresh()->volume_source);
        Http::assertSent(fn (Request $q) => $q->data()[0]['keywords'] === ['giày chạy bộ', 'không có số', 'số cũ của nhà cung cấp']);

        // Lần sau: "không có số" vừa được tra nên không tra lại ngay.
        $this->assertSame(0, (new KeywordMetricsEnricher)->pendingCount($this->site));
        // overwrite: ghi đè cả số CSV.
        $this->assertSame(6, (new KeywordMetricsEnricher)->pendingCount($this->site, null, ['overwrite' => true]));
    }

    public function test_enricher_ghi_mot_phien_market_research_co_buoc_metrics(): void
    {
        $a = $this->kw('một');
        Http::fake(['api.dataforseo.com/*' => Http::response($this->apiOk([['keyword' => 'một', 'search_volume' => 10]]))]);
        (new KeywordMetricsEnricher)->enrich($this->site);

        $run = AgentRun::where('job_type', 'market_research')->sole();
        $this->assertSame('ready_for_review', $run->status);
        $this->assertSame([$a->id], $run->output_ref['keyword_ids']);
        $step = $run->steps()->sole();
        $this->assertSame('metrics', $step->stage);
        $this->assertSame('dataforseo', $step->model);
        $this->assertSame(1000.0, (float) $step->cost_vnd);
        $this->assertSame(1, $step->data['keywords']);
    }

    public function test_enricher_khong_co_gi_de_tra_thi_khong_goi_va_khong_ghi_phien(): void
    {
        Http::fake();
        $this->kw('đã có', ['volume' => 5, 'volume_source' => 'csv']);
        $r = (new KeywordMetricsEnricher)->enrich($this->site);
        $this->assertSame(0, $r['updated']);
        Http::assertNothingSent();
        $this->assertSame(0, AgentRun::count());
    }

    public function test_enricher_lo_va_tran_so_luong(): void
    {
        config(['keywords.batch' => 2, 'keywords.max_per_run' => 5]);
        foreach (range(1, 8) as $i) {
            $this->kw("kw $i");
        }
        Http::fake(['api.dataforseo.com/*' => Http::response($this->apiOk([]))]);
        (new KeywordMetricsEnricher)->enrich($this->site);
        Http::assertSentCount(3); // 5 từ khóa, lô 2: 2+2+1
    }

    public function test_enricher_dung_khi_cham_tran_chi_phi(): void
    {
        config(['keywords.batch' => 2, 'keywords.cost_per_100_keywords_vnd' => 1000]);
        foreach (range(1, 6) as $i) {
            $this->kw("kw $i");
        }
        Http::fake(['api.dataforseo.com/*' => Http::response($this->apiOk([['keyword' => 'kw 1', 'search_volume' => 1], ['keyword' => 'kw 2', 'search_volume' => 2]]))]);
        $r = (new KeywordMetricsEnricher(null, 1500))->enrich($this->site);
        Http::assertSentCount(1);
        $this->assertSame(2, $r['updated']);
        $this->assertSame(4, $r['skipped']);
        $this->assertStringContainsString('trần chi phí', $r['stopped_reason']);
        $this->assertNull(Keyword::where('keyword', 'kw 3')->value('volume'));
    }

    public function test_enricher_loi_nha_cung_cap_dung_gon(): void
    {
        $this->kw('a');
        Http::fake(['api.dataforseo.com/*' => Http::response('', 500)]);
        $r = (new KeywordMetricsEnricher)->enrich($this->site);
        $this->assertSame(0, $r['updated']);
        $this->assertStringContainsString('HTTP 500', $r['stopped_reason']);
        $this->assertSame('failed', AgentRun::sole()->status);
    }

    public function test_enricher_chua_cau_hinh_nem_huong_dan(): void
    {
        config(['keywords.dataforseo.login' => null]);
        $this->kw('a');
        $this->expectException(KeywordMetricsUnavailable::class);
        (new KeywordMetricsEnricher)->enrich($this->site);
    }

    private function ai(): StructuredAiInterface
    {
        return new class implements StructuredAiInterface
        {
            public function generateStructured(string $system, string $user): array
            {
                return ['data' => ['keywords' => [['keyword' => 'giày chạy bộ', 'intent' => 'commercial'], ['keyword' => 'cách chọn giày', 'intent' => 'informational']]], 'tokens_used' => 10, 'cost_vnd' => 5.0, 'model' => 'm'];
            }
        };
    }

    public function test_khao_sat_tu_lay_luot_tim_sau_khi_them_tu_khoa_ai(): void
    {
        Http::fake(['api.dataforseo.com/*' => Http::response($this->apiOk([['keyword' => 'giày chạy bộ', 'search_volume' => 900], ['keyword' => 'cách chọn giày', 'search_volume' => 100]]))]);
        $r = (new KeywordResearchService($this->ai(), null, 'AI thử', false))->suggest($this->site, ['giày'], 10);

        $this->assertSame(2, $r['added']);
        $this->assertSame(2, $r['metrics']['updated']);
        $this->assertSame('dataforseo', $r['metrics']['provider']);
        $this->assertSame(900, Keyword::where('keyword', 'giày chạy bộ')->value('volume'));
        $this->assertSame(2, AgentRun::where('job_type', 'market_research')->count()); // phiên khảo sát + phiên lấy số liệu
    }

    public function test_khao_sat_bo_qua_em_khi_khong_co_nguon_so_lieu(): void
    {
        config(['keywords.dataforseo.login' => null, 'keywords.dataforseo.password' => null]);
        Http::fake();
        $r = (new KeywordResearchService($this->ai(), null, 'AI thử', false))->suggest($this->site, ['giày'], 10);

        $this->assertSame(2, $r['added']);
        $this->assertSame(0, $r['metrics']['updated']);
        $this->assertStringContainsString('Chưa có số liệu lượt tìm', $r['metrics']['message']);
        $this->assertNull(Keyword::where('keyword', 'giày chạy bộ')->value('volume'));
        Http::assertNothingSent();
        $this->assertSame(1, AgentRun::count());
    }

    public function test_khao_sat_loi_nha_cung_cap_khong_lam_hong_viec_chinh(): void
    {
        Http::fake(['api.dataforseo.com/*' => Http::response('', 500)]);
        $r = (new KeywordResearchService($this->ai(), null, 'AI thử', false))->suggest($this->site, ['giày'], 10);
        $this->assertSame(2, $r['added']);
        $this->assertSame(0, $r['metrics']['updated']);
    }

    public function test_migration_len_xuong_sqlite(): void
    {
        $this->assertTrue(Schema::hasColumns('keywords', ['volume_source', 'metrics_at', 'competition', 'cpc_vnd']));
        $m = require base_path('database/migrations/2026_11_23_000001_add_volume_source_to_keywords.php');
        $m->down();
        $this->assertFalse(Schema::hasColumn('keywords', 'volume_source'));
        $this->assertFalse(Schema::hasColumn('keywords', 'cpc_vnd'));
        $m->up();
        $this->assertTrue(Schema::hasColumns('keywords', ['volume_source', 'metrics_at', 'competition', 'cpc_vnd']));
    }

    public function test_nhap_csv_va_tay_ghi_nguon_so_lieu(): void
    {
        $this->actingAs($this->writer());
        $svc = KeywordService::make($this->site);
        $svc->addRows([['keyword' => 'a csv', 'volume' => 10, 'intent' => 'informational'], ['keyword' => 'b csv', 'volume' => null, 'intent' => 'informational']], 'csv');
        $svc->addManual('c tay', '20', 'informational');
        $svc->addRows([['keyword' => 'd ai', 'volume' => null, 'intent' => 'informational']], 'ai');
        $v = Keyword::pluck('volume_source', 'keyword')->all();
        $this->assertSame(['a csv' => 'csv', 'b csv' => null, 'c tay' => 'tay', 'd ai' => null], $v);
        // Lưu lại danh sách (ví dụ đổi tên cụm) không làm mất nhãn nguồn của số do DataForSEO điền.
        Keyword::where('keyword', 'd ai')->update(['volume' => 7, 'volume_source' => 'dataforseo']);
        $svc->delete(Keyword::where('keyword', 'b csv')->value('id'));
        $this->assertSame('dataforseo', Keyword::where('keyword', 'd ai')->value('volume_source'));
    }

    private function writer(): User
    {
        return User::create(['name' => 'w', 'email' => 'w@x.vn', 'password' => 'secret123', 'role' => 'writer', 'is_active' => true]);
    }

    public function test_trang_hien_day_du_dai_buoc_the_nguon_va_nhan_nguon(): void
    {
        $this->actingAs($this->writer());
        $this->kw('có csv', ['volume' => 100, 'volume_source' => 'csv']);
        $this->kw('có dfs', ['volume' => 900, 'volume_source' => 'dataforseo', 'metrics_at' => now()]);
        $this->kw('chưa có');
        $t = Livewire::test(KeywordsPage::class);
        $t->assertSee('Nguồn số liệu lượt tìm')->assertSee('2/3 từ khóa có số liệu, 1 từ nguồn DataForSEO')
            ->assertSee('Lấy lượt tìm thật');
        $src = collect($t->viewData('kwRows'))->pluck('volumeSource', 'keyword')->all();
        $this->assertSame(['có csv' => 'CSV', 'có dfs' => 'DataForSEO', 'chưa có' => 'Chưa có số liệu'], $src);
        $this->get('/admin/tu-khoa')->assertOk()->assertDontSee('data-testid="chain-context"', false)->assertSee('Mở Kế hoạch');
    }

    public function test_nut_lay_luot_tim_bat_tat_dung(): void
    {
        $this->actingAs($this->writer());
        $this->kw('a');
        Livewire::test(KeywordsPage::class)->assertActionEnabled('fetchMetrics');
        config(['keywords.dataforseo.login' => null]);
        Livewire::test(KeywordsPage::class)->assertActionDisabled('fetchMetrics')->assertSee('DATAFORSEO_LOGIN / DATAFORSEO_PASSWORD');
        config(['keywords.dataforseo.login' => 'u']);
        Keyword::query()->update(['volume' => 1, 'volume_source' => 'csv']);
        Livewire::test(KeywordsPage::class)->assertActionDisabled('fetchMetrics');
    }

    public function test_bam_nut_chay_enrich_va_thong_bao(): void
    {
        $this->actingAs($this->writer());
        $this->kw('giày chạy bộ');
        Http::fake(['api.dataforseo.com/*' => Http::response($this->apiOk([['keyword' => 'giày chạy bộ', 'search_volume' => 300]]))]);
        Livewire::test(KeywordsPage::class)->callAction('fetchMetrics')->assertNotified('Đã cập nhật lượt tìm cho 1 từ khóa');
        $this->assertSame(300, Keyword::first()->volume);
    }

    public function test_tab_cum_tong_luot_tim_xep_giam_dan(): void
    {
        $this->actingAs($this->writer());
        $c1 = KeywordCluster::create(['website_id' => $this->site->id, 'name' => 'Cụm nhỏ', 'intent' => 'informational']);
        $c2 = KeywordCluster::create(['website_id' => $this->site->id, 'name' => 'Cụm lớn', 'intent' => 'informational']);
        $this->kw('a', ['cluster_id' => $c1->id, 'volume' => 10, 'volume_source' => 'csv']);
        $this->kw('b', ['cluster_id' => $c2->id, 'volume' => 500, 'volume_source' => 'csv']);
        $this->kw('c', ['cluster_id' => $c2->id, 'volume' => 100, 'volume_source' => 'csv']);
        $this->kw('d', ['cluster_id' => $c2->id]);
        $rows = Livewire::test(KeywordsPage::class)->viewData('clusterRows');
        $this->assertSame(['Cụm lớn', 'Cụm nhỏ'], array_column($rows, 'name'));
        $this->assertSame(600, $rows[0]['volume']);
        $this->assertSame(1, $rows[0]['missing']);
        $this->assertSame(0, $rows[1]['missing']);
    }

    public function test_ke_hoach_noi_dung_hien_nguon_so_lieu(): void
    {
        $this->kw('có dfs', ['volume' => 900, 'volume_source' => 'dataforseo']);
        $rows = (new ContentPlanner)->propose($this->site, 5);
        $this->assertContains('lượt tìm 900 (DataForSEO)', $rows[0]['reasons']);
        $this->assertSame('dataforseo', $rows[0]['volume_source']);
    }
}
