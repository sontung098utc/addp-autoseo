<?php

namespace Tests\Feature\Keywords;

use App\Contracts\SerpInsightsProvider;
use App\Models\KnowledgeItem;
use App\Models\Website;
use App\Services\Keywords\Serp\SerpInsights;
use App\Services\Keywords\Trends\GoogleTrendsHot;
use App\Services\Keywords\Trends\SuggestionHistory;
use App\Services\Keywords\Trends\TrendSignals;
use App\Services\Keywords\Trends\WikipediaPageviews;
use App\Services\Planning\CampaignProposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Xu hướng MIỄN PHÍ (Wikipedia, RSS Google Trends VN, lịch sử gợi ý) và cờ kho có nguồn. HTTP giả lập; không có số bịa. */
class TrendSignalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('local');
        config(['keywords.trends.enabled' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @var array{title:string,views:list<int>,status:int} */
    private array $wiki = ['title' => '', 'views' => [], 'status' => 200];

    /** @param list<int> $views 28 ngày lượt xem. Một Http::fake duy nhất đọc dữ liệu hiện tại, nên đổi được giữa các lần gọi trong một test. */
    private function fakeWiki(string $title, array $views, int $status = 200): void
    {
        $this->wiki = ['title' => $title, 'views' => $views, 'status' => $status];
        if (! $this->wikiFaked) {
            $this->wikiFaked = true;
            Http::fake([
                'vi.wikipedia.org/*' => fn () => Http::response($this->wiki['status'] === 200 ? ['x', [$this->wiki['title']], [''], ['']] : '', $this->wiki['status']),
                'wikimedia.org/*' => fn () => Http::response(['items' => $this->wikiItems()], $this->wiki['status']),
                'trends.google.com/*' => fn () => Http::response($this->rss, $this->rssStatus),
            ]);
        }
    }

    /** @return list<array{timestamp:string,views:int}> 28 ngày kết thúc 2 ngày trước; ngày có 0 lượt xem bị bỏ như API thật */
    private function wikiItems(): array
    {
        $end = now()->subDays(2);
        $n = count($this->wiki['views']);
        $out = [];
        foreach ($this->wiki['views'] as $i => $v) {
            if ($v > 0) {
                $out[] = ['timestamp' => $end->copy()->subDays($n - 1 - $i)->format('Ymd').'00', 'views' => $v];
            }
        }

        return $out;
    }

    private bool $wikiFaked = false;

    private string $rss = '<rss><channel></channel></rss>';

    private int $rssStatus = 200;

    public function test_wikipedia_tang_giam_on_dinh_theo_14_ngay(): void
    {
        $this->fakeWiki('Đái tháo đường', array_merge(array_fill(0, 14, 10), array_fill(0, 14, 20)));
        $r = (new WikipediaPageviews)->signal('đái tháo đường');
        $this->assertSame('up', $r['direction']);
        $this->assertSame(100, $r['change_pct']);
        $this->assertSame([140, 280], [$r['previous'], $r['recent']]);

        Cache::flush();
        $this->fakeWiki('Đái tháo đường', array_merge(array_fill(0, 14, 20), array_fill(0, 14, 10)));
        $this->assertSame('down', (new WikipediaPageviews)->signal('đái tháo đường')['direction']);

        Cache::flush();
        $this->fakeWiki('Đái tháo đường', array_fill(0, 28, 10));
        $this->assertSame('flat', (new WikipediaPageviews)->signal('đái tháo đường')['direction']);
    }

    public function test_wikipedia_khong_du_du_lieu_thi_unknown_kem_ly_do_khong_doan(): void
    {
        $this->fakeWiki('Đái tháo đường', array_merge(array_fill(0, 14, 1), array_fill(0, 14, 3)));
        $r = (new WikipediaPageviews)->signal('đái tháo đường');
        $this->assertSame('unknown', $r['direction']);
        $this->assertNull($r['change_pct']);
        $this->assertStringContainsString('quá thấp', $r['reason']);

        Cache::flush();
        $this->fakeWiki('Bóng đá Việt Nam', array_fill(0, 28, 500));
        $r = (new WikipediaPageviews)->signal('đái tháo đường');
        $this->assertSame('unknown', $r['direction']);
        $this->assertStringContainsString('Không có bài Wikipedia khớp', $r['reason'], 'không gán nhầm bài khác');

        Cache::flush();
        $this->fakeWiki('', [], 500);
        $this->assertSame('unknown', (new WikipediaPageviews)->signal('đái tháo đường')['direction'], 'lỗi mạng không làm hỏng, không ném lỗi');
    }

    public function test_wikipedia_cache_khong_goi_lai_trong_ngay(): void
    {
        $this->fakeWiki('Đái tháo đường', array_fill(0, 28, 10));
        $w = new WikipediaPageviews;
        $w->signal('đái tháo đường');
        $w->signal('Đái  tháo đường');
        Http::assertSentCount(2); // một lần tìm tiêu đề + một lần lượt xem
    }

    public function test_rss_google_trends_chi_khop_khi_trung_that(): void
    {
        $xml = '<?xml version="1.0"?><rss xmlns:ht="x"><channel><item><title>tiểu đường thai kỳ</title></item><item><title>lịch thi đấu bóng đá việt nam</title></item></channel></rss>';
        $this->rss = $xml;
        $this->fakeWiki('x', []);
        $h = new GoogleTrendsHot;
        $this->assertSame('tiểu đường thai kỳ', $h->matches('tiểu đường thai kỳ'));
        $this->assertNull($h->matches('đường huyết'), 'chỉ chung một từ lẻ thì không khớp');
        $this->assertNull($h->matches('huyết áp cao'));
        $this->rssStatus = 500;
        Cache::flush();
        $this->assertSame([], (new GoogleTrendsHot)->titles(), 'lỗi thì rỗng, không ném lỗi');
    }

    public function test_lich_su_goi_y_chua_du_ngay_thi_noi_chua_san_sang(): void
    {
        $h = new SuggestionHistory;
        $h->record('đường huyết', ['đường huyết cao', 'đường huyết thấp']);
        $m = $h->movers('đường huyết', ['đường huyết cao']);
        $this->assertFalse($m['ready']);
        $this->assertSame([], $m['movers']);
    }

    public function test_lich_su_goi_y_thay_moi_leo_hang_tut_hang(): void
    {
        $h = new SuggestionHistory;
        Carbon::setTestNow('2026-11-01 08:00:00');
        $h->record('đường huyết', ['a', 'b', 'c', 'd', 'e', 'f']);
        Carbon::setTestNow('2026-11-09 08:00:00');
        $m = $h->movers('đường huyết', ['f', 'a', 'x', 'e', 'd', 'c']);

        $this->assertTrue($m['ready']);
        $this->assertSame('2026-11-01', $m['since']);
        $this->assertSame('up', $m['movers']['f']['status']);     // hạng 5 → 0
        $this->assertSame(5, $m['movers']['f']['delta']);
        $this->assertSame('new', $m['movers']['x']['status']);
        $this->assertSame('down', $m['movers']['c']['status']);   // hạng 2 → 5
        $this->assertSame('same', $m['movers']['a']['status']);
    }

    public function test_gop_nguon_huong_chung_va_bang_chung_co_nhan_nguon(): void
    {
        $this->fakeWiki('Đái tháo đường', array_merge(array_fill(0, 14, 10), array_fill(0, 14, 20)));
        $hist = ['ready' => true, 'since' => '2026-11-01', 'days' => 3, 'movers' => ['đái tháo đường' => ['status' => 'down', 'delta' => -4]]];
        $t = (new TrendSignals)->forKeyword('đái tháo đường', $hist);
        $this->assertSame('flat', $t['direction'], 'một nguồn tăng một nguồn giảm thì không kết luận');
        $this->assertEqualsCanonicalizing(['wikipedia', 'lich_su_goi_y'], array_column($t['evidence'], 'source'));

        $none = (new TrendSignals)->forKeyword('đái tháo đường', null, false);
        $this->assertSame('unknown', $none['direction']);
        $this->assertSame([], $none['evidence']);
    }

    private function serpWith(array $auto): SerpInsights
    {
        return new SerpInsights([new class($auto) implements SerpInsightsProvider
        {
            public function __construct(private array $auto) {}

            public function name(): string
            {
                return 'autocomplete';
            }

            public function insights(string $keyword, array $options = []): array
            {
                return ['paa' => [], 'related' => [], 'autocomplete' => $this->auto, 'organic' => [], 'provider' => 'autocomplete', 'cost_vnd' => 0.0, 'fetched_at' => now()->toIso8601String()];
            }
        }]);
    }

    public function test_de_xuat_chat_luong_truoc_xu_huong_sau_va_co_kho_chua_co_nguon(): void
    {
        $site = Website::create(['name' => 'A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $title = 'Đường huyết cao và cách xử trí';
        $body = 'Đường huyết cao cần theo dõi và xử trí đúng cách.';
        KnowledgeItem::create(['website_id' => $site->id, 'type' => 'fact', 'title' => $title, 'body' => $body, 'status' => 'approved', 'version' => 1, 'created_by' => 'rule', 'content_hash' => \App\Services\Knowledge\KnowledgeDedupe::hash($title, $body)]);
        $this->fakeWiki('Đường huyết cao', array_merge(array_fill(0, 14, 10), array_fill(0, 14, 20)));

        $p = (new CampaignProposer)->propose($site, 'đường huyết', null, $this->serpWith(['đường huyết cao', 'đường huyết thấp']));
        $sat = collect($p['satellites'])->keyBy('keyword');

        $this->assertGreaterThan(0, $sat['đường huyết cao']['kho_matches'], 'kho có mục khớp');
        $this->assertSame(0, $sat['đường huyết thấp']['kho_matches'], 'kho chưa có nguồn cho cụm này');
        $this->assertSame('up', $sat['đường huyết cao']['trend']['direction']);
        $this->assertSame(8, $sat['đường huyết cao']['score_parts']['trend'], 'có bằng chứng chất lượng (kho) nên được cộng điểm xu hướng');
        $this->assertSame(6, $sat['đường huyết cao']['score_parts']['kho_nguon'], 'một mục khớp = +6; từ hai mục mới +12');
        $this->assertStringContainsString('↑ đang tăng', $sat['đường huyết cao']['headline']);
        $this->assertStringContainsString('CHƯA có nguồn khớp', $sat['đường huyết thấp']['why']);
        $this->assertGreaterThan($sat['đường huyết thấp']['score'], $sat['đường huyết cao']['score']);
        $this->assertStringContainsString('Xu hướng từ lịch sử gợi ý', implode(' ', $p['notes']));
    }

    public function test_xu_huong_khong_cong_diem_khi_chua_co_bang_chung_chat_luong(): void
    {
        $site = Website::create(['name' => 'A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $xml = '<rss><channel><item><title>đường huyết thấp</title></item></channel></rss>';
        Http::fake(['vi.wikipedia.org/*' => Http::response(['x', [], [], []]), 'trends.google.com/*' => Http::response($xml)]);

        $p = (new CampaignProposer)->propose($site, 'đường huyết', null, $this->serpWith(['đường huyết thấp']));
        $s = $p['satellites'][0];
        $this->assertSame('up', $s['trend']['direction'], 'có hiện trên Google Trends hôm nay');
        $this->assertSame(0, $s['score_parts']['trend'], 'nhưng chưa có bằng chứng chất lượng nên không được cộng điểm');
        $this->assertStringContainsString('chưa cộng điểm', $s['why']);
        $this->assertStringContainsString('Kho kiến thức của website đang trống', implode(' ', $p['notes']));
        $this->assertNull($s['kho_matches'], 'kho trống thì không gắn cờ từng bài');
    }

    public function test_ngay_khong_co_luot_xem_bi_bo_van_cong_dung_theo_ngay(): void
    {
        $views = array_merge(array_fill(0, 14, 10), array_fill(0, 7, 0), array_fill(0, 7, 40)); // 7 ngày 0 lượt xem bị API bỏ khỏi dữ liệu
        $this->fakeWiki('Đái tháo đường', $views);
        $r = (new WikipediaPageviews)->signal('đái tháo đường');
        $this->assertSame([140, 280], [$r['previous'], $r['recent']]);
        $this->assertSame('up', $r['direction']);
    }

    public function test_tu_dong_nghia_y_khoa_tim_dung_bai_va_429_khong_luu_ca_ngay(): void
    {
        $this->assertSame('đái tháo đường thai kỳ', WikipediaPageviews::withSynonym('tiểu đường thai kỳ'));
        $this->assertSame('cách ăn uống', WikipediaPageviews::withSynonym('cách ăn uống'));
        $this->fakeWiki('Đái tháo đường', array_merge(array_fill(0, 14, 10), array_fill(0, 14, 20)));
        $this->assertSame('up', (new WikipediaPageviews)->signal('tiểu đường')['direction'], '"tiểu đường" tìm ra bài "Đái tháo đường"');

        Cache::flush();
        $this->fakeWiki('', [], 429);
        $r = (new WikipediaPageviews)->signal('tiểu đường');
        $this->assertSame('unknown', $r['direction']);
        $this->assertStringContainsString('429', $r['reason']);
        $this->assertTrue((bool) Cache::get('trend:wiki:'.md5(\App\Models\Keyword::keyFor('tiểu đường')).':'.now()->toDateString())['rate_limited']);
    }

    public function test_lenh_ghi_lich_su_goi_y_theo_chu_de_va_theo_chien_dich(): void
    {
        Http::fake(['suggestqueries.google.com/*' => Http::response(json_encode(['x', ['tiểu đường thai kỳ', 'tiểu đường tuýp 1']]), 200)]);
        $this->artisan('trends:snapshot', ['seed' => ['tiểu đường'], '--pause' => 0])->expectsOutputToContain('ghi 2 gợi ý')->assertSuccessful();
        $this->assertSame(['tiểu đường thai kỳ', 'tiểu đường tuýp 1'], (new SuggestionHistory)->load('tiểu đường')[now()->toDateString()]);

        $site = Website::create(['name' => 'A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        \App\Models\Campaign::create(['website_id' => $site->id, 'name' => 'CD', 'topic_cluster' => 'huyết áp']);
        $this->artisan('trends:snapshot', ['--pause' => 0])->expectsOutputToContain('«huyết áp»')->assertSuccessful();
        $this->assertNotEmpty((new SuggestionHistory)->load('huyết áp'));
    }

    public function test_lenh_ghi_lich_su_bao_loi_khi_nguon_loi_va_khong_co_chu_de(): void
    {
        Http::fake(['suggestqueries.google.com/*' => Http::response('', 503)]);
        $this->artisan('trends:snapshot', ['seed' => ['x'], '--pause' => 0])->assertFailed();
        $this->artisan('trends:snapshot', ['--pause' => 0])->expectsOutputToContain('Không có chủ đề')->assertSuccessful();
    }
}

