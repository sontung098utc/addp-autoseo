<?php

namespace Tests\Feature;

use App\Contracts\PublisherInterface;
use App\Integrations\WordPress\Exceptions\WordPressAmbiguousException;
use App\Integrations\WordPress\Exceptions\WordPressException;
use App\Integrations\WordPress\Taxonomy\WordPressTaxonomyService;
use App\Integrations\WordPress\WordPressClient;
use App\Integrations\WordPress\WordPressPublisher;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\Website;
use App\Services\Publishing\PublisherManager;
use App\Services\Security\UrlSafetyService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** Test GIẢ LẬP bằng MockHandler: không gọi WordPress thật (tích hợp staging: Chưa chạy). */
class WordPressConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const PASS = 'abcd EFGH 1234 SeCrEt-pw';
    private const KEY = 'pub-1-a1-v1';

    private array $history = [];
    private Website $website;
    private ArticleVersion $version;
    private Publication $publication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->website = $this->makeWebsite('https://blog.example.com');
        $article = Article::create(['website_id' => $this->website->id, 'primary_keyword' => 'kw', 'search_intent' => 'informational', 'workflow_status' => 'approved']);
        $this->version = ArticleVersion::create([
            'article_id' => $article->id, 'version' => 1, 'title' => 'Tiêu đề', 'slug' => 'tieu-de',
            'html_content' => '<p>Nội dung</p><script>alert(1)</script>', 'seo_title' => 'SEO T', 'meta_description' => 'Mô tả',
            'focus_keyword' => 'kw', 'review_status' => 'approved',
        ]);
        $this->publication = Publication::create([
            'website_id' => $this->website->id, 'article_version_id' => $this->version->id,
            'publication_key' => self::KEY, 'scheduled_at_utc' => now(), 'status' => 'publishing', 'timezone' => 'Asia/Ho_Chi_Minh',
        ]);
    }

    private function makeWebsite(string $url, array $flags = []): Website
    {
        return Website::create([
            'name' => 'WP ' . uniqid(), 'cms_type' => 'wordpress', 'base_url' => $url, 'status' => 'active',
            'capability_flags' => $flags,
            'encrypted_credentials' => ['username' => 'editor', 'application_password' => self::PASS],
        ]);
    }

    private function publisher(array $queue): WordPressPublisher
    {
        $mock = new MockHandler($queue);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));
        // Resolver giả: không phụ thuộc DNS thật; IP literal vẫn đi qua isBlockedIp thật.
        $safety = new class extends UrlSafetyService {
            protected function resolveIps(string $host): array
            {
                return filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ['93.184.216.34'];
            }
        };
        $client = new WordPressClient(new Client(['handler' => $stack]), $safety);

        return new WordPressPublisher($client, new WordPressTaxonomyService($client));
    }

    private function jr(int $status, $data): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($data));
    }

    private function wpPost(array $over = []): array
    {
        return $over + ['id' => 55, 'status' => 'publish', 'link' => 'https://blog.example.com/tieu-de/', 'content' => ['raw' => '<p>x</p>'], 'meta' => []];
    }

    private function methods(): array
    {
        return array_map(fn ($t) => $t['request']->getMethod(), $this->history);
    }

    // ---------- happy path / idempotency ----------

    public function test_publish_tao_moi_khi_tra_key_rong(): void
    {
        $p = $this->publisher([$this->jr(200, []), $this->jr(201, $this->wpPost()), $this->jr(200, $this->wpPost())]);
        $r = $p->publish($this->publication);

        $this->assertSame('55', $r['remote_id']);
        $this->assertSame('https://blog.example.com/tieu-de/', $r['published_url']);
        $this->assertSame(['GET', 'POST', 'GET'], $this->methods());
        $body = json_decode((string) $this->history[1]['request']->getBody(), true);
        $this->assertSame('publish', $body['status']);
        $this->assertStringContainsString('<!-- autoseo:publication_key=' . self::KEY . ' -->', $body['content']);
        $this->assertStringNotContainsString('<script', $body['content']);
        $this->assertStringNotContainsString('alert(1)</script', $body['content']);
        $this->assertSame('Mô tả', $body['excerpt']);
        $this->assertTrue(str_starts_with($this->history[0]['request']->getHeaderLine('Authorization'), 'Basic '));
    }

    public function test_publish_idempotent_khi_da_co_bai_khong_goi_post(): void
    {
        $existing = $this->wpPost(['content' => ['raw' => '<p>x</p><!-- autoseo:publication_key=' . self::KEY . ' -->']]);
        $p = $this->publisher([$this->jr(200, [$existing])]);
        $r = $p->publish($this->publication);

        $this->assertSame('55', $r['remote_id']);
        $this->assertTrue($r['already_existed']);
        $this->assertSame(['GET'], $this->methods());
    }

    public function test_bai_co_san_o_trang_thai_nhap_duoc_dua_len_publish_khong_tao_moi(): void
    {
        $existing = $this->wpPost(['status' => 'draft', 'content' => ['raw' => '<!-- autoseo:publication_key=' . self::KEY . ' -->']]);
        $p = $this->publisher([$this->jr(200, [$existing]), $this->jr(200, $this->wpPost()), $this->jr(200, $this->wpPost())]);
        $p->publish($this->publication);

        $this->assertSame(['GET', 'POST', 'GET'], $this->methods());
        $this->assertStringContainsString('/posts/55', (string) $this->history[1]['request']->getUri());
    }

    public function test_so_khop_key_chinh_xac_khong_khop_tien_to(): void
    {
        $other = $this->wpPost(['id' => 9, 'content' => ['raw' => '<!-- autoseo:publication_key=pub-1-a1-v10 -->']]);
        $p = $this->publisher([$this->jr(200, [$other])]);

        $this->assertNull($p->getByPublicationKey($this->website, 'pub-1-a1-v1'));
    }

    public function test_nhieu_bai_khop_key_nem_err06(): void
    {
        $m = '<!-- autoseo:publication_key=' . self::KEY . ' -->';
        $p = $this->publisher([$this->jr(200, [$this->wpPost(['id' => 1, 'content' => ['raw' => $m]]), $this->wpPost(['id' => 2, 'content' => ['raw' => $m]])])]);

        $this->assertCode('ERR06', fn () => $p->getByPublicationKey($this->website, self::KEY));
    }

    public function test_key_sai_dinh_dang_bi_chan_truoc_khi_gui(): void
    {
        $p = $this->publisher([]);
        $this->assertCode('ERR06', fn () => $p->getByPublicationKey($this->website, 'a b<!-->'));
        $this->assertSame([], $this->history);
    }

    // ---------- 7 mã lỗi ----------

    public function test_err01_timeout_sau_khi_gui_post_la_mo_ho_va_khong_tao_lan_hai(): void
    {
        $timeout = new ConnectException('cURL error 28', new Request('POST', 'https://blog.example.com'), null, ['errno' => 28, 'connect_time' => 0.3]);
        $p = $this->publisher([$this->jr(200, []), $timeout]);

        try {
            $p->publish($this->publication);
            $this->fail('Phải ném ngoại lệ');
        } catch (WordPressAmbiguousException $e) {
            $this->assertSame('ERR01', $e->errorCode());
            $this->assertTrue($e->isAmbiguous());
            // PublishArticleJob chỉ nhận ConnectionException => needs_reconcile
            $this->assertInstanceOf(\Illuminate\Http\Client\ConnectionException::class, $e);
        }
        $this->assertSame(['GET', 'POST'], $this->methods());
    }

    public function test_err02_401_va_403(): void
    {
        foreach ([401, 403] as $status) {
            $p = $this->publisher([$this->jr($status, ['code' => 'rest_forbidden'])]);
            $this->assertCode('ERR02', fn () => $p->getByPublicationKey($this->website, self::KEY));
        }
    }

    public function test_err03_404_va_redirect_khong_duoc_theo(): void
    {
        $p = $this->publisher([$this->jr(404, [])]);
        $this->assertCode('ERR03', fn () => $p->getByPublicationKey($this->website, self::KEY));

        $this->history = [];
        $p = $this->publisher([new Response(301, ['Location' => 'https://evil.example.org/x'])]);
        $this->assertCode('ERR03', fn () => $p->getByPublicationKey($this->website, self::KEY));
        $this->assertCount(1, $this->history); // không có request thứ hai sang host khác
    }

    public function test_err04_429(): void
    {
        $p = $this->publisher([$this->jr(429, [])]);
        $this->assertCode('ERR04', fn () => $p->getByPublicationKey($this->website, self::KEY));
    }

    public function test_err05_5xx_khi_doc_la_khong_mo_ho_con_khi_post_la_mo_ho(): void
    {
        foreach ([500, 503] as $status) {
            $p = $this->publisher([$this->jr($status, [])]);
            $e = $this->assertCode('ERR05', fn () => $p->getByPublicationKey($this->website, self::KEY));
            $this->assertFalse($e->isAmbiguous());
        }
        $p = $this->publisher([$this->jr(200, []), $this->jr(502, [])]);
        $e = $this->assertCode('ERR05', fn () => $p->publish($this->publication));
        $this->assertTrue($e->isAmbiguous());
    }

    public function test_err06_json_hong_thieu_id_va_doc_lai_sai(): void
    {
        $p = $this->publisher([new Response(200, [], '{not json')]);
        $this->assertCode('ERR06', fn () => $p->getByPublicationKey($this->website, self::KEY));

        $p = $this->publisher([$this->jr(200, []), $this->jr(201, ['status' => 'publish'])]);
        $e = $this->assertCode('ERR06', fn () => $p->publish($this->publication));
        $this->assertTrue($e->isAmbiguous());

        $p = $this->publisher([$this->jr(200, []), $this->jr(201, $this->wpPost()), $this->jr(200, $this->wpPost(['status' => 'pending']))]);
        $this->assertCode('ERR06', fn () => $p->publish($this->publication));
    }

    public function test_err07_connect_exception_truoc_khi_gui_an_toan_thu_lai(): void
    {
        $refused = new ConnectException('cURL error 7', new Request('GET', 'https://blog.example.com'), null, ['errno' => 7]);
        $p = $this->publisher([$refused]);
        $e = $this->assertCode('ERR07', fn () => $p->publish($this->publication));
        $this->assertFalse($e->isAmbiguous());
        $this->assertSame(['GET'], $this->methods()); // chưa có POST nào
    }

    public function test_get_by_key_khong_tra_null_khi_loi_mang_hoac_5xx(): void
    {
        $net = new ConnectException('x', new Request('GET', 'https://blog.example.com'), null, ['errno' => 28, 'connect_time' => 1.0]);
        foreach ([[$net], [$this->jr(500, [])]] as $queue) {
            $p = $this->publisher($queue);
            try {
                $p->getByPublicationKey($this->website, self::KEY);
                $this->fail('Không được trả null/giá trị khi lỗi');
            } catch (\Throwable $e) {
                $this->assertContains($e->errorCode(), ['ERR01', 'ERR05']);
            }
        }
    }

    // ---------- bảo mật ----------

    public function test_ssrf_chan_truoc_khi_gui_bat_ky_request(): void
    {
        foreach (['http://blog.example.com', 'https://127.0.0.1', 'https://10.1.2.3', 'https://169.254.169.254', 'https://localhost', 'https://u:p@blog.example.com'] as $url) {
            $site = $this->makeWebsite($url);
            $p = $this->publisher([$this->jr(200, [])]);
            $this->assertCode('ERR03', fn () => $p->getByPublicationKey($site, self::KEY));
        }
        $this->assertSame([], $this->history);
    }

    public function test_credential_khong_lo_trong_exception_va_log(): void
    {
        Log::spy();
        $p = $this->publisher([$this->jr(401, ['message' => self::PASS]), $this->jr(401, [])]);
        $e = $this->assertCode('ERR02', fn () => $p->getByPublicationKey($this->website, self::KEY));
        $this->assertStringNotContainsString(self::PASS, $e->getMessage());
        $this->assertStringNotContainsString('editor', $e->getMessage());
        $this->assertFalse($p->testConnection($this->website));
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => ! str_contains((string) $m, self::PASS) && ! str_contains((string) $m, 'Basic '))->atLeast()->once();
    }

    public function test_thieu_credential_ném_err02_khong_gui_request(): void
    {
        $this->website->update(['encrypted_credentials' => ['username' => 'editor']]);
        $p = $this->publisher([]);
        $this->assertCode('ERR02', fn () => $p->getByPublicationKey($this->website->fresh(), self::KEY));
        $this->assertSame([], $this->history);
    }

    // ---------- testConnection ----------

    public function test_test_connection(): void
    {
        $p = $this->publisher([$this->jr(200, ['id' => 3, 'capabilities' => ['publish_posts' => true]])]);
        $this->assertTrue($p->testConnection($this->website));
        $this->assertStringContainsString('context=edit', $this->history[0]['request']->getUri()->getQuery());

        $p = $this->publisher([$this->jr(200, ['id' => 3, 'capabilities' => ['read' => true]])]);
        $this->assertFalse($p->testConnection($this->website));

        $p = $this->publisher([$this->jr(403, [])]);
        $this->assertFalse($p->testConnection($this->website));
    }

    // ---------- media ----------

    public function test_upload_media_mime_gia_bi_chan(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'wp') . '.jpg';
        file_put_contents($f, '<?php echo "pwn";');
        $p = $this->publisher([]);
        try {
            $this->expectException(\InvalidArgumentException::class);
            $p->uploadMedia($this->website, $f, 'alt');
        } finally {
            @unlink($f);
            $this->assertSame([], $this->history);
        }
    }

    public function test_upload_media_thanh_cong_va_cap_nhat_alt(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'wp');
        file_put_contents($f, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        $p = $this->publisher([
            $this->jr(201, ['id' => 77, 'source_url' => 'https://blog.example.com/wp-content/a.png']),
            $this->jr(200, ['id' => 77]),
        ]);
        $r = $p->uploadMedia($this->website, $f, 'Ảnh minh họa');
        @unlink($f);

        $this->assertSame(77, $r['id']);
        $this->assertSame('https://blog.example.com/wp-content/a.png', $r['source_url']);
        $this->assertSame('image/png', $this->history[0]['request']->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment; filename="', $this->history[0]['request']->getHeaderLine('Content-Disposition'));
        $this->assertSame('Ảnh minh họa', json_decode((string) $this->history[1]['request']->getBody(), true)['alt_text']);
    }

    // ---------- SEO ----------

    public function test_seo_rank_math_va_yoast_tao_dung_khoa_meta(): void
    {
        foreach (['rank_math' => 'rank_math_description', 'yoast' => '_yoast_wpseo_metadesc'] as $plugin => $key) {
            $this->history = [];
            $this->website->update(['capability_flags' => ['seo_plugin' => $plugin]]);
            $saved = $this->wpPost(['meta' => [$key => 'Mô tả']]);
            $p = $this->publisher([$this->jr(200, []), $this->jr(201, $saved), $this->jr(200, $saved)]);
            $r = $p->publish($this->publication->fresh());

            $sent = json_decode((string) $this->history[1]['request']->getBody(), true)['meta'];
            $this->assertSame('Mô tả', $sent[$key]);
            $this->assertArrayHasKey($plugin === 'yoast' ? '_yoast_wpseo_title' : 'rank_math_title', $sent);
            $this->assertFalse($r['seo_meta_persisted']); // chỉ meta_description được đọc lại, title thì không
        }
    }

    public function test_seo_khong_luu_van_dang_thanh_cong(): void
    {
        Log::spy();
        $this->website->update(['capability_flags' => ['seo_plugin' => 'rank_math']]);
        $p = $this->publisher([$this->jr(200, []), $this->jr(201, $this->wpPost()), $this->jr(200, $this->wpPost())]);
        $r = $p->publish($this->publication->fresh());

        $this->assertSame('55', $r['remote_id']);
        $this->assertFalse($r['seo_meta_persisted']);
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    public function test_seo_400_do_meta_khong_dang_ky_thu_lai_khong_meta(): void
    {
        $this->website->update(['capability_flags' => ['seo_plugin' => 'yoast']]);
        $p = $this->publisher([$this->jr(200, []), $this->jr(400, ['code' => 'rest_invalid_param']), $this->jr(201, $this->wpPost()), $this->jr(200, $this->wpPost())]);
        $r = $p->publish($this->publication->fresh());

        $this->assertSame(['GET', 'POST', 'POST', 'GET'], $this->methods());
        $this->assertArrayNotHasKey('meta', json_decode((string) $this->history[2]['request']->getBody(), true));
        $this->assertFalse($r['seo_meta_persisted']);
    }

    public function test_khong_seo_plugin_thi_seo_meta_persisted_null(): void
    {
        $p = $this->publisher([$this->jr(200, []), $this->jr(201, $this->wpPost()), $this->jr(200, $this->wpPost())]);
        $this->assertNull($p->publish($this->publication)['seo_meta_persisted']);
        $this->assertArrayNotHasKey('meta', json_decode((string) $this->history[1]['request']->getBody(), true));
    }

    // ---------- taxonomy / draft ----------

    public function test_taxonomy_tim_tao_va_bo_qua_khi_khong_duoc_tao(): void
    {
        $this->website->update(['capability_flags' => ['default_categories' => ['Tin tức', 'Mới'], 'allow_create_terms' => false]]);
        $p = $this->publisher([
            $this->jr(200, []), // tra key: chưa có bài
            $this->jr(200, [['id' => 5, 'name' => 'Tin tức']]), $this->jr(200, []), // 'Mới' thiếu, không được tạo
            $this->jr(201, $this->wpPost()), $this->jr(200, $this->wpPost()),
        ]);
        $r = $p->publish($this->publication->fresh());

        $this->assertSame(['Mới'], $r['taxonomy']['skipped']);
        $this->assertSame([5], json_decode((string) $this->history[3]['request']->getBody(), true)['categories']);
        $this->assertNotContains('POST', array_slice($this->methods(), 0, 3));
    }

    public function test_create_draft_khong_co_dau_nhan_dang(): void
    {
        $p = $this->publisher([$this->jr(201, $this->wpPost(['status' => 'draft', 'link' => 'https://blog.example.com/?p=55']))]);
        $r = $p->createDraft($this->website, $this->version);

        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame('draft', $body['status']);
        $this->assertStringNotContainsString('autoseo:publication_key', $body['content']);
        $this->assertSame('55', $r['remote_id']);
        $this->assertSame('https://blog.example.com/?p=55', $r['preview_url']);
    }

    // ---------- provider ----------

    public function test_provider_dang_ky_wordpress_publisher(): void
    {
        $this->assertInstanceOf(WordPressPublisher::class, app(PublisherInterface::class));
        $this->assertInstanceOf(WordPressPublisher::class, app(PublisherManager::class)->getPublisher($this->website));
    }

    private function assertCode(string $code, callable $fn): \Throwable
    {
        try {
            $fn();
        } catch (WordPressException|WordPressAmbiguousException $e) {
            $this->assertSame($code, $e->errorCode());

            return $e;
        }
        $this->fail("Phải ném {$code}");
    }
}
