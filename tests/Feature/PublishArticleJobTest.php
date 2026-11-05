<?php

namespace Tests\Feature;

use App\Contracts\PublisherInterface;
use App\Jobs\PublishArticleJob;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use App\Services\Publishing\PublisherManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class PublishArticleJobTest extends TestCase
{
    use RefreshDatabase;

    protected Website $website;
    protected Article $article;
    protected ArticleVersion $version;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::create([
            'name' => 'Admin Dược',
            'email' => 'admin_test@duocaddp.com',
            'password' => bcrypt('password123'),
            'role' => 'admin',
        ]);

        $this->website = Website::create([
            'name' => 'Công ty Dược ADDP',
            'cms_type' => 'magento2',
            'base_url' => 'https://duocaddp.com',
            'store_scope' => 'default',
            'encrypted_credentials' => ['consumer_key' => 'key', 'consumer_secret' => 'secret'],
            'status' => 'active',
        ]);

        $this->article = Article::create([
            'website_id' => $this->website->id,
            'created_by' => $user->id,
            'primary_keyword' => 'chăm sóc sức khỏe mùa đông',
            'search_intent' => 'informational',
            'topic_cluster' => 'Dược phẩm',
            'workflow_status' => 'approved',
        ]);

        $this->version = ArticleVersion::create([
            'article_id' => $this->article->id,
            'version' => 1,
            'title' => 'Cẩm nang chăm sóc sức khỏe mùa đông',
            'slug' => 'cam-nang-cham-soc-suc-khoe-mua-dong',
            'html_content' => '<p>Nội dung bài viết chuẩn SEO dược phẩm...</p>',
            'meta_description' => 'Hướng dẫn chi tiết bảo vệ sức khỏe mùa đông cho cả gia đình.',
            'review_status' => 'approved',
            'tokens_used' => 500,
            'cost_vnd' => 50.00,
        ]);
    }

    public function test_it_publishes_article_successfully(): void
    {
        $publication = Publication::create([
            'website_id' => $this->website->id,
            'article_version_id' => $this->version->id,
            'publication_key' => 'pub_test_success_123',
            'scheduled_at_utc' => now()->subMinute(),
            'timezone' => 'Asia/Ho_Chi_Minh',
            'status' => 'scheduled',
        ]);

        $mockPublisher = Mockery::mock(PublisherInterface::class);
        $mockPublisher->shouldReceive('publish')
            ->once()
            ->andReturn([
                'remote_id' => 888,
                'published_url' => 'https://duocaddp.com/blog/suc-khoe-mua-dong',
            ]);

        $manager = new PublisherManager();
        $manager->registerPublisher('magento2', $mockPublisher);

        $job = new PublishArticleJob($publication);
        $job->handle($manager);

        $publication->refresh();
        $this->assertEquals('published', $publication->status);
        $this->assertEquals('888', $publication->cms_post_id);
        $this->assertEquals('https://duocaddp.com/blog/suc-khoe-mua-dong', $publication->published_url);
        $this->assertNotNull($publication->published_at_utc);
        $this->assertEquals(1, $publication->attempt_count);
    }

    public function test_it_does_not_republish_if_already_published(): void
    {
        $publication = Publication::create([
            'website_id' => $this->website->id,
            'article_version_id' => $this->version->id,
            'publication_key' => 'pub_test_already_done',
            'scheduled_at_utc' => now()->subHour(),
            'timezone' => 'Asia/Ho_Chi_Minh',
            'status' => 'published',
            'cms_post_id' => '100',
        ]);

        $mockPublisher = Mockery::mock(PublisherInterface::class);
        $mockPublisher->shouldNotReceive('publish');

        $manager = new PublisherManager();
        $manager->registerPublisher('magento2', $mockPublisher);

        $job = new PublishArticleJob($publication);
        $job->handle($manager);

        $this->assertEquals('published', $publication->fresh()->status);
    }

    public function test_it_transitions_to_needs_reconcile_on_connection_timeout(): void
    {
        $publication = Publication::create([
            'website_id' => $this->website->id,
            'article_version_id' => $this->version->id,
            'publication_key' => 'pub_test_timeout_456',
            'scheduled_at_utc' => now()->subMinute(),
            'timezone' => 'Asia/Ho_Chi_Minh',
            'status' => 'scheduled',
        ]);

        $mockPublisher = Mockery::mock(PublisherInterface::class);
        $mockPublisher->shouldReceive('publish')
            ->once()
            ->andThrow(new ConnectionException('cURL error 28: Operation timed out after 30000 milliseconds'));

        $manager = new PublisherManager();
        $manager->registerPublisher('magento2', $mockPublisher);

        $job = new PublishArticleJob($publication);
        $job->handle($manager);

        $publication->refresh();
        $this->assertEquals('needs_reconcile', $publication->status);
        $this->assertStringContainsString('Operation timed out', $publication->last_error);
    }

    public function test_it_transitions_to_needs_reconcile_on_504_gateway_timeout(): void
    {
        $publication = Publication::create([
            'website_id' => $this->website->id,
            'article_version_id' => $this->version->id,
            'publication_key' => 'pub_test_504_gateway',
            'scheduled_at_utc' => now()->subMinute(),
            'timezone' => 'Asia/Ho_Chi_Minh',
            'status' => 'scheduled',
        ]);

        $psrResponse = new \GuzzleHttp\Psr7\Response(504, [], 'Gateway Timeout');
        $response = new Response($psrResponse);

        $mockPublisher = Mockery::mock(PublisherInterface::class);
        $mockPublisher->shouldReceive('publish')
            ->once()
            ->andThrow(new RequestException($response));

        $manager = new PublisherManager();
        $manager->registerPublisher('magento2', $mockPublisher);

        $job = new PublishArticleJob($publication);
        $job->handle($manager);

        $publication->refresh();
        $this->assertEquals('needs_reconcile', $publication->status);
        $this->assertStringContainsString('504', $publication->last_error);
    }

    public function test_it_respects_atomic_site_lock(): void
    {
        $publication = Publication::create([
            'website_id' => $this->website->id,
            'article_version_id' => $this->version->id,
            'publication_key' => 'pub_test_lock_busy',
            'scheduled_at_utc' => now()->subMinute(),
            'timezone' => 'Asia/Ho_Chi_Minh',
            'status' => 'scheduled',
        ]);

        // Giả lập một job khác đang chiếm khóa site
        $lockKey = "publish_lock_site_{$this->website->id}";
        $lock = Cache::lock($lockKey, 60);
        $lock->get();

        $mockPublisher = Mockery::mock(PublisherInterface::class);
        $mockPublisher->shouldNotReceive('publish');

        $manager = new PublisherManager();
        $manager->registerPublisher('magento2', $mockPublisher);

        $job = Mockery::mock(PublishArticleJob::class, [$publication])->makePartial();
        $job->shouldReceive('release')->with(15)->once();

        $job->handle($manager);

        // Trạng thái publication không bị thay đổi vì site bận
        $this->assertEquals('scheduled', $publication->fresh()->status);

        $lock->release();
    }
}
