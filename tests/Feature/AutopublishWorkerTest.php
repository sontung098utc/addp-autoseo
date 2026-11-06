<?php

namespace Tests\Feature;

use App\Contracts\PublisherInterface;
use App\Integrations\Magento\MagentoBridgeClient;
use App\Integrations\WordPress\WordPressPublisher;
use App\Jobs\PublishArticleJob;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use App\Services\Publishing\EmergencyStopService;
use App\Services\Publishing\PublisherManager;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class AutopublishWorkerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Website $wpWebsite;
    protected Website $magentoWebsite;
    protected Article $wpArticle;
    protected Article $magentoArticle;
    protected ArticleVersion $wpVersion;
    protected ArticleVersion $magentoVersion;
    protected EmergencyStopService $emergencyStop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->emergencyStop = app(EmergencyStopService::class);
        $this->emergencyStop->resetForTesting();

        $this->admin = User::create([
            'name' => 'Admin Autopublish',
            'email' => 'admin_auto@test.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        // WordPress site
        $this->wpWebsite = Website::create([
            'name' => 'Tin Tức Dược WP',
            'cms_type' => 'wordpress',
            'base_url' => 'https://blog.test-company.com',
            'encrypted_credentials' => ['username' => 'admin', 'application_password' => 'secret'],
            'status' => 'active',
        ]);

        // Magento site
        $this->magentoWebsite = Website::create([
            'name' => 'Cửa Hàng Dược M2',
            'cms_type' => 'magento2',
            'base_url' => 'https://shop.test-company.com',
            'store_scope' => 'default',
            'encrypted_credentials' => ['consumer_key' => 'ck', 'consumer_secret' => 'cs', 'access_token' => 'at', 'access_token_secret' => 'ats'],
            'status' => 'active',
        ]);

        // Articles & Versions
        $this->wpArticle = Article::create([
            'website_id' => $this->wpWebsite->id,
            'created_by' => $this->admin->id,
            'primary_keyword' => 'thuốc ho thảo dược',
            'search_intent' => 'informational',
            'workflow_status' => 'approved',
        ]);

        $this->wpVersion = ArticleVersion::create([
            'article_id' => $this->wpArticle->id,
            'version' => 1,
            'title' => 'Top Thuốc Ho Thảo Dược An Toàn 2026',
            'slug' => 'top-thuoc-ho-thao-duoc-2026',
            'html_content' => '<p>Các vị thảo dược trị ho tự nhiên...</p>',
            'review_status' => 'approved',
        ]);

        $this->magentoArticle = Article::create([
            'website_id' => $this->magentoWebsite->id,
            'created_by' => $this->admin->id,
            'primary_keyword' => 'siro ho bổ phế',
            'search_intent' => 'commercial',
            'workflow_status' => 'approved',
        ]);

        $this->magentoVersion = ArticleVersion::create([
            'article_id' => $this->magentoArticle->id,
            'version' => 1,
            'title' => 'Siro Ho Bổ Phế Cao Cấp',
            'slug' => 'siro-ho-bo-phe-cao-cap',
            'html_content' => '<p>Sản phẩm siro ho chuẩn GMP...</p>',
            'review_status' => 'approved',
        ]);
    }

    protected function tearDown(): void
    {
        $this->emergencyStop->resetForTesting();
        parent::tearDown();
    }

    public function test_autopublish_worker_dispatches_due_posts_for_both_cms(): void
    {
        Bus::fake([PublishArticleJob::class]);

        // Tạo 2 bài đến hạn (1 WP, 1 Magento)
        $pubWp = Publication::create([
            'website_id' => $this->wpWebsite->id,
            'article_version_id' => $this->wpVersion->id,
            'publication_key' => 'wp-due-001',
            'scheduled_at_utc' => now()->subMinutes(5),
            'timezone' => 'Asia/Ho_Chi_Minh',
            'status' => 'scheduled',
        ]);

        $pubM2 = Publication::create([
            'website_id' => $this->magentoWebsite->id,
            'article_version_id' => $this->magentoVersion->id,
            'publication_key' => 'm2-due-002',
            'scheduled_at_utc' => now()->subMinutes(2),
            'timezone' => 'Asia/Ho_Chi_Minh',
            'status' => 'scheduled',
        ]);

        // Tạo 1 bài chưa đến hạn (tương lai)
        $pubFuture = Publication::create([
            'website_id' => $this->wpWebsite->id,
            'article_version_id' => $this->wpVersion->id,
            'publication_key' => 'wp-future-003',
            'scheduled_at_utc' => now()->addHours(3),
            'timezone' => 'Asia/Ho_Chi_Minh',
            'status' => 'scheduled',
        ]);

        $this->artisan('autoseo:publish-due-posts')
            ->expectsOutputToContain('Tìm thấy 2 bài viết đến hạn xuất bản')
            ->expectsOutputToContain('Hoàn tất đẩy 2/2 bài vào hàng đợi')
            ->assertSuccessful();

        Bus::assertDispatched(PublishArticleJob::class, 2);

        $this->assertEquals('publishing', $pubWp->fresh()->status);
        $this->assertEquals('publishing', $pubM2->fresh()->status);
        $this->assertEquals('scheduled', $pubFuture->fresh()->status);
    }

    public function test_autopublish_worker_skips_when_global_emergency_stop_is_active(): void
    {
        Bus::fake([PublishArticleJob::class]);

        Publication::create([
            'website_id' => $this->wpWebsite->id,
            'article_version_id' => $this->wpVersion->id,
            'publication_key' => 'wp-stopped-001',
            'scheduled_at_utc' => now()->subMinutes(5),
            'timezone' => 'Asia/Ho_Chi_Minh',
            'status' => 'scheduled',
        ]);

        // Kích hoạt dừng khẩn cấp toàn hệ thống
        $this->emergencyStop->stop($this->admin, 'Bảo trì hệ thống toàn diện');

        $this->artisan('autoseo:publish-due-posts')
            ->expectsOutputToContain('[DỪNG KHẨN CẤP] Toàn bộ hệ thống đang tạm dừng xuất bản tự động')
            ->assertSuccessful();

        Bus::assertNotDispatched(PublishArticleJob::class);
    }

    public function test_autopublish_worker_skips_only_stopped_site(): void
    {
        Bus::fake([PublishArticleJob::class]);

        $pubWp = Publication::create([
            'website_id' => $this->wpWebsite->id,
            'article_version_id' => $this->wpVersion->id,
            'publication_key' => 'wp-due-active',
            'scheduled_at_utc' => now()->subMinutes(5),
            'timezone' => 'Asia/Ho_Chi_Minh',
            'status' => 'scheduled',
        ]);

        $pubM2 = Publication::create([
            'website_id' => $this->magentoWebsite->id,
            'article_version_id' => $this->magentoVersion->id,
            'publication_key' => 'm2-due-stopped',
            'scheduled_at_utc' => now()->subMinutes(2),
            'timezone' => 'Asia/Ho_Chi_Minh',
            'status' => 'scheduled',
        ]);

        // Chỉ dừng site Magento
        $this->emergencyStop->stop($this->admin, 'Magento shop đang nâng cấp module', $this->magentoWebsite->id);

        $this->artisan('autoseo:publish-due-posts')
            ->expectsOutputToContain("Website #{$this->magentoWebsite->id} đang tạm dừng khẩn cấp")
            ->expectsOutputToContain('Hoàn tất đẩy 1/2 bài vào hàng đợi')
            ->assertSuccessful();

        Bus::assertDispatched(PublishArticleJob::class, 1);
        $this->assertEquals('publishing', $pubWp->fresh()->status);
        $this->assertEquals('scheduled', $pubM2->fresh()->status);
    }

    public function test_job_aborts_and_resets_if_emergency_stop_triggered_after_dispatch(): void
    {
        $pub = Publication::create([
            'website_id' => $this->wpWebsite->id,
            'article_version_id' => $this->wpVersion->id,
            'publication_key' => 'wp-job-emergency',
            'scheduled_at_utc' => now()->subMinutes(1),
            'timezone' => 'Asia/Ho_Chi_Minh',
            'status' => 'publishing',
        ]);

        // Kích hoạt dừng khẩn cấp trước khi worker chạy job
        $this->emergencyStop->stop($this->admin, 'Sự cố khẩn cấp lúc chạy job');

        $mockPublisher = Mockery::mock(PublisherInterface::class);
        $mockPublisher->shouldNotReceive('publish');

        $manager = new PublisherManager();
        $manager->registerPublisher('wordpress', $mockPublisher);

        $job = new PublishArticleJob($pub);
        $job->handle($manager, $this->emergencyStop);

        // Bài được trả về trạng thái scheduled
        $pub->refresh();
        $this->assertEquals('scheduled', $pub->status);
        $this->assertStringContainsString('Tạm dừng khẩn cấp', $pub->last_error);
    }

    public function test_magento_publish_uses_provided_publication_key_and_prevents_duplicate_post(): void
    {
        $pubKey = 'm2-idempotency-key-123';
        $pub = Publication::create([
            'website_id' => $this->magentoWebsite->id,
            'article_version_id' => $this->magentoVersion->id,
            'publication_key' => $pubKey,
            'scheduled_at_utc' => now()->subMinute(),
            'timezone' => 'Asia/Ho_Chi_Minh',
            'status' => 'scheduled',
        ]);

        // Lần 1: getByPublicationKey trả về 404 (chưa có) -> tạo draft với $pubKey (post_id: 101) -> publish (status 200)
        $mock1 = new MockHandler([
            new Response(404, [], json_encode(['message' => 'Post not found'])), // getByKey
            new Response(200, [], json_encode(['post_id' => 101, 'publication_key' => $pubKey])), // createDraft
            new Response(200, [], json_encode(['post_id' => 101, 'url_key' => 'siro-ho-bo-phe-cao-cap'])), // publish
        ]);
        $client1 = new MagentoBridgeClient(new Client(['handler' => HandlerStack::create($mock1)]));

        $res1 = $client1->publish($pub);
        $this->assertEquals('101', $res1['remote_id']);

        // Lần 2 (giả lập retry sau khi timeout hoặc gọi lại):
        // getByPublicationKey trả về post_id 101 -> không gọi createDraft mà gọi thẳng publish!
        $mock2 = new MockHandler([
            new Response(200, [], json_encode(['post_id' => 101, 'status' => 0])), // getByKey thấy bài cũ
            new Response(200, [], json_encode(['post_id' => 101, 'url_key' => 'siro-ho-bo-phe-cao-cap'])), // publish
        ]);
        $client2 = new MagentoBridgeClient(new Client(['handler' => HandlerStack::create($mock2)]));

        $res2 = $client2->publish($pub);
        $this->assertEquals('101', $res2['remote_id']);
        $this->assertEquals('https://shop.test-company.com/blog/post/siro-ho-bo-phe-cao-cap', $res2['published_url']);
    }
}
