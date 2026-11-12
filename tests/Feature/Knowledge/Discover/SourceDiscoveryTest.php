<?php

namespace Tests\Feature\Knowledge\Discover;

use App\Contracts\StructuredAiInterface;
use App\Models\AgentRun;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeSource;
use App\Models\User;
use App\Models\Website;
use App\Services\Crawler\SitemapCrawlerService;
use App\Services\Knowledge\Build\BrainBuildService;
use App\Services\Knowledge\Build\SafePageFetcher;
use App\Services\Knowledge\Discover\SourceDiscoveryService;
use App\Services\Knowledge\KnowledgeError;
use App\Services\Search\WebSearchUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Knowledge\Build\FakeSafety;
use Tests\TestCase;

/** Khám phá và nạp nguồn ngoài. Tavily GIẢ, trang ngoài GIẢ (Http::fake), AI GIẢ: không có mạng thật. */
class SourceDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['search.tavily.key' => 'tvly-TEST', 'search.cost_per_search_vnd' => 200.0]);
        $this->site = Website::create(['name' => 'Ánh Dương', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function user(string $role = 'approver'): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function page(string $h1, string $para): string
    {
        return '<html><head><meta charset="utf-8"><title>'.$h1.'</title></head><body><main><h1>'.$h1.'</h1><p>'.$para.'</p><p>'.str_repeat('Thông tin tham khảo dành cho cộng đồng. ', 6).'</p></main></body></html>';
    }

    private function ai(array $responses): object
    {
        return new class($responses) implements StructuredAiInterface
        {
            public function __construct(private array $responses) {}

            public function generateStructured(string $system, string $user): array
            {
                return ['data' => array_shift($this->responses) ?? ['items' => []], 'tokens_used' => 100, 'cost_vnd' => 10.0, 'model' => 'fake'];
            }
        };
    }

    private function item(string $type, string $title, string $body, string $span): array
    {
        return ['title' => $title, 'body' => $body, 'type' => $type, 'topic' => 'Chung', 'tags' => [], 'confidence' => 90, 'source_span' => $span];
    }

    private function svc(?object $ai = null): SourceDiscoveryService
    {
        return new SourceDiscoveryService(new SafePageFetcher(new FakeSafety, 0), null, null, null, $ai, null, 'AI giả');
    }

    private function tavily(array $rows): void
    {
        Http::fake(['api.tavily.com/search' => Http::response(['results' => $rows])]);
    }

    public function test_discover_xep_tin_cay_truoc_loai_trung_va_chan_va_tran(): void
    {
        $rows = [
            ['title' => 'Blog', 'url' => 'https://blog.vn/a', 'content' => 'b', 'score' => 0.99],
            ['title' => 'FB', 'url' => 'https://facebook.com/p', 'content' => 'f', 'score' => 0.95],
            ['title' => 'WHO', 'url' => 'https://www.who.int/diabetes', 'content' => 'w', 'score' => 0.5],
            ['title' => 'WHO trùng', 'url' => 'https://who.int/diabetes/', 'content' => 'w2', 'score' => 0.6],
            ['title' => 'CDC', 'url' => 'https://cdc.gov/d', 'content' => 'c', 'score' => 0.7],
            ['title' => 'Vncdc', 'url' => 'https://vncdc.gov.vn/d', 'content' => 'v', 'score' => 0.4],
        ];
        for ($i = 0; $i < 6; $i++) {
            $rows[] = ['title' => 'Khác '.$i, 'url' => 'https://khac'.$i.'.vn/x', 'content' => 'k', 'score' => 0.1];
        }
        $this->tavily($rows);
        $d = $this->svc()->discover($this->site, 'người bị tiểu đường nên ăn gì');

        $this->assertSame('health', $d['field']);
        $this->assertStringContainsString('hướng dẫn khuyến cáo', $d['query']);
        $this->assertCount(8, $d['candidates']);
        $levels = array_column(array_column($d['candidates'], 'trust'), 'level');
        $this->assertSame(['trusted', 'trusted', 'trusted'], array_slice($levels, 0, 3));
        $this->assertNotContains('blocked', $levels);
        $urls = array_column($d['candidates'], 'url');
        $this->assertNotContains('https://facebook.com/p', $urls);
        $this->assertCount(1, array_filter($urls, fn ($u) => str_contains($u, 'who.int')), 'trùng thì giữ một');
        $this->assertSame('https://cdc.gov/d', $d['candidates'][0]['url'], 'cùng tin cậy thì điểm cao đi trước');
        $this->assertNotSame('', $d['candidates'][0]['trust']['reason']);
        // lĩnh vực sức khỏe: tìm lần đầu có include_domains; ít kết quả tin cậy thì mở rộng (hai lần, hai lần chi phí)
        Http::assertSent(fn ($r) => ! empty($r['include_domains']) && in_array('who.int', $r['include_domains'], true));
        $this->assertEqualsWithDelta(200.0 * count(Http::recorded()), $d['cost_vnd'], 0.01);
    }

    public function test_discover_chu_de_thuong_khong_include_domains_va_thieu_khoa_nem_loi(): void
    {
        $this->tavily([['title' => 'A', 'url' => 'https://a1.vn/x', 'content' => '', 'score' => 1]]);
        $d = $this->svc()->discover($this->site, 'học bổng Úc');
        $this->assertNull($d['field']);
        $this->assertCount(1, $d['candidates']);
        $this->assertCount(1, Http::recorded());
        Http::assertSent(fn ($r) => ! isset($r['include_domains']) && isset($r['exclude_domains']));

        config(['search.tavily.key' => '']);
        $this->expectException(WebSearchUnavailable::class);
        $this->svc()->discover($this->site, 'học bổng Úc');
    }

    public function test_fetch_external_chan_ip_noi_bo_chuyen_huong_noi_bo_qua_co_va_robots(): void
    {
        $f = new SafePageFetcher(new FakeSafety, 0);
        Http::fake([
            'ext.com/robots.txt' => Http::response("User-agent: *\nDisallow: /cam", 200),
            'ext.com/cam' => Http::response('x', 200, ['Content-Type' => 'text/html']),
            'ext.com/to' => Http::response('', 302, ['Location' => 'http://10.0.0.5/x']),
            'ext.com/big' => Http::response(str_repeat('a', SafePageFetcher::MAX_BYTES + 10), 200, ['Content-Type' => 'text/html']),
            'ext.com/ok' => Http::response('<html><body>ok</body></html>', 200, ['Content-Type' => 'text/html']),
            'ext.com/pdf' => Http::response('%PDF', 200, ['Content-Type' => 'application/pdf']),
            'other.org/*' => Http::response('<html><body>other</body></html>', 200, ['Content-Type' => 'text/html']),
            'ext.com/hop' => Http::response('', 301, ['Location' => 'https://other.org/page']),
        ]);
        $this->assertSame('blocked', $f->fetchExternal('http://127.0.0.1/x')['error']);
        $this->assertSame('blocked', $f->fetchExternal('http://169.254.169.254/latest')['error']);
        $this->assertSame('blocked', $f->fetchExternal('https://ext.com/to')['error'], 'chuyển hướng ra IP nội bộ');
        $this->assertSame('too_large', $f->fetchExternal('https://ext.com/big')['error']);
        $this->assertSame('robots', $f->fetchExternal('https://ext.com/cam')['error']);
        $this->assertSame('not_html', $f->fetchExternal('https://ext.com/pdf')['error']);
        $this->assertTrue($f->fetchExternal('https://ext.com/ok')['ok']);
        $hop = $f->fetchExternal('https://ext.com/hop');
        $this->assertTrue($hop['ok'], 'chuyển hướng sang tên miền ngoài khác vẫn qua kiểm an toàn');
        $this->assertSame('https://other.org/page', $hop['final_url']);
        // chế độ cũ vẫn chặn tên miền ngoài
        $this->assertSame('off_domain', $f->fetch('https://ext.com/ok', ['a.vn'])['error']);
        Http::assertNotSent(fn ($r) => str_contains((string) $r->url(), '10.0.0.5') || str_contains((string) $r->url(), '127.0.0.1'));
    }

    public function test_nguon_tin_cay_tu_nhan_ca_suc_khoe_neutral_chi_de_xuat_price_khong_tu_nhan_va_ghi_phien(): void
    {
        Http::fake([
            'who.int/robots.txt' => Http::response('', 404),
            'blog.vn/robots.txt' => Http::response('', 404),
            'who.int/an' => Http::response($this->page('Ăn uống cho người tiểu đường', 'Người tiểu đường nên ăn nhiều rau xanh và hạn chế đồ ngọt. Một bộ xét nghiệm giá 120.000 đồng.'), 200, ['Content-Type' => 'text/html']),
            'blog.vn/b' => Http::response($this->page('Blog', 'Người bệnh nên tập đi bộ mỗi ngày để kiểm soát đường huyết.'), 200, ['Content-Type' => 'text/html']),
        ]);
        $ai = $this->ai([
            ['items' => [
                $this->item('fact', 'Chế độ ăn', 'Nên ăn nhiều rau xanh và hạn chế đồ ngọt.', 'nên ăn nhiều rau xanh và hạn chế đồ ngọt'),
                $this->item('price', 'Giá xét nghiệm', 'Bộ xét nghiệm giá 120.000 đồng.', 'Một bộ xét nghiệm giá 120.000 đồng'),
                $this->item('fact', 'Bịa', 'Điều không có trong trang.', 'câu này không có trong trang'),
            ]],
            ['items' => [$this->item('fact', 'Tập đi bộ', 'Nên tập đi bộ mỗi ngày.', 'nên tập đi bộ mỗi ngày')]],
        ]);
        $by = $this->user();
        $r = $this->svc($ai)->import($this->site, [
            ['url' => 'https://www.who.int/an', 'title' => 'WHO'],
            ['url' => 'https://blog.vn/b'],
            ['url' => 'https://facebook.com/x'],
        ], $by, ['search' => ['query' => 'tiểu đường', 'results' => 3, 'cost_vnd' => 200.0, 'provider' => 'tavily']]);

        $this->assertSame(2, $r['imported']);
        $this->assertSame(1, $r['items_auto']);
        $this->assertSame(3, $r['items_proposed'], 'price + mục không đoạn nguyên văn (nguồn tin cậy) + mục nguồn chưa rõ');
        $fact = KnowledgeItem::where('title', 'Chế độ ăn')->first();
        $this->assertSame('approved', $fact->status);
        $this->assertSame('auto', $fact->review_mode);
        $this->assertNotNull($fact->risk_tier);
        $this->assertSame('proposed', KnowledgeItem::where('title', 'Giá xét nghiệm')->first()->status);
        $this->assertNull(KnowledgeItem::where('title', 'Giá xét nghiệm')->first()->review_mode);
        $fake = KnowledgeItem::where('title', 'Bịa')->first();
        $this->assertNull($fake->source_span, 'đoạn không có trong trang bị bỏ');
        $this->assertSame('proposed', $fake->status, 'không có đoạn nguyên văn thì không bao giờ tự nhận ngay cả nguồn tin cậy');
        $walk = KnowledgeItem::where('title', 'Tập đi bộ')->first();
        $this->assertSame('proposed', $walk->status);

        $src = KnowledgeSource::where('url', 'https://www.who.int/an')->first();
        $this->assertSame('search', $src->origin);
        $this->assertNotNull($src->fetched_at);
        $this->assertSame(200, $src->http_status);
        Http::assertNotSent(fn ($q) => str_contains((string) $q->url(), 'facebook.com'));

        $run = AgentRun::where('job_type', 'brain_build')->latest('id')->first();
        $this->assertSame('ready_for_review', $run->status);
        $this->assertSame('search', $run->steps->first()->stage);
        $this->assertSame(200.0, (float) $run->steps->first()->cost_vnd);
        $this->assertSame(2, $run->steps->where('stage', 'source')->where('status', 'completed')->count());
        $this->assertSame(1, $run->steps->where('stage', 'source')->where('status', 'skipped')->count());
        $this->assertEqualsWithDelta(220.0, $r['cost_vnd'], 0.01);
    }

    public function test_muc_tang_c_y_te_tu_nguon_tin_cay_van_cho_nguoi_duyet_con_muc_thuong_van_tu_nhan(): void
    {
        Http::fake([
            'who.int/robots.txt' => Http::response('', 404),
            'who.int/tt' => Http::response($this->page('Tài liệu', 'Người tiểu đường cần theo dõi đường huyết thường xuyên cùng bác sĩ. Thư viện mở cửa từ 8 giờ sáng mỗi ngày.'), 200, ['Content-Type' => 'text/html']),
        ]);
        $ai = $this->ai([['items' => [
            $this->item('fact', 'Theo dõi đường huyết', 'Người tiểu đường cần theo dõi đường huyết thường xuyên cùng bác sĩ.', 'Người tiểu đường cần theo dõi đường huyết thường xuyên cùng bác sĩ'),
            $this->item('fact', 'Giờ mở cửa thư viện', 'Thư viện mở cửa từ 8 giờ sáng mỗi ngày.', 'Thư viện mở cửa từ 8 giờ sáng mỗi ngày'),
        ]]]);
        $r = $this->svc($ai)->import($this->site, [['url' => 'https://www.who.int/tt']], $this->user());

        $health = KnowledgeItem::where('title', 'Theo dõi đường huyết')->first();
        $this->assertSame('C', $health->risk_tier);
        $this->assertSame('proposed', $health->status, 'tầng C luôn chờ người duyệt, kể cả nguồn tin cậy có đoạn nguyên văn');
        $this->assertNull($health->review_mode);
        $plain = KnowledgeItem::where('title', 'Giờ mở cửa thư viện')->first();
        $this->assertNotSame('C', $plain->risk_tier);
        $this->assertSame('approved', $plain->status);
        $this->assertSame('auto', $plain->review_mode);
        $this->assertSame(1, $r['items_auto']);
        $this->assertSame(1, $r['items_proposed']);
    }

    public function test_import_tin_cay_lai_tu_luat_khong_tin_du_lieu_gui_len_va_trung_bam_bo_qua(): void
    {
        $body = $this->page('Trang', 'Nội dung giống hệt để kiểm trùng băm.');
        Http::fake(['x.org/robots.txt' => Http::response('', 404), 'x.org/a' => Http::response($body, 200, ['Content-Type' => 'text/html'])]);
        $svc = $this->svc($this->ai([['items' => []]]));
        $first = $svc->import($this->site, [['url' => 'https://x.org/a', 'trust' => ['level' => 'trusted']]], $this->user());
        $this->assertSame(1, $first['imported']);
        $again = $this->svc($this->ai([]))->import($this->site, [['url' => 'https://x.org/a']], $this->user());
        $this->assertSame(0, $again['imported']);
        $this->assertSame('unchanged', $again['pages'][0]['error']);
    }

    public function test_writer_khong_duoc_nap_tran_chi_phi_va_khong_co_ai(): void
    {
        $this->expectException(KnowledgeError::class);
        try {
            $this->svc($this->ai([]))->import($this->site, [['url' => 'https://x.org/a']], $this->user('writer'));
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_tran_chi_phi_dung_giua_chung(): void
    {
        Http::fake(['*/robots.txt' => Http::response('', 404), '*' => Http::response($this->page('T', 'Nội dung đủ dài để rút kiến thức.'), 200, ['Content-Type' => 'text/html'])]);
        $r = $this->svc($this->ai([['items' => []], ['items' => []]]))->import($this->site, [['url' => 'https://p1.org/a'], ['url' => 'https://p2.org/b']], $this->user(), ['budget_cap_vnd' => 5.0, 'search' => ['query' => 'q', 'cost_vnd' => 6.0]]);
        $this->assertSame('budget', $r['stopped_reason']);
        $this->assertSame(0, $r['imported']);
    }

    public function test_luot_he_thong_tu_chay_khong_nguoi_dung_van_rut_duoc_muc(): void
    {
        Http::fake(['a.vn/robots.txt' => Http::response('', 404), 'a.vn/gioi-thieu' => Http::response($this->page('Giới thiệu', 'Công ty thành lập năm 2010 tại Hà Nội.'), 200, ['Content-Type' => 'text/html'])]);
        $sitemap = new class extends SitemapCrawlerService
        {
            public function __construct() {}

            public function discoverUrls(Website $website, ?string $customSitemapUrl = null, int $maxUrls = 500): Collection
            {
                return collect([['url' => 'https://a.vn/gioi-thieu']]);
            }
        };
        $ai = $this->ai([['items' => [$this->item('fact', 'Năm thành lập', 'Thành lập năm 2010.', 'thành lập năm 2010 tại Hà Nội')]]]);
        $svc = new BrainBuildService(new SafePageFetcher(new FakeSafety, 0), $sitemap, null, null, null, $ai, null, 'AI giả', false);
        $r = $svc->run($this->site, null, ['as_system' => true, 'max_pages' => 1]);
        $this->assertSame(1, $r['pages_read']);
        $this->assertSame(1, $r['items_auto'], 'trước đây lượt hệ thống bị KnowledgeAiIngest từ chối vì không có người dùng');
        $this->assertNull(KnowledgeItem::first()->approved_by);
    }
}
