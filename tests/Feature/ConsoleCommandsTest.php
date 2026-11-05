<?php

namespace Tests\Feature;

use App\Jobs\PublishArticleJob;
use App\Jobs\ReconcilePublicationJob;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ConsoleCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected Website $website;
    protected ArticleVersion $version;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::create([
            'name' => 'Admin Lập Lịch',
            'email' => 'admin_sched@duocaddp.com',
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
            'primary_keyword' => 'lập lịch SEO',
            'search_intent' => 'informational',
            'topic_cluster' => 'Dược phẩm',
            'workflow_status' => 'approved',
        ]);

        $this->version = ArticleVersion::create([
            'article_id' => $article->id,
            'version' => 1,
            'title' => 'Lập lịch xuất bản tự động',
            'slug' => 'lap-lich-xuat-ban-tu-dong',
            'html_content' => '<p>Nội dung...</p>',
            'review_status' => 'approved',
        ]);
    }

    public function test_publish_due_posts_command_dispatches_due_publications(): void
    {
        Queue::fake();

        // 1 bài đến hạn
        $due = Publication::create([
            'website_id' => $this->website->id,
            'article_version_id' => $this->version->id,
            'publication_key' => 'pub_due_1',
            'scheduled_at_utc' => now()->subMinute(),
            'timezone' => 'Asia/Ho_Chi_Minh',
            'status' => 'scheduled',
        ]);

        // 1 bài chưa đến hạn (tương lai)
        $future = Publication::create([
            'website_id' => $this->website->id,
            'article_version_id' => $this->version->id,
            'publication_key' => 'pub_future_2',
            'scheduled_at_utc' => now()->addHour(),
            'timezone' => 'Asia/Ho_Chi_Minh',
            'status' => 'scheduled',
        ]);

        $this->artisan('autoseo:publish-due-posts')
            ->expectsOutputToContain('Tìm thấy 1 bài viết đến hạn xuất bản')
            ->assertSuccessful();

        $this->assertEquals('publishing', $due->fresh()->status);
        $this->assertEquals('scheduled', $future->fresh()->status);

        Queue::assertPushed(PublishArticleJob::class, 1);
    }

    public function test_reconcile_publications_command_dispatches_pending_reconcile(): void
    {
        Queue::fake();

        $reconcile = Publication::create([
            'website_id' => $this->website->id,
            'article_version_id' => $this->version->id,
            'publication_key' => 'pub_needs_reconcile_1',
            'scheduled_at_utc' => now()->subMinutes(5),
            'timezone' => 'Asia/Ho_Chi_Minh',
            'status' => 'needs_reconcile',
            'last_error' => 'cURL timeout',
        ]);

        $this->artisan('autoseo:reconcile-publications')
            ->expectsOutputToContain('Tìm thấy 1 bài viết cần đối soát')
            ->assertSuccessful();

        Queue::assertPushed(ReconcilePublicationJob::class, 1);
    }
}
