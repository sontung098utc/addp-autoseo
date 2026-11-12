<?php

namespace Tests\Feature\Agent;

use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Website;
use App\Services\Agent\Run\StaleRunReaper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaleRunReaperTest extends TestCase
{
    use RefreshDatabase;

    private function mkRun(Article $a, string $status, int $minAgo): AgentRun
    {
        $run = AgentRun::create(['website_id' => $a->website_id, 'article_id' => $a->id, 'triggered_by' => null, 'trigger' => 'manual', 'status' => $status, 'started_at' => now()->subMinutes($minAgo)]);
        $run->forceFill(['created_at' => now()->subMinutes($minAgo)])->save();
        AgentRunStep::create(['agent_run_id' => $run->id, 'seq' => 1, 'stage' => 'write', 'role' => 'writer', 'status' => 'ok', 'title' => 'Viết', 'data' => []])->forceFill(['created_at' => now()->subMinutes($minAgo)])->save();

        return $run;
    }

    private function article(string $kw, string $state): Article
    {
        $site = Website::firstOrCreate(['name' => 'S'], ['cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);

        return Article::create(['website_id' => $site->id, 'primary_keyword' => $kw, 'workflow_status' => $state]);
    }

    public function test_closes_stale_run_and_restores_stuck_drafting_articles(): void
    {
        $stuck = $this->article('a', 'drafting');
        $dead = $this->mkRun($stuck, 'running', 120);
        $withDraft = $this->article('b', 'drafting');
        ArticleVersion::create(['article_id' => $withDraft->id, 'version' => 1, 'title' => 't', 'slug' => 't', 'html_content' => '<p>x</p>', 'review_status' => 'needs_review']);
        $this->mkRun($withDraft, 'failed', 130);
        $alive = $this->article('c', 'drafting');
        $liveRun = $this->mkRun($alive, 'running', 5);

        $r = StaleRunReaper::reap();

        $this->assertSame(1, $r['runs']);
        $this->assertSame('failed', $dead->fresh()->status);
        $this->assertStringContainsString('tự đóng phiên', $dead->fresh()->outcome_reason);
        $this->assertSame('planned', $stuck->fresh()->workflow_status);
        $this->assertSame('needs_review', $withDraft->fresh()->workflow_status);
        $this->assertSame('running', $liveRun->fresh()->status);
        $this->assertSame('drafting', $alive->fresh()->workflow_status);
    }
}
