<?php

namespace Tests\Feature\Keywords;

use App\Contracts\SerpInsightsProvider;
use App\Filament\Pages\KeywordsPage;
use App\Filament\Support\Keywords\EloquentKeywordRepository;
use App\Filament\Support\Keywords\KeywordService;
use App\Models\Keyword;
use App\Models\User;
use App\Models\Website;
use App\Services\Keywords\Serp\SerpClusterer;
use App\Services\Keywords\Serp\SerpInsights;
use App\Services\Keywords\Serp\SerpUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/** Gom cụm theo trùng top 10 SERP. SERP là bảng cố định trong test (không phải kết quả thật của Google). */
class SerpClustererTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string,list<string>> $serp keyword => danh sách URL top 10 theo thứ tự */
    private function insights(array $serp, float $cost = 1000.0): SerpInsights
    {
        $p = new class($serp, $cost) implements SerpInsightsProvider
        {
            public function __construct(private array $serp, private float $cost) {}

            public function name(): string
            {
                return 'dataforseo';
            }

            public function insights(string $keyword, array $options = []): array
            {
                $urls = $this->serp[$keyword] ?? [];

                return ['paa' => [], 'related' => [], 'autocomplete' => [], 'provider' => 'dataforseo', 'cost_vnd' => $this->cost, 'fetched_at' => now()->toIso8601String(),
                    'organic' => array_map(fn ($u, $i) => ['rank' => $i + 1, 'url' => $u, 'title' => 't', 'domain' => 'd'], $urls, array_keys($urls))];
            }
        };

        return new SerpInsights([$p]);
    }

    /** @return list<string> n URL dùng chung theo tiền tố + các URL riêng */
    private function urls(string $shared, int $nShared, string $own): array
    {
        $out = [];
        for ($i = 1; $i <= $nShared; $i++) {
            $out[] = "https://{$shared}{$i}.vn/bai";
        }
        for ($i = count($out) + 1; $i <= 10; $i++) {
            $out[] = "https://{$own}{$i}.vn/bai";
        }

        return $out;
    }

    public function test_gom_theo_so_url_chung_va_khong_dua_vao_chu_giong_nhau(): void
    {
        Cache::flush();
        $serp = [
            'đường huyết cao' => $this->urls('s', 10, 'a'),      // trụ
            'tăng đường huyết' => $this->urls('s', 6, 'b'),       // 6 chung → cùng cụm
            'glucose máu cao' => $this->urls('s', 8, 'c'),        // 8 chung → cùng cụm, nên gộp một bài
            'mua máy đo đường huyết' => $this->urls('m', 10, 'd'), // chữ giống nhưng SERP khác hẳn
            'máy đo đường huyết giá' => $this->urls('m', 5, 'e'),
            'lẻ loi' => $this->urls('z', 10, 'f'),
        ];
        $r = (new SerpClusterer)->cluster(array_keys($serp), $this->insights($serp), null);

        $groups = collect($r['groups'])->keyBy('name');
        $this->assertEqualsCanonicalizing(['đường huyết cao', 'tăng đường huyết', 'glucose máu cao'], $groups['đường huyết cao']['keywords']);
        $this->assertSame(['glucose máu cao'], array_column($groups['đường huyết cao']['same_post'], 'keyword'));
        $this->assertSame(8, $groups['đường huyết cao']['same_post'][0]['shared']);
        $this->assertEqualsCanonicalizing(['mua máy đo đường huyết', 'máy đo đường huyết giá'], $groups['mua máy đo đường huyết']['keywords']);
        $this->assertSame(['lẻ loi'], $groups['Từ khóa lẻ']['keywords'], 'không cùng cụm với ai thì vào cụm lẻ');
        $this->assertSame(6000.0, $r['cost_vnd']);
        $this->assertSame(['dataforseo'], $r['providers']);
        $ev = collect($groups['đường huyết cao']['evidence'])->firstWhere('b', 'tăng đường huyết');
        $this->assertSame(6, $ev['shared']);
        $this->assertCount(5, $ev['urls'], 'bằng chứng là URL thật trùng nhau, tối đa 5');
    }

    public function test_chi_nhan_tu_khoa_noi_thang_voi_tru_khong_day_chuyen(): void
    {
        Cache::flush();
        $map = fn (array $l) => array_map(fn ($x) => "https://{$x}.vn/p", $l);
        $a = ['a1', 'a2', 'a3', 'a4', 'a5', 'a6', 'a7', 'a8', 'a9', 'a10'];
        $serp = [
            'A' => $map($a),
            'B' => $map(['a1', 'a2', 'a3', 'a4', 'b1', 'b2', 'b3', 'b4', 'b5', 'b6']),   // chung với A: 4
            'D' => $map(['a5', 'a6', 'a7', 'a8', 'd1', 'd2', 'd3', 'd4', 'd5', 'd6']),   // chung với A: 4
            'E' => $map(['a9', 'a10', 'a1', 'a2', 'e1', 'e2', 'e3', 'e4', 'e5', 'e6']),  // chung với A: 4
            'C' => $map(['b1', 'b2', 'b3', 'b4', 'c1', 'c2', 'c3', 'c4', 'c5', 'c6']),   // chung với B: 4, với A: 0
        ];
        $r = (new SerpClusterer)->cluster(['A', 'B', 'C', 'D', 'E'], $this->insights($serp), null);

        $g = collect($r['groups'])->keyBy('name');
        $this->assertEqualsCanonicalizing(['A', 'B', 'D', 'E'], $g['A']['keywords'], 'A là trụ nhiều liên kết nhất');
        $this->assertNotContains('C', $g['A']['keywords'], 'C chỉ nối với B (không nối thẳng với trụ) nên không bị kéo vào cụm');
        $this->assertSame(['C'], $g['Từ khóa lẻ']['keywords']);
    }

    public function test_cum_khac_nhau_co_2_3_url_chung_thi_de_xuat_link_cheo(): void
    {
        Cache::flush();
        $k1 = $this->urls('p', 10, 'a');
        $serp = [
            'k1' => $k1,
            'k2' => $this->urls('p', 8, 'b'),                                                       // cùng cụm với k1
            'k3' => array_merge(array_slice($k1, 8, 2), $this->urls('t', 0, 't3'), []),            // 2 URL chung với k1
            'k4' => $this->urls('u', 10, 'u'),
        ];
        $serp['k3'] = array_slice(array_merge(array_slice($k1, 8, 2), $this->urls('t', 0, 't3')), 0, 10);
        $r = (new SerpClusterer)->cluster(['k1', 'k2', 'k3', 'k4'], $this->insights($serp), null);

        $this->assertNotEmpty($r['interlinks']);
        $l = $r['interlinks'][0];
        $this->assertSame(2, $l['shared']);
        $this->assertEqualsCanonicalizing(['k1', 'k3'], [$l['from'], $l['to']]);
    }

    public function test_tu_khoa_khong_co_serp_hoac_het_tran_chi_phi_vao_chua_xu_ly(): void
    {
        Cache::flush();
        $serp = ['a' => $this->urls('s', 10, 'a'), 'b' => $this->urls('s', 10, 'b'), 'khong-serp' => [], 'd' => $this->urls('s', 10, 'd')];
        $r = (new SerpClusterer)->cluster(['a', 'khong-serp', 'b', 'd'], $this->insights($serp, 1000.0), 2000.0);

        $unp = collect($r['unprocessed'])->keyBy('keyword');
        $this->assertStringContainsString('không trả kết quả tự nhiên', $unp['khong-serp']['reason']);
        $this->assertStringContainsString('hết trần chi phí', $unp['d']['reason']);
        $this->assertNotNull($r['stopped']);
        $all = array_merge(...array_column($r['groups'], 'keywords'));
        $this->assertNotContains('khong-serp', $all, 'không xếp bừa');
        $this->assertNotContains('d', $all);
    }

    public function test_khong_co_nguon_serp_thi_bi_chan(): void
    {
        $this->expectException(SerpUnavailable::class);
        (new SerpClusterer)->cluster(['a'], new SerpInsights([]), null);
    }

    public function test_chuan_hoa_url(): void
    {
        $this->assertSame('a.vn/bai', SerpClusterer::normalizeUrl('HTTPS://www.A.vn/Bai/?utm=1#x'));
        $this->assertSame('', SerpClusterer::normalizeUrl('javascript:alert(1)'));
    }

    public function test_service_luu_de_xuat_nguon_serp_kem_bang_chung_va_man_hinh_gan_nhan(): void
    {
        Cache::flush();
        $w = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://s.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        foreach (['đường huyết cao' => 500, 'tăng đường huyết' => 100, 'khác hẳn' => null] as $k => $v) {
            Keyword::create(['website_id' => $w->id, 'keyword' => $k, 'keyword_key' => Keyword::keyFor($k), 'volume' => $v, 'source' => 'tay', 'status' => 'new']);
        }
        $serp = ['đường huyết cao' => $this->urls('s', 10, 'a'), 'tăng đường huyết' => $this->urls('s', 7, 'b'), 'khác hẳn' => $this->urls('z', 10, 'c')];
        $repo = new EloquentKeywordRepository($w->id);
        $svc = new KeywordService($repo);
        $r = $svc->proposeWithSerp($this->insights($serp), 10000.0);

        $this->assertSame(2, $r['proposals']);
        $this->assertSame('serp', $svc->proposalSource());
        $p = collect($repo->proposals())->firstWhere('name', 'đường huyết cao');
        $this->assertSame(['đường huyết cao', 'tăng đường huyết'], $p['keywords'], 'từ khóa nhiều lượt tìm làm trụ');
        $this->assertSame('tăng đường huyết', $p['serp']['same_post'][0]['keyword']);

        $svc->accept($p['id']);
        $this->assertSame('đường huyết cao', Keyword::where('keyword_key', 'tăng đường huyết')->first()->cluster->name);
    }

    public function test_man_hinh_bao_bi_chan_khi_thieu_nguon_serp_khong_gom_gia(): void
    {
        Cache::flush();
        config(['keywords.dataforseo.login' => '', 'keywords.dataforseo.password' => '', 'keywords.serp.autocomplete' => false]);
        $w = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://s.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        Keyword::create(['website_id' => $w->id, 'keyword' => 'x', 'keyword_key' => 'x', 'source' => 'tay', 'status' => 'new']);
        $this->actingAs(User::create(['name' => 'a', 'email' => 'a@x.vn', 'password' => 'secret123', 'role' => 'admin', 'is_active' => true]));

        Livewire::test(KeywordsPage::class)->call('proposeBySerp')->assertNotified();
        $this->assertSame([], (new EloquentKeywordRepository($w->id))->proposals());
    }
}
