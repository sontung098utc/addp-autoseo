<?php

namespace Tests\Feature;

use App\Jobs\PublishArticleJob;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\Website;
use App\Services\Publishing\PublicationStaleReaper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicationStaleReaperTest extends TestCase
{
    use RefreshDatabase;

    private function make(string $status, int $attempts, int $minutesAgo): Publication
    {
        $site = Website::create(['name' => 'S', 'cms_type' => 'magento2', 'base_url' => 'https://shop.company.vn', 'encrypted_credentials' => ['a' => 'b'], 'status' => 'active']);
        $article = Article::create(['website_id' => $site->id, 'primary_keyword' => 'k', 'search_intent' => 'informational', 'workflow_status' => 'approved']);
        $v = ArticleVersion::create(['article_id' => $article->id, 'version' => 1, 'title' => 't', 'slug' => 's', 'html_content' => '<p>x</p>', 'review_status' => 'approved']);
        $p = Publication::create([
            'website_id' => $site->id, 'article_version_id' => $v->id, 'publication_key' => 'k-'.uniqid(),
            'scheduled_at_utc' => now()->subDay(), 'timezone' => 'Asia/Ho_Chi_Minh', 'status' => $status, 'attempt_count' => $attempts,
        ]);
        DB::table('publications')->where('id', $p->id)->update(['updated_at' => now()->subMinutes($minutesAgo)]);

        return $p->fresh();
    }

    public function test_stuck_with_zero_attempts_becomes_failed_with_reason(): void
    {
        $p = $this->make('publishing', 0, 120);
        $this->assertSame(1, PublicationStaleReaper::reap(15));
        $p->refresh();
        $this->assertSame('failed', $p->status);
        $this->assertStringContainsString('0 lần thử', $p->last_error);
    }

    public function test_stuck_after_sending_becomes_needs_reconcile_not_retry(): void
    {
        $p = $this->make('publishing', 2, 120);
        PublicationStaleReaper::reap(15);
        $this->assertSame('needs_reconcile', $p->fresh()->status);
    }

    public function test_recent_publishing_is_left_alone(): void
    {
        $p = $this->make('publishing', 0, 3);
        $this->assertSame(0, PublicationStaleReaper::reap(15));
        $this->assertSame('publishing', $p->fresh()->status);
    }

    public function test_command_runs(): void
    {
        $p = $this->make('publishing', 0, 120);
        $this->artisan('autoseo:reap-stale-publications')->assertSuccessful();
        $this->assertSame('failed', $p->fresh()->status);
    }

    public function test_dead_job_never_sent_goes_back_to_scheduled(): void
    {
        $p = $this->make('publishing', 0, 1);
        (new PublishArticleJob($p))->failed(new \RuntimeException('x'));
        $this->assertSame('scheduled', $p->fresh()->status);
    }

    public function test_dead_job_after_send_needs_reconcile(): void
    {
        $p = $this->make('publishing', 1, 1);
        (new PublishArticleJob($p))->failed();
        $this->assertSame('needs_reconcile', $p->fresh()->status);
    }
}
