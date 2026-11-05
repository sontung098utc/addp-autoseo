<?php

namespace Tests\Feature;

use App\Integrations\Magento\MagentoBridgeClient;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\Website;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
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
}
