<?php

namespace Tests\Feature;

use App\Integrations\Magento\MagentoBridgeClient;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\Website;
use GuzzleHttp\Client;
use App\Models\MediaAsset;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use Illuminate\Support\Facades\Storage;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MagentoBridgeClientTest extends TestCase
{
    use RefreshDatabase;

    protected Website $website;
    protected Article $article;
    protected ArticleVersion $version;

    protected function setUp(): void
    {
        parent::setUp();

        $this->website = Website::create([
            'name' => 'Magento 2 Shop',
            'cms_type' => 'magento2',
            'base_url' => 'https://shop.company.vn',
            'store_scope' => 'default',
            'encrypted_credentials' => [
                'consumer_key' => 'ck_test',
                'consumer_secret' => 'cs_test',
                'access_token' => 'at_test',
                'access_token_secret' => 'ats_test',
            ],
            'status' => 'active',
        ]);

        $this->article = Article::create([
            'website_id' => $this->website->id,
            'primary_keyword' => 'áo thun nam cao cấp',
            'search_intent' => 'commercial',
            'workflow_status' => 'approved',
        ]);

        $this->version = ArticleVersion::create([
            'article_id' => $this->article->id,
            'version' => 1,
            'title' => 'Bộ Sưu Tập Áo Thun Nam Cao Cấp 2026',
            'slug' => 'ao-thun-nam-cao-cap-2026',
            'html_content' => '<p>Áo thun nam chất liệu cotton 100%...</p>',
            'seo_title' => 'Áo Thun Nam Cao Cấp 2026',
            'meta_description' => 'Khám phá bộ sưu tập áo thun nam cao cấp 2026.',
            'review_status' => 'approved',
        ]);
    }

    public function test_connection_successful_with_http_200(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([['id' => 1, 'code' => 'base']])),
        ]);
        $client = new MagentoBridgeClient(new Client(['handler' => HandlerStack::create($mock)]));

        $this->assertTrue($client->testConnection($this->website));
    }

    public function test_create_draft_creates_post_and_returns_remote_id(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['post_id' => 99, 'title' => 'Bộ Sưu Tập Áo Thun'])),
        ]);
        $client = new MagentoBridgeClient(new Client(['handler' => HandlerStack::create($mock)]));

        $result = $client->createDraft($this->website, $this->version);

        $this->assertEquals('99', $result['remote_id']);
        $this->assertStringContainsString('blog/post/view/id/99', $result['preview_url']);
        $this->assertNotEmpty($result['publication_key']);
    }

    public function test_get_by_publication_key_reconciles_existing_post(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['post_id' => 99, 'status' => 0, 'title' => 'Đã có'])),
        ]);
        $client = new MagentoBridgeClient(new Client(['handler' => HandlerStack::create($mock)]));

        $post = $client->getByPublicationKey($this->website, 'magento-1-art1-v1');

        $this->assertNotNull($post);
        $this->assertEquals('99', $post['remote_id']);
    }

    public function test_publish_publishes_post_successfully(): void
    {
        $publication = Publication::create([
            'website_id' => $this->website->id,
            'article_version_id' => $this->version->id,
            'publication_key' => 'magento-1-art1-v1',
            'scheduled_at_utc' => now(),
            'status' => 'scheduled',
            'cms_post_id' => '99',
        ]);

        $mock = new MockHandler([
            new Response(200, [], json_encode(['post_id' => 99, 'url_key' => 'ao-thun-nam-cao-cap-2026'])),
        ]);
        $client = new MagentoBridgeClient(new Client(['handler' => HandlerStack::create($mock)]));

        $result = $client->publish($publication);

        $this->assertEquals('99', $result['remote_id']);
        $this->assertEquals('https://shop.company.vn/blog/post/ao-thun-nam-cao-cap-2026', $result['published_url']);
    }

    private function clientWithHistory(array $responses, array &$history): MagentoBridgeClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new MagentoBridgeClient(new Client(['handler' => $stack]));
    }

    private function featuredAsset(string $name = 'cover.webp', ?string $bytes = null): MediaAsset
    {
        Storage::fake('public');
        if ($bytes === null) {
            $im = imagecreatetruecolor(8, 8);
            ob_start();
            str_ends_with($name, '.png') ? imagepng($im) : imagewebp($im);
            $bytes = (string) ob_get_clean();
        }
        Storage::disk('public')->put('media/2026/11/'.$name, $bytes);
        $a = MediaAsset::create(['website_id' => $this->website->id, 'disk' => 'public', 'file_path' => 'media/2026/11/'.$name, 'file_name' => $name, 'original_name' => $name, 'mime_type' => 'image/x', 'file_size' => 1, 'sha256' => str_repeat('a', 64), 'alt_text' => 'Bìa']);
        $this->version->mediaAssets()->attach($a->id, ['is_featured' => true, 'position' => 0]);

        return $a;
    }

    /** Thumbnail ở trang danh sách blog: ảnh bìa tải lên Magento, đường dẫn trả về gửi kèm bài (trường image của module), nhớ ở metadata để không tải lại. */
    public function test_create_draft_uploads_featured_image_and_sends_image_field(): void
    {
        $asset = $this->featuredAsset();
        $h = [];
        $client = $this->clientWithHistory([
            new Response(200, [], json_encode(['id' => 'a.webp', 'file' => 'magefan_blog/autoseo/1_cover.jpg', 'url' => '/media/magefan_blog/autoseo/1_cover.jpg'])),
            new Response(200, [], json_encode(['post_id' => 99])),
        ], $h);

        $client->createDraft($this->website, $this->version);

        $this->assertStringEndsWith('/V1/company-blog/media', (string) $h[0]['request']->getUri());
        $this->assertSame('cover.jpg', json_decode((string) $h[0]['request']->getBody(), true)['fileName']);
        $post = json_decode((string) $h[1]['request']->getBody(), true)['post'];
        $this->assertSame('magefan_blog/autoseo/1_cover.jpg', $post['image']);
        $this->assertSame('magefan_blog/autoseo/1_cover.jpg', $asset->fresh()->metadata['magento_media'][(string) $this->website->id]);

        // Lần sau (đăng sau nháp) dùng lại đường dẫn đã nhớ, không tải lại.
        $h2 = [];
        $this->clientWithHistory([new Response(200, [], json_encode(['post_id' => 99]))], $h2)->createDraft($this->website, $this->version->fresh());
        $this->assertCount(1, $h2);
        $this->assertSame('magefan_blog/autoseo/1_cover.jpg', json_decode((string) $h2[0]['request']->getBody(), true)['post']['image']);
    }

    public function test_create_draft_without_image_or_with_upload_failure_still_creates_post(): void
    {
        $h = [];
        $this->clientWithHistory([new Response(200, [], json_encode(['post_id' => 1]))], $h)->createDraft($this->website, $this->version);
        $this->assertArrayNotHasKey('image', json_decode((string) $h[0]['request']->getBody(), true)['post']);

        $this->featuredAsset();
        $h = [];
        $r = $this->clientWithHistory([new Response(500, [], '{}'), new Response(200, [], json_encode(['post_id' => 2]))], $h)->createDraft($this->website, $this->version);
        $this->assertSame('2', $r['remote_id']);
        $this->assertArrayNotHasKey('image', json_decode((string) $h[1]['request']->getBody(), true)['post']);
    }

    /** Magento (GD2) không resize được WebP: bìa WebP đổi sang JPEG (đuôi .jpg) trước khi tải; PNG giữ nguyên; WebP hỏng thì bỏ ảnh bìa, bài vẫn tạo. */
    public function test_cover_webp_is_converted_to_jpeg_png_kept_and_corrupt_webp_skipped(): void
    {
        $this->featuredAsset('cover.webp');
        $h = [];
        $this->clientWithHistory([new Response(200, [], json_encode(['file' => 'magefan_blog/autoseo/1_cover.jpg'])), new Response(200, [], json_encode(['post_id' => 1]))], $h)->createDraft($this->website, $this->version);
        $up = json_decode((string) $h[0]['request']->getBody(), true);
        $this->assertSame('cover.jpg', $up['fileName']);
        $this->assertSame("\xFF\xD8\xFF", substr((string) base64_decode($up['base64Content']), 0, 3), 'nội dung tải lên là JPEG');
        $this->assertSame([], glob(sys_get_temp_dir().'/magento-cover-*') ?: [], 'không để thư mục tạm');

        $this->version->mediaAssets()->detach();
        $this->featuredAsset('cover.png');
        $h = [];
        $this->clientWithHistory([new Response(200, [], json_encode(['file' => 'magefan_blog/autoseo/2_cover.png'])), new Response(200, [], json_encode(['post_id' => 2]))], $h)->createDraft($this->website, $this->version->fresh());
        $this->assertSame('cover.png', json_decode((string) $h[0]['request']->getBody(), true)['fileName']);

        $this->version->mediaAssets()->detach();
        $this->featuredAsset('bad.webp', 'not a webp');
        $h = [];
        $r = $this->clientWithHistory([new Response(200, [], json_encode(['post_id' => 3]))], $h)->createDraft($this->website, $this->version->fresh());
        $this->assertSame('3', $r['remote_id']);
        $this->assertCount(1, $h);
        $this->assertArrayNotHasKey('image', json_decode((string) $h[0]['request']->getBody(), true)['post']);
    }
}
