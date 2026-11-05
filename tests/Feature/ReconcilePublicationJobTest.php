<?php

namespace Tests\Feature;

use App\Contracts\PublisherInterface;
use App\Jobs\ReconcilePublicationJob;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use App\Services\Publishing\PublisherManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ReconcilePublicationJobTest extends TestCase
{
    use RefreshDatabase;

    protected Website $website;
    protected Publication $publication;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::create([
            'name' => 'Admin Dược',
            'email' => 'admin_reconcile@duocaddp.com',
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

        $article = Article::create([
            'website_id' => $this->website->id,
            'created_by' => $user->id,
            'primary_keyword' => 'đối soát SEO',
            'search_intent' => 'informational',
            'topic_cluster' => 'Dược phẩm',
            'workflow_status' => 'approved',
        ]);

        $version = ArticleVersion::create([
            'article_id' => $article->id,
            'version' => 1,
            'title' => 'Bài viết cần đối soát',
            'slug' => 'bai-viet-can-doi-soat',
            'html_content' => '<p>Nội dung cần đối soát...</p>',
            'review_status' => 'approved',
        ]);

        $this->publication = Publication::create([
            'website_id' => $this->website->id,
            'article_version_id' => $version->id,
            'publication_key' => 'pub_reconcile_key_999',
            'scheduled_at_utc' => now()->subMinutes(10),
            'timezone' => 'Asia/Ho_Chi_Minh',
            'status' => 'needs_reconcile',
            'last_error' => 'Connection timeout',
        ]);
    }

    public function test_it_reconciles_and_marks_published_when_remote_post_exists(): void
    {
        $mockPublisher = Mockery::mock(PublisherInterface::class);
        $mockPublisher->shouldReceive('getByPublicationKey')
            ->with(Mockery::type(Website::class), 'pub_reconcile_key_999')
            ->once()
            ->andReturn([
                'remote_id' => 777,
                'published_url' => 'https://duocaddp.com/blog/bai-viet-doi-soat',
            ]);

        $manager = new PublisherManager();
        $manager->registerPublisher('magento2', $mockPublisher);

        $job = new ReconcilePublicationJob($this->publication);
        $job->handle($manager);

        $this->publication->refresh();
        $this->assertEquals('published', $this->publication->status);
        $this->assertEquals('777', $this->publication->cms_post_id);
        $this->assertEquals('https://duocaddp.com/blog/bai-viet-doi-soat', $this->publication->published_url);
        $this->assertNull($this->publication->last_error);
    }

    public function test_it_reconciles_and_marks_failed_when_remote_post_does_not_exist(): void
    {
        $mockPublisher = Mockery::mock(PublisherInterface::class);
        $mockPublisher->shouldReceive('getByPublicationKey')
            ->with(Mockery::type(Website::class), 'pub_reconcile_key_999')
            ->once()
            ->andReturn(null);

        $manager = new PublisherManager();
        $manager->registerPublisher('magento2', $mockPublisher);

        $job = new ReconcilePublicationJob($this->publication);
        $job->handle($manager);

        $this->publication->refresh();
        $this->assertEquals('failed', $this->publication->status);
        $this->assertStringContainsString('chưa được ghi nhận trên CMS', $this->publication->last_error);
    }
}
