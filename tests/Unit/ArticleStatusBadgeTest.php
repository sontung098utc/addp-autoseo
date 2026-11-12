<?php

namespace Tests\Unit;

use App\Filament\Support\Review\ArticleRows;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Article;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArticleStatusBadgeTest extends TestCase
{
    use RefreshDatabase;

    private function make(string $state, ?string $runStatus, int $startedMinAgo = 1): Article
    {
        $site = Website::firstOrCreate(['name' => 'S'], ['cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $a = Article::create(['website_id' => $site->id, 'primary_keyword' => 'kw '.$state.$runStatus.$startedMinAgo, 'workflow_status' => $state]);
        if ($runStatus) {
            $run = AgentRun::create(['website_id' => $site->id, 'article_id' => $a->id, 'triggered_by' => null, 'trigger' => 'manual', 'status' => $runStatus, 'started_at' => now()->subMinutes($startedMinAgo)]);
            $run->forceFill(['created_at' => now()->subMinutes($startedMinAgo), 'updated_at' => now()->subMinutes($startedMinAgo)])->save();
            AgentRunStep::create(['agent_run_id' => $run->id, 'seq' => 1, 'stage' => 'write', 'role' => 'writer', 'status' => 'running', 'title' => 'Viết', 'data' => []])
                ->forceFill(['created_at' => now()->subMinutes($startedMinAgo)])->save();
        }

        return $a;
    }

    public function test_live_run_wins_over_review_status(): void
    {
        [$label, $color] = ArticleRows::statusBadge($this->make('needs_review', 'running'));
        $this->assertStringStartsWith('Đang viết lại', $label);
        $this->assertSame('info', $color);
        $this->assertSame('Đang viết lại', ArticleRows::statusBadge($this->make('needs_review', 'running', 2), false)[0]);
        $this->assertStringStartsWith('Đang viết ·', ArticleRows::statusBadge($this->make('drafting', 'running', 3))[0]);
    }

    public function test_stale_run_and_failed_drafting_are_explicit(): void
    {
        $this->assertSame(['Có thể bị treo', 'warning'], ArticleRows::statusBadge($this->make('drafting', 'running', 90)));
        $this->assertSame(['Phiên lỗi, chưa có bản nháp', 'danger'], ArticleRows::statusBadge($this->make('drafting', 'failed')));
        $this->assertSame('Chờ duyệt', ArticleRows::statusBadge($this->make('needs_review', null))[0]);
    }

    public function test_cannot_approve_or_reject_while_ai_is_rewriting(): void
    {
        $a = $this->make('needs_review', 'running');
        $v = \App\Models\ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 't', 'slug' => 't', 'html_content' => '<p>x</p>', 'review_status' => 'needs_review']);
        $admin = \App\Models\User::create(['name' => 'a', 'email' => 'ad@x.vn', 'password' => 'secret123', 'role' => 'admin', 'is_active' => true]);
        $wf = new \App\Filament\Support\Review\ReviewWorkflow;
        foreach ([fn () => $wf->approve($admin, $v), fn () => $wf->reject($admin, $v, 'lý do')] as $call) {
            try {
                $call();
                $this->fail('Phải chặn khi đội AI đang viết lại.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('đang viết lại', $e->getMessage());
            }
        }
    }
}
