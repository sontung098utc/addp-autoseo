<?php

namespace Tests\Feature\Planning;

use App\Filament\Support\Gsc\GoogleSearchConsoleSource;
use App\Filament\Support\Gsc\GscAccessToken;
use App\Filament\Support\Gsc\GscFailed;
use App\Filament\Support\Gsc\GscService;
use App\Filament\Support\Gsc\PeriodComparer;
use App\Models\Article;
use App\Models\Keyword;
use App\Models\User;
use App\Models\Website;
use App\Services\Planning\ContentPlanner;
use App\Services\Planning\GscSignals;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/** Nguồn Search Console thật + tín hiệu cơ hội vào kế hoạch nội dung. Toàn bộ HTTP là giả lập; khóa RSA sinh tạm trong test, không phải khóa thật. */
class GscSignalsTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    private string $keyFile;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->site = Website::create(['name' => 'A', 'cms_type' => 'wordpress', 'base_url' => 'https://www.a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $pem);
        $this->keyFile = tempnam(sys_get_temp_dir(), 'gsc');
        file_put_contents($this->keyFile, json_encode(['client_email' => 'bot@test.iam.gserviceaccount.com', 'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token']));
    }

    protected function tearDown(): void
    {
        @unlink($this->keyFile);
        parent::tearDown();
    }

    private function configure(): void
    {
        config(['gsc.service_account_json' => $this->keyFile, 'gsc.property_type' => 'domain', 'gsc.properties' => '', 'studio.demo' => false]);
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    /** Hai truy vấn: "đường huyết cao" (vị trí 11,2, 1000 hiển thị, CTR thấp → cơ hội) và "ăn gì" (vị trí 3 → không cơ hội). */
    private function fakeGoogle(array $extra = []): void
    {
        $day = PeriodComparer::periods(CarbonImmutable::now())['current']['to']->toDateString();
        Http::fake($extra + [
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok-test', 'expires_in' => 3600]),
            'www.googleapis.com/*' => function (Request $r) use ($day) {
                $dims = $r['dimensions'];
                $mk = fn (string $k, string $p, int $clicks, int $imp, float $pos) => ['keys' => array_slice([$day, $k, $p], 0, count($dims)), 'clicks' => $clicks, 'impressions' => $imp, 'position' => $pos];
                if ($dims === ['date', 'page']) {
                    return Http::response(['rows' => [['keys' => [$day, 'https://a.vn/x'], 'clicks' => 12, 'impressions' => 6000, 'position' => 5.0]]]);
                }
                if ($dims === ['date', 'query', 'page']) {
                    return Http::response(['rows' => [$mk('đường huyết cao', 'https://a.vn/x', 10, 1000, 11.2), $mk('ăn gì', 'https://a.vn/y', 400, 5000, 3.0)]]);
                }

                return Http::response(['rows' => [['keys' => [$day, 'mobile'], 'clicks' => 1, 'impressions' => 10, 'position' => 4.0]]]);
            },
        ]);
    }

    public function test_thuoc_tinh_theo_ten_mien_url_va_ghi_de(): void
    {
        config(['gsc.property_type' => 'domain', 'gsc.properties' => '']);
        $this->assertSame('sc-domain:a.vn', GoogleSearchConsoleSource::propertyFor($this->site));
        config(['gsc.property_type' => 'url']);
        $this->assertSame('https://a.vn/', GoogleSearchConsoleSource::propertyFor($this->site));
        config(['gsc.properties' => 'b.vn=sc-domain:b.vn; a.vn=https://a.vn/blog/']);
        $this->assertSame('https://a.vn/blog/', GoogleSearchConsoleSource::propertyFor($this->site));
    }

    public function test_khong_co_khoa_thi_khong_co_nguon_that(): void
    {
        config(['gsc.service_account_json' => '', 'studio.demo' => false]);
        $this->assertNull(GoogleSearchConsoleSource::forWebsite($this->site));
        $why = null;
        $this->assertNull(GscSignals::forWebsite($this->site, 28, $why));
        $this->assertStringContainsString('chưa nối Search Console thật', $why);
        $this->assertFalse(GscService::forWebsite($this->site)->isReal());
    }

    public function test_token_ky_jwt_va_cache_va_khong_lo_khoa(): void
    {
        $this->configure();
        $this->fakeGoogle();
        $a = GscAccessToken::fromConfig();
        $this->assertSame('tok-test', $a->get());
        $this->assertSame('tok-test', $a->get(), 'cache, không gọi lại');
        Http::assertSentCount(1);
        Http::assertSent(function (Request $r) {
            parse_str($r->body(), $f);
            [$h, $c, $s] = explode('.', $f['assertion']);
            $claims = json_decode(base64_decode(strtr($c, '-_', '+/')), true);

            return $f['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer' && $claims['iss'] === 'bot@test.iam.gserviceaccount.com'
                && str_contains($claims['scope'], 'webmasters.readonly') && $s !== '';
        });
    }

    public function test_token_bi_google_tu_choi_bao_loi_khong_lo_khoa(): void
    {
        $this->configure();
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Invalid JWT'], 400)]);
        try {
            GscAccessToken::fromConfig()->get();
            $this->fail('phải ném GscFailed');
        } catch (GscFailed $e) {
            $this->assertStringContainsString('Invalid JWT', $e->getMessage());
            $this->assertStringNotContainsString('PRIVATE KEY', $e->getMessage());
        }
    }

    public function test_nguon_that_doc_dung_hang_va_403_khong_tra_so_gia(): void
    {
        $this->configure();
        $this->fakeGoogle();
        $src = GoogleSearchConsoleSource::forWebsite($this->site);
        $this->assertSame('sc-domain:a.vn', $src->property());
        $p = PeriodComparer::periods(CarbonImmutable::now())['current'];
        $rows = $src->rows('query', $p['from'], $p['to']);
        $this->assertCount(2, $rows);
        $this->assertSame('đường huyết cao', $rows[0]['key']);
        $this->assertSame('https://a.vn/x', $rows[0]['page']);
        $this->assertSame(1000, $rows[0]['impressions']);
        $this->assertFalse($src->isEmpty());
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer tok-test') && str_contains($r->url(), rawurlencode('sc-domain:a.vn')));

    }

    public function test_search_console_403_khong_tra_so_gia_va_noi_ro_ly_do(): void
    {
        $this->configure();
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 't', 'expires_in' => 3600]), 'www.googleapis.com/*' => Http::response([], 403)]);
        $p = PeriodComparer::periods(CarbonImmutable::now())['current'];
        $bad = GoogleSearchConsoleSource::forWebsite($this->site);
        $this->assertSame([], $bad->rows('query', $p['from'], $p['to']));
        $this->assertTrue($bad->isEmpty());
        $this->assertStringContainsString('chưa được thêm vào thuộc tính sc-domain:a.vn', $bad->error());

        $why = null;
        $this->assertNull(GscSignals::forWebsite($this->site, 28, $why));
        $this->assertStringContainsString('Search Console lỗi', $why);
    }

    public function test_tin_hieu_co_hoi_trang_1_va_ung_vien_chua_co_bai(): void
    {
        $this->configure();
        $this->fakeGoogle();
        Keyword::create(['website_id' => $this->site->id, 'keyword' => 'ăn gì', 'keyword_key' => 'ăn gì', 'source' => 'tay', 'status' => 'new']);
        $why = null;
        $sig = GscSignals::forWebsite($this->site, 28, $why);

        $this->assertNotNull($sig, (string) $why);
        $hi = $sig['queries']['đường huyết cao'];
        $this->assertContains('co-hoi-trang-1', $hi['rules']);
        $this->assertGreaterThan(0, $hi['points']);
        $this->assertLessThanOrEqual(GscSignals::MAX_POINTS, $hi['points']);
        $this->assertSame(0, $sig['queries']['ăn gì']['points'], 'vị trí 3 không phải cơ hội');

        $c = GscSignals::candidates($this->site, $sig);
        $this->assertSame(['đường huyết cao'], array_column($c, 'keyword'));
        $this->assertSame(1000, $c[0]['impressions']);

        Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'Đường  huyết cao', 'workflow_status' => 'planned', 'kind' => 'new']);
        $this->assertSame([], GscSignals::candidates($this->site, $sig), 'đã có bài thì không đề xuất lại');
    }

    public function test_nhap_tu_khoa_can_quyen_va_khong_ghi_luot_tim_gia(): void
    {
        $this->expectException(InvalidArgumentException::class);
        try {
            GscSignals::import($this->user('viewer'), $this->site, ['đường huyết cao']);
        } finally {
            $this->assertSame(0, Keyword::count());
        }
    }

    public function test_nhap_tu_khoa_tu_gsc_nguon_gsc_trang_thai_moi_khong_trung(): void
    {
        Keyword::create(['website_id' => $this->site->id, 'keyword' => 'đã có', 'keyword_key' => 'đã có', 'source' => 'tay', 'status' => 'new']);
        $r = GscSignals::import($this->user('writer'), $this->site, ['Đường huyết  cao', 'đường huyết cao', 'Đã có']);
        $this->assertSame(1, $r['added']);
        $this->assertCount(1, $r['skipped']);
        $k = Keyword::where('keyword_key', 'đường huyết cao')->first();
        $this->assertSame('gsc', $k->source);
        $this->assertSame('new', $k->status);
        $this->assertNull($k->volume, 'impressions không phải lượt tìm');
        $this->assertNull($k->volume_source);
    }

    public function test_content_planner_cong_diem_gsc_va_ghi_ly_do_nguon(): void
    {
        $this->configure();
        $this->fakeGoogle();
        Keyword::create(['website_id' => $this->site->id, 'keyword' => 'đường huyết cao', 'keyword_key' => 'đường huyết cao', 'source' => 'gsc', 'status' => 'new']);
        Keyword::create(['website_id' => $this->site->id, 'keyword' => 'khác', 'keyword_key' => 'khác', 'source' => 'tay', 'status' => 'new']);

        $without = collect((new ContentPlanner)->propose($this->site))->keyBy('keyword');
        $this->assertSame(0, $without['đường huyết cao']['priority'], 'không dùng Search Console thì không cộng điểm');

        $sig = GscSignals::forWebsite($this->site);
        $with = collect((new ContentPlanner)->propose($this->site, 10, $sig))->keyBy('keyword');
        $row = $with['đường huyết cao'];
        $this->assertSame($sig['queries']['đường huyết cao']['points'], $row['priority']);
        $this->assertSame('gsc', collect($row['sources'])->firstWhere('type', 'gsc')['type']);
        $this->assertStringContainsString('Search Console', implode(' ', $row['reasons']));
        $this->assertStringContainsString('1.000 lượt hiển thị', implode(' ', $row['reasons']));
        $this->assertSame(0, $with['khác']['priority']);
        $this->assertSame('đường huyết cao', array_column((new ContentPlanner)->propose($this->site, 10, $sig), 'keyword')[0], 'cơ hội lên đầu');
    }
}
