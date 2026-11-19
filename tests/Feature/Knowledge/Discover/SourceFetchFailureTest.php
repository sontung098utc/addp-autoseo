<?php

namespace Tests\Feature\Knowledge\Discover;

use App\Contracts\StructuredAiInterface;
use App\Models\AgentRun;
use App\Models\User;
use App\Models\Website;
use App\Services\Knowledge\Build\SafePageFetcher;
use App\Services\Knowledge\Discover\BadSourceMemory;
use App\Services\Knowledge\Discover\SourceDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Knowledge\Build\FakeSafety;
use Tests\TestCase;

/** Một nguồn không tải được (DNS, 5xx, hết giờ) chỉ bị BỎ: phiên chạy tiếp các nguồn còn lại. Http GIẢ, AI GIẢ; chưa chạy trên server thật. */
class SourceFetchFailureTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['knowledge.retry_delay_ms' => 0]);
        $this->site = Website::create(['name' => 'ADDP', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->user = User::create(['name' => 'u', 'email' => 'u@x.vn', 'password' => 'secret123', 'role' => 'approver', 'is_active' => true]);
    }

    private function goodPage(): string
    {
        return '<html><head><title>Tiểu đường</title></head><body><main><h1>Tiểu đường</h1><p>Nên ăn nhiều rau xanh và hạn chế đồ ngọt mỗi ngày.</p><p>'.str_repeat('Thông tin tham khảo dành cho cộng đồng. ', 6).'</p></main></body></html>';
    }

    private function ai(): object
    {
        return new class implements StructuredAiInterface
        {
            public function generateStructured(string $system, string $user): array
            {
                return ['data' => ['items' => [['title' => 'Chế độ ăn', 'body' => 'Nên ăn nhiều rau xanh và hạn chế đồ ngọt.', 'type' => 'fact', 'topic' => 'Chung', 'tags' => [], 'confidence' => 90, 'source_span' => 'Nên ăn nhiều rau xanh và hạn chế đồ ngọt mỗi ngày']]], 'tokens_used' => 100, 'cost_vnd' => 10.0, 'model' => 'fake'];
            }
        };
    }

    private function imp(array $urls): array
    {
        $svc = new SourceDiscoveryService(new SafePageFetcher(new FakeSafety, 0), null, null, null, $this->ai(), null, 'AI giả');

        return $svc->import($this->site, array_map(fn ($u) => ['url' => $u], $urls), $this->user, ['topic' => 'tiểu đường']);
    }

    public function test_dns_loi_khong_thu_lai_va_cac_nguon_con_lai_van_chay(): void
    {
        $dnsHits = 0;
        Http::fake(function ($req) use (&$dnsHits) {
            if (str_contains($req->url(), 'vimda.moh.gov.vn')) {
                $dnsHits++;
                throw new ConnectionException('cURL error 6: Could not resolve host: vimda.moh.gov.vn');
            }

            return Http::response($this->goodPage(), 200, ['Content-Type' => 'text/html']);
        });
        $r = $this->imp(['https://vimda.moh.gov.vn/a', 'https://vimda.moh.gov.vn/b', 'https://who.int/tt']);

        $this->assertSame(1, $dnsHits, 'robots.txt báo DNS lỗi thì không gọi lại, không thử lại trang');
        $run = AgentRun::find($r['run_id']);
        $this->assertSame('ready_for_review', $run->status);
        $this->assertStringContainsString('Xong, bỏ 2 nguồn không tải được', (string) $run->outcome_reason);
        $this->assertStringContainsString('vimda.moh.gov.vn', (string) $run->outcome_reason);
        $this->assertStringContainsString('kiểm tra địa chỉ, hoặc bỏ nguồn này', (string) $run->outcome_reason);
        $this->assertSame(1, $r['imported']);
        $failed = $run->steps->where('stage', 'source')->where('status', 'failed');
        $this->assertCount(2, $failed);
        $this->assertStringContainsString('DNS', $failed->first()->detail);
        $this->assertSame(1, $run->steps->where('stage', 'source_summary')->count());
        $this->assertTrue(BadSourceMemory::isBad('vimda.moh.gov.vn'));
        $this->assertFalse(BadSourceMemory::isBad('who.int'));
    }

    public function test_loi_500_thu_lai_dung_mot_lan_roi_bo_nguon(): void
    {
        $hits = 0;
        Http::fake(function ($req) use (&$hits) {
            if (str_contains($req->url(), 'robots.txt')) {
                return Http::response('', 404);
            }
            if (str_contains($req->url(), 'hong.vn')) {
                $hits++;

                return Http::response('lỗi', 500);
            }

            return Http::response($this->goodPage(), 200, ['Content-Type' => 'text/html']);
        });
        $r = $this->imp(['https://hong.vn/a', 'https://who.int/tt']);

        $this->assertSame(2, $hits, '1 lần đầu + đúng 1 lần thử lại');
        $run = AgentRun::find($r['run_id']);
        $this->assertSame('ready_for_review', $run->status);
        $this->assertStringContainsString('Xong, bỏ 1 nguồn', (string) $run->outcome_reason);
        $this->assertTrue(BadSourceMemory::isBad('hong.vn'));
    }

    public function test_loi_tam_thoi_thu_lai_duoc_thi_nguon_van_dung(): void
    {
        $hits = 0;
        Http::fake(function ($req) use (&$hits) {
            if (str_contains($req->url(), 'robots.txt')) {
                return Http::response('', 404);
            }
            if ($hits++ === 0) {
                throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds');
            }

            return Http::response($this->goodPage(), 200, ['Content-Type' => 'text/html']);
        });
        $r = $this->imp(['https://who.int/tt']);

        $this->assertSame(1, $r['imported']);
        $this->assertSame('ready_for_review', AgentRun::find($r['run_id'])->status);
        $this->assertFalse(BadSourceMemory::isBad('who.int'));
    }

    public function test_het_gio_thu_lai_mot_lan_va_bo_nguon(): void
    {
        $hits = 0;
        Http::fake(function ($req) use (&$hits) {
            if (str_contains($req->url(), 'robots.txt')) {
                return Http::response('', 404);
            }
            if (str_contains($req->url(), 'cham.vn')) {
                $hits++;
                throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds');
            }

            return Http::response($this->goodPage(), 200, ['Content-Type' => 'text/html']);
        });
        $r = $this->imp(['https://cham.vn/a', 'https://who.int/tt']);

        $this->assertSame(2, $hits);
        $run = AgentRun::find($r['run_id']);
        $this->assertSame('ready_for_review', $run->status);
        $this->assertStringContainsString('hết giờ chờ', (string) $run->outcome_reason);
    }

    public function test_404_khong_thu_lai_va_khong_ghi_nho_ca_ten_mien(): void
    {
        $hits = 0;
        Http::fake(function ($req) use (&$hits) {
            if (str_contains($req->url(), 'robots.txt')) {
                return Http::response('', 404);
            }
            $hits++;

            return Http::response('không có', 404);
        });
        $r = $this->imp(['https://who.int/khong-co']);

        $this->assertSame(1, $hits);
        $this->assertFalse(BadSourceMemory::isBad('who.int'));
        $this->assertSame('failed', AgentRun::find($r['run_id'])->status);
    }

    public function test_tat_ca_nguon_loi_thi_phien_moi_la_loi(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 6: Could not resolve host: x');
        });
        $r = $this->imp(['https://a-hong.vn/a', 'https://b-hong.vn/b']);

        $run = AgentRun::find($r['run_id']);
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('Không tải được nguồn nào', (string) $run->outcome_reason);
        $this->assertSame(0, $r['imported']);
    }

    public function test_goi_y_lan_sau_bo_ten_mien_da_hong_trong_24_gio(): void
    {
        BadSourceMemory::mark('vimda.moh.gov.vn', 'dns', 'x');
        $this->assertContains('vimda.moh.gov.vn', BadSourceMemory::hosts());
        $this->travel(25)->hours();
        $this->assertFalse(BadSourceMemory::isBad('vimda.moh.gov.vn'));
        $this->assertSame([], BadSourceMemory::hosts());
    }

    public function test_van_chan_ip_noi_bo_khong_tai(): void
    {
        Http::fake();
        $p = (new SafePageFetcher(null, 0))->fetchExternal('http://169.254.169.254/latest');
        $this->assertFalse($p['ok']);
        $this->assertSame('blocked', $p['error']);
        Http::assertNothingSent();
    }
}
