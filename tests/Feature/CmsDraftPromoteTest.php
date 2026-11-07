<?php

namespace Tests\Feature;

use App\Integrations\WordPress\Exceptions\WordPressAmbiguousException;
use App\Integrations\WordPress\Exceptions\WordPressException;
use App\Integrations\WordPress\Taxonomy\WordPressTaxonomyService;
use App\Integrations\WordPress\WordPressClient;
use App\Integrations\WordPress\WordPressPublisher;
use App\Jobs\PublishArticleJob;
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
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** AG-S04: đăng từ nháp, hủy nháp. Test GIẢ LẬP bằng MockHandler; chưa chạy WordPress thật/staging. */
class CmsDraftPromoteTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'pub-1-a1-v1';
    private const PASS = 'abcd EFGH 1234 SeCrEt-pw';

    private array $history = [];
    private Website $website;
    private ArticleVersion $version;
    private Publication $publication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->website = Website::create(['name' => 'WP', 'cms_type' => 'wordpress', 'base_url' => 'https://blog.example.com', 'status' => 'active',
            'capability_flags' => [], 'encrypted_credentials' => ['username' => 'editor', 'application_password' => self::PASS]]);
        $article = Article::create(['website_id' => $this->website->id, 'primary_keyword' => 'kw', 'search_intent' => 'informational', 'workflow_status' => 'approved']);
        $this->version = ArticleVersion::create(['article_id' => $article->id, 'version' => 1, 'title' => 'Tiêu đề', 'slug' => 'tieu-de',
            'html_content' => '<p>Nội dung</p>', 'meta_description' => 'Mô tả', 'focus_keyword' => 'kw', 'review_status' => 'approved',
            'cms_draft_id' => '77', 'cms_draft_url' => 'https://blog.example.com/?p=77', 'cms_draft_status' => 'pushed']);
        $this->publication = Publication::create(['website_id' => $this->website->id, 'article_version_id' => $this->version->id,
            'publication_key' => self::KEY, 'scheduled_at_utc' => now(), 'status' => 'publishing', 'timezone' => 'Asia/Ho_Chi_Minh']);
    }

    private function publisher(array $queue): WordPressPublisher
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));
        $safety = new class extends UrlSafetyService {
            protected function resolveIps(string $host): array
            {
                return ['93.184.216.34'];
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
        return $over + ['id' => 77, 'status' => 'publish', 'link' => 'https://blog.example.com/tieu-de/', 'content' => ['raw' => '<p>x</p>'], 'meta' => []];
    }

    private function calls(): array
    {
        return array_map(fn ($t) => $t['request']->getMethod().' '.$t['request']->getUri()->getPath(), $this->history);
    }

    public function test_dang_tu_nhap_cap_nhat_chinh_bai_nhap_khong_tao_bai_thu_hai(): void
    {
        $p = $this->publisher([$this->jr(200, []), $this->jr(200, $this->wpPost()), $this->jr(200, $this->wpPost())]);
        $r = $p->publish($this->publication);

        $this->assertSame('77', $r['remote_id']);
        $this->assertSame('77', $r['promoted_draft_id']);
        $this->assertSame(['GET /wp-json/wp/v2/posts', 'POST /wp-json/wp/v2/posts/77', 'GET /wp-json/wp/v2/posts/77'], $this->calls());
        $body = json_decode((string) $this->history[1]['request']->getBody(), true);
        $this->assertSame('publish', $body['status']);
        $this->assertStringContainsString('<!-- autoseo:publication_key='.self::KEY.' -->', $body['content']);
        $this->assertNotContains('POST /wp-json/wp/v2/posts', $this->calls(), 'không được tạo bài mới');
    }

    public function test_goi_lai_sau_khi_da_dang_tu_nhap_tra_key_thay_bai_va_khong_ghi_gi(): void
    {
        $existing = $this->wpPost(['content' => ['raw' => '<p>x</p><!-- autoseo:publication_key='.self::KEY.' -->']]);
        $p = $this->publisher([$this->jr(200, [$existing])]);
        $r = $p->publish($this->publication);

        $this->assertTrue($r['already_existed']);
        $this->assertSame(['GET /wp-json/wp/v2/posts'], $this->calls());
    }

    public function test_timeout_sau_khi_gui_khi_dang_tu_nhap_la_mo_ho_de_doi_soat_khong_tao_moi(): void
    {
        $timeout = new ConnectException('cURL error 28', new Request('POST', 'x'), null, ['errno' => 28, 'connect_time' => 0.2]);
        $p = $this->publisher([$this->jr(200, []), $timeout]);

        try {
            $p->publish($this->publication);
            $this->fail('phải ném lỗi mơ hồ');
        } catch (WordPressAmbiguousException $e) {
            $this->assertSame(['GET /wp-json/wp/v2/posts', 'POST /wp-json/wp/v2/posts/77'], $this->calls());
        }
    }

    public function test_job_chuyen_needs_reconcile_khi_timeout_va_khong_dang_lai(): void
    {
        Cache::flush();
        $timeout = new ConnectException('cURL error 28', new Request('POST', 'x'), null, ['errno' => 28, 'connect_time' => 0.2]);
        $manager = new PublisherManager();
        $manager->registerPublisher('wordpress', $this->publisher([$this->jr(200, []), $timeout]));
        $this->publication->update(['status' => 'scheduled']);

        (new PublishArticleJob($this->publication))->handle($manager);

        $this->assertSame('needs_reconcile', $this->publication->refresh()->status);
        $this->assertSame('pushed', $this->version->refresh()->cms_draft_status, 'chưa chắc đã đăng => chưa đánh dấu promoted');
        $this->assertCount(2, $this->history);
    }

    public function test_job_danh_dau_nhap_da_dang_khi_thanh_cong(): void
    {
        Cache::flush();
        $manager = new PublisherManager();
        $manager->registerPublisher('wordpress', $this->publisher([$this->jr(200, []), $this->jr(200, $this->wpPost()), $this->jr(200, $this->wpPost())]));
        $this->publication->update(['status' => 'scheduled']);

        (new PublishArticleJob($this->publication))->handle($manager);

        $this->publication->refresh();
        $this->assertSame('published', $this->publication->status);
        $this->assertSame('77', $this->publication->cms_post_id);
        $this->assertSame('promoted', $this->version->refresh()->cms_draft_status);
    }

    public function test_nhap_da_bi_xoa_tren_cms_404_thi_dang_bai_moi_binh_thuong(): void
    {
        $p = $this->publisher([$this->jr(200, []), $this->jr(404, ['code' => 'rest_post_invalid_id']), $this->jr(201, $this->wpPost(['id' => 90])), $this->jr(200, $this->wpPost(['id' => 90]))]);
        $r = $p->publish($this->publication);

        $this->assertSame('90', $r['remote_id']);
        $this->assertNull($r['promoted_draft_id']);
        $this->assertContains('POST /wp-json/wp/v2/posts', $this->calls());
    }

    public function test_loi_401_khi_dang_tu_nhap_khong_tao_bai_moi(): void
    {
        $p = $this->publisher([$this->jr(200, []), $this->jr(401, [])]);
        try {
            $p->publish($this->publication);
            $this->fail();
        } catch (WordPressException $e) {
            $this->assertSame(['GET /wp-json/wp/v2/posts', 'POST /wp-json/wp/v2/posts/77'], $this->calls());
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider("khongCoNhap")]
    public function test_version_khong_co_nhap_hieu_luc_giu_hanh_vi_cu(?string $id, ?string $status): void
    {
        $this->version->update(['cms_draft_id' => $id, 'cms_draft_status' => $status]);
        $p = $this->publisher([$this->jr(200, []), $this->jr(201, $this->wpPost(['id' => 55])), $this->jr(200, $this->wpPost(['id' => 55]))]);
        $r = $p->publish($this->publication->refresh());

        $this->assertSame(['GET /wp-json/wp/v2/posts', 'POST /wp-json/wp/v2/posts', 'GET /wp-json/wp/v2/posts/55'], $this->calls());
        $this->assertNull($r['promoted_draft_id']);
    }

    public static function khongCoNhap(): array
    {
        return [[null, null], ['77', 'cancelled'], ['77', 'failed'], ['77', 'promoted'], ['abc', 'pushed']];
    }

    public function test_huy_nhap_chi_vao_thung_rac_khi_van_la_nhap(): void
    {
        $p = $this->publisher([$this->jr(200, $this->wpPost(['status' => 'draft'])), $this->jr(200, $this->wpPost(['status' => 'trash']))]);
        $p->deleteDraft($this->website, '77');

        $this->assertSame(['GET /wp-json/wp/v2/posts/77', 'DELETE /wp-json/wp/v2/posts/77'], $this->calls());
        $this->assertStringNotContainsString('force', $this->history[1]['request']->getUri()->getQuery());
    }

    public function test_khong_huy_bai_da_cong_khai(): void
    {
        $p = $this->publisher([$this->jr(200, $this->wpPost(['status' => 'publish']))]);
        try {
            $p->deleteDraft($this->website, '77');
            $this->fail();
        } catch (WordPressException $e) {
            $this->assertSame(['GET /wp-json/wp/v2/posts/77'], $this->calls(), 'không gọi DELETE');
        }
    }

    public function test_ma_nhap_sai_dinh_dang_bi_tu_choi_truoc_khi_goi_mang(): void
    {
        $p = $this->publisher([]);
        $this->expectException(WordPressException::class);
        $p->deleteDraft($this->website, '77/../users');
    }
}
