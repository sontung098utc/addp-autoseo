<?php

namespace Tests\Feature;

use App\Filament\Support\Audit\AuditAction;
use App\Filament\Support\Audit\AuditRecorder;
use App\Filament\Support\Audit\DatabaseAuditSink;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteConnectionCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigrationsLotATest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $writer;
    protected Website $website;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin_test_' . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->writer = User::create([
            'name' => 'Writer User',
            'email' => 'writer_test_' . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'role' => 'writer',
            'is_active' => true,
        ]);

        $this->website = Website::create([
            'name' => 'Demo Site',
            'base_url' => 'https://example.com',
            'cms_type' => 'wordpress',
            'status' => 'active',
            'encrypted_credentials' => [
                'username' => 'test',
                'application_password' => 'secret',
            ],
            'timezone' => 'Asia/Ho_Chi_Minh',
        ]);
    }

    public function test_campaign_can_be_created_and_has_proper_relationships(): void
    {
        $campaign = Campaign::create([
            'website_id' => $this->website->id,
            'created_by' => $this->admin->id,
            'name' => 'Chiến dịch SEO Quý 4',
            'status' => Campaign::STATUS_ACTIVE,
            'target_keywords' => ['seo tu dong', 'magento auto post'],
            'start_date' => '2026-11-01',
            'end_date' => '2026-12-31',
            'budget_limit' => 5000000.00,
            'settings' => ['interval_days' => 2],
        ]);

        $this->assertDatabaseHas('campaigns', [
            'id' => $campaign->id,
            'name' => 'Chiến dịch SEO Quý 4',
            'status' => 'active',
        ]);

        $this->assertTrue($campaign->website->is($this->website));
        $this->assertTrue($campaign->creator->is($this->admin));
        $this->assertCount(1, $this->website->campaigns);
        $this->assertSame(['seo tu dong', 'magento auto post'], $campaign->target_keywords);
    }

    public function test_article_supports_campaign_id_and_approved_version_id(): void
    {
        $campaign = Campaign::create([
            'website_id' => $this->website->id,
            'created_by' => $this->admin->id,
            'name' => 'Chiến dịch Content',
            'status' => Campaign::STATUS_DRAFT,
        ]);

        $article = Article::create([
            'website_id' => $this->website->id,
            'campaign_id' => $campaign->id,
            'created_by' => $this->writer->id,
            'title' => 'Bài viết mẫu chiến dịch',
            'primary_keyword' => 'tu khoa mau',
            'status' => 'draft',
        ]);

        $version = ArticleVersion::create([
            'article_id' => $article->id,
            'version' => 1,
            'title' => 'Bài viết mẫu v1',
            'slug' => 'bai-viet-mau-v1',
            'html_content' => '<p>Nội dung mẫu</p>',
            'meta_description' => 'Mô tả ngắn chuẩn SEO cho bài viết mẫu',
            'outline' => ['mục 1', 'mục 2'],
        ]);

        $article->update([
            'approved_version_id' => $version->id,
            'status' => 'approved',
        ]);

        $article->refresh();

        $this->assertTrue($article->campaign->is($campaign));
        $this->assertTrue($article->approvedVersion->is($version));
        $this->assertCount(1, $campaign->articles);
    }

    public function test_publication_supports_held_status(): void
    {
        $article = Article::create([
            'website_id' => $this->website->id,
            'created_by' => $this->writer->id,
            'title' => 'Bài viết xuất bản',
            'primary_keyword' => 'tu khoa xuat ban',
            'status' => 'approved',
        ]);

        $version = ArticleVersion::create([
            'article_id' => $article->id,
            'version' => 1,
            'title' => 'Bài viết v1',
            'slug' => 'bai-viet-v1',
            'html_content' => '<p>Nội dung</p>',
            'meta_description' => 'Mô tả ngắn chuẩn SEO',
        ]);

        $publication = Publication::create([
            'website_id' => $this->website->id,
            'article_version_id' => $version->id,
            'publication_key' => 'pub_held_test_001',
            'status' => Publication::STATUS_HELD,
            'scheduled_at_utc' => now('UTC')->addDay(),
            'timezone' => 'Asia/Ho_Chi_Minh',
        ]);

        $this->assertDatabaseHas('publications', [
            'id' => $publication->id,
            'status' => 'held',
            'publication_key' => 'pub_held_test_001',
        ]);

        $this->assertTrue($publication->isHeld());
        $this->assertFalse($publication->needsReconcile());
    }

    public function test_audit_logs_can_be_written_via_database_audit_sink(): void
    {
        $article = Article::create([
            'website_id' => $this->website->id,
            'created_by' => $this->writer->id,
            'title' => 'Bài duyệt audit',
            'primary_keyword' => 'tu khoa audit',
            'status' => 'submitted',
        ]);

        $version = ArticleVersion::create([
            'article_id' => $article->id,
            'version' => 1,
            'title' => 'Bài duyệt v1',
            'slug' => 'bai-duyet-v1',
            'html_content' => '<p>Duyệt bài</p>',
            'meta_description' => 'Mô tả ngắn chuẩn SEO',
        ]);

        $sink = new DatabaseAuditSink();
        $recorder = new AuditRecorder($sink);

        $entry = $recorder->record(
            AuditAction::Approve,
            $this->admin,
            $article,
            $version,
            'Đạt yêu cầu SEO và nội dung'
        );

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::Approve->value,
            'actor_id' => $this->admin->id,
            'article_id' => $article->id,
            'article_version_id' => $version->id,
            'reason' => 'Đạt yêu cầu SEO và nội dung',
        ]);

        $log = AuditLog::first();
        $this->assertNotNull($log);
        $this->assertTrue($log->actor->is($this->admin));
        $this->assertTrue($log->article->is($article));
        $this->assertTrue($log->articleVersion->is($version));
    }

    public function test_website_connection_checks_records_health_history(): void
    {
        $check = WebsiteConnectionCheck::create([
            'website_id' => $this->website->id,
            'is_success' => true,
            'http_status' => 200,
            'response_time_ms' => 145,
            'checked_at' => now('UTC'),
        ]);

        $this->assertDatabaseHas('website_connection_checks', [
            'id' => $check->id,
            'website_id' => $this->website->id,
            'is_success' => 1,
            'http_status' => 200,
        ]);

        $this->assertTrue($check->website->is($this->website));
        $this->assertCount(1, $this->website->connectionChecks);
    }
}
