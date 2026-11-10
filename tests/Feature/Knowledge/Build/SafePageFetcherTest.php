<?php

namespace Tests\Feature\Knowledge\Build;

use App\Services\Knowledge\Build\SafePageFetcher;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SafePageFetcherTest extends TestCase
{
    private function fetcher(): SafePageFetcher
    {
        return new SafePageFetcher(new FakeSafety, 0);
    }

    private function html(string $body = '<html><body>ok</body></html>', array $h = []): PromiseInterface
    {
        return Http::response($body, 200, $h + ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function test_tai_trang_thanh_cong(): void
    {
        Http::fake(['a.vn/robots.txt' => Http::response('', 404), 'a.vn/gioi-thieu' => $this->html()]);
        $r = $this->fetcher()->fetch('https://a.vn/gioi-thieu', ['a.vn']);
        $this->assertTrue($r['ok']);
        $this->assertSame('https://a.vn/gioi-thieu', $r['final_url']);
        $this->assertSame(200, $r['http_status']);
        $this->assertStringContainsString('ok', $r['html']);
        $this->assertNotEmpty($r['fetched_at']);
        Http::assertSent(fn ($req) => str_contains($req->header('User-Agent')[0] ?? '', 'ADDP-AutoSEO'));
    }

    public function test_tu_choi_localhost_va_ip_noi_bo(): void
    {
        Http::fake();
        foreach (['http://localhost/x', 'http://127.0.0.1/x', 'http://169.254.169.254/latest', 'http://10.0.0.5/'] as $u) {
            $host = parse_url($u, PHP_URL_HOST);
            $r = $this->fetcher()->fetch($u, [$host]);
            $this->assertFalse($r['ok'], $u);
            $this->assertSame('blocked', $r['error'], $u);
        }
        Http::assertNothingSent();
    }

    public function test_tu_choi_ten_mien_ngoai_va_giao_thuc_la(): void
    {
        Http::fake();
        $this->assertSame('off_domain', $this->fetcher()->fetch('https://evil.com/', ['a.vn'])['error']);
        $this->assertSame('off_domain', $this->fetcher()->fetch('https://a.vn.evil.com/', ['a.vn'])['error']);
        $this->assertSame('blocked', $this->fetcher()->fetch('file:///etc/passwd', ['a.vn'])['error']);
        Http::assertNothingSent();
    }

    public function test_cho_phep_ten_mien_con_va_www(): void
    {
        Http::fake(['*/robots.txt' => Http::response('', 404), '*' => $this->html()]);
        $this->assertTrue($this->fetcher()->fetch('https://www.a.vn/', ['a.vn'])['ok']);
        $this->assertTrue($this->fetcher()->fetch('https://blog.a.vn/', ['a.vn'])['ok']);
        $this->assertSame(['a.vn'], SafePageFetcher::allowedHostsFor('https://www.a.vn/x'));
    }

    public function test_chuyen_huong_cung_ten_mien_duoc_theo_va_ghi_final_url(): void
    {
        Http::fake([
            'a.vn/robots.txt' => Http::response('', 404),
            'a.vn/cu' => Http::response('', 301, ['Location' => '/moi']),
            'a.vn/moi' => $this->html(),
        ]);
        $r = $this->fetcher()->fetch('https://a.vn/cu', ['a.vn']);
        $this->assertTrue($r['ok']);
        $this->assertSame('https://a.vn/moi', $r['final_url']);
        $this->assertSame('https://a.vn/cu', $r['url']);
    }

    public function test_chuyen_huong_sang_ten_mien_khac_bi_chan(): void
    {
        Http::fake(['a.vn/robots.txt' => Http::response('', 404), 'a.vn/*' => Http::response('', 302, ['Location' => 'https://evil.com/x']), 'evil.com/*' => $this->html()]);
        $r = $this->fetcher()->fetch('https://a.vn/x', ['a.vn']);
        $this->assertSame('off_domain', $r['error']);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'evil.com'));
    }

    public function test_chuyen_huong_sang_ip_noi_bo_bi_chan(): void
    {
        Http::fake(['a.vn/robots.txt' => Http::response('', 404), 'a.vn/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data'])]);
        $this->assertSame('blocked', $this->fetcher()->fetch('https://a.vn/x', ['a.vn'])['error']);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '169.254'));
    }

    public function test_qua_ba_lan_chuyen_huong_thi_dung(): void
    {
        Http::fake(['a.vn/robots.txt' => Http::response('', 404), 'a.vn/*' => Http::response('', 302, ['Location' => '/loop'])]);
        $r = $this->fetcher()->fetch('https://a.vn/loop', ['a.vn']);
        $this->assertSame('http_error', $r['error']);
        // 1 lần robots + 4 lần tải (đầu + 3 lần chuyển hướng)
        Http::assertSentCount(5);
    }

    public function test_qua_2mb(): void
    {
        Http::fake(['a.vn/robots.txt' => Http::response('', 404), 'a.vn/*' => $this->html(str_repeat('x', 2 * 1024 * 1024 + 1))]);
        $this->assertSame('too_large', $this->fetcher()->fetch('https://a.vn/big', ['a.vn'])['error']);
        Http::fake(['a.vn/robots.txt' => Http::response('', 404), 'a.vn/*' => $this->html('x', ['Content-Length' => '9999999'])]);
        $this->assertSame('too_large', (new SafePageFetcher(new FakeSafety, 0))->fetch('https://a.vn/big2', ['a.vn'])['error']);
    }

    public function test_khong_phai_html(): void
    {
        Http::fake(['a.vn/robots.txt' => Http::response('', 404), 'a.vn/*' => Http::response('%PDF', 200, ['Content-Type' => 'application/pdf'])]);
        $this->assertSame('not_html', $this->fetcher()->fetch('https://a.vn/f.pdf', ['a.vn'])['error']);
    }

    public function test_van_ban_thuong_duoc_nhan(): void
    {
        Http::fake(['a.vn/robots.txt' => Http::response('', 404), 'a.vn/*' => Http::response('xin chào', 200, ['Content-Type' => 'text/plain'])]);
        $this->assertTrue($this->fetcher()->fetch('https://a.vn/t', ['a.vn'])['ok']);
    }

    public function test_ma_loi_http(): void
    {
        Http::fake(['a.vn/robots.txt' => Http::response('', 404), 'a.vn/*' => Http::response('', 500)]);
        $r = $this->fetcher()->fetch('https://a.vn/x', ['a.vn']);
        $this->assertSame('http_error', $r['error']);
        $this->assertSame(500, $r['http_status']);
    }

    public function test_het_thoi_gian(): void
    {
        Http::fake(['a.vn/robots.txt' => Http::response('', 404), 'a.vn/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);
        $this->assertSame('timeout', $this->fetcher()->fetch('https://a.vn/slow', ['a.vn'])['error']);
    }

    public function test_robots_chan_trang(): void
    {
        Http::fake(['a.vn/robots.txt' => Http::response("User-agent: *\nDisallow: /rieng\nAllow: /rieng/cho-phep\n", 200, ['Content-Type' => 'text/plain']), 'a.vn/*' => $this->html()]);
        $f = $this->fetcher();
        $this->assertSame('robots', $f->fetch('https://a.vn/rieng/bi-chan', ['a.vn'])['error']);
        $this->assertTrue($f->fetch('https://a.vn/rieng/cho-phep', ['a.vn'])['ok']);
        $this->assertTrue($f->fetch('https://a.vn/cong-khai', ['a.vn'])['ok']);
        Http::assertNotSent(fn ($req) => str_ends_with($req->url(), '/rieng/bi-chan'));
    }

    public function test_robots_nhom_rieng_cho_bot_thang_nhom_sao(): void
    {
        $rules = SafePageFetcher::parseRobots("User-agent: *\nDisallow: /\n\nUser-agent: ADDP-AutoSEO-KnowledgeBot\nDisallow: /admin\n");
        $this->assertTrue(SafePageFetcher::robotsAllows($rules, '/san-pham'));
        $this->assertFalse(SafePageFetcher::robotsAllows($rules, '/admin/x'));
        $this->assertFalse(SafePageFetcher::robotsAllows(SafePageFetcher::parseRobots("User-agent: *\nDisallow: /\n"), '/a'));
        $this->assertTrue(SafePageFetcher::robotsAllows([], '/a'));
    }

    public function test_robots_khong_co_thi_cho_phep(): void
    {
        Http::fake(['a.vn/robots.txt' => Http::response('', 404), 'a.vn/*' => $this->html()]);
        $this->assertTrue($this->fetcher()->fetch('https://a.vn/a', ['a.vn'])['ok']);
    }
}
