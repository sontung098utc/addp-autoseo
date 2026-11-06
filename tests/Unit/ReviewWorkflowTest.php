<?php

namespace Tests\Unit;

use App\Filament\Support\Audit\AuditRecorder;
use App\Filament\Support\Audit\AuditSink;
use App\Filament\Support\Review\ReviewWorkflow;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class ReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private object $sink;
    private ReviewWorkflow $wf;
    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sink = new class implements AuditSink {
            public array $entries = [];
            public function write(array $entry): void { $this->entries[] = $entry; }
        };
        $this->wf = new ReviewWorkflow(new AuditRecorder($this->sink));
        $this->site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function user(string $role, bool $active = true): User
    {
        return User::create(['name' => $role, 'email' => uniqid() . '@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => $active]);
    }

    private function make(string $status = 'needs_review', string $vs = 'needs_review', ?User $creator = null): ArticleVersion
    {
        $a = Article::create(['website_id' => $this->site->id, 'created_by' => $creator?->id, 'primary_keyword' => 'k', 'workflow_status' => $status]);

        return ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 't', 'slug' => 's', 'html_content' => '<p>x</p>', 'review_status' => $vs]);
    }

    private function future(): Carbon
    {
        return Carbon::now('Asia/Ho_Chi_Minh')->addDay()->setTime(9, 30);
    }

    public function test_writer_submit_ok_but_cannot_approve_reject_schedule(): void
    {
        $w = $this->user('writer');
        $v = $this->make('planned', 'rejected');
        $this->wf->submitForReview($w, $v);
        $this->assertSame('needs_review', $v->fresh()->article->workflow_status);
        $this->assertCount(1, $this->sink->entries);

        foreach ([fn () => $this->wf->approve($w, $v), fn () => $this->wf->reject($w, $v, 'x'), fn () => $this->wf->schedule($w, $v, $this->future(), $this->site)] as $call) {
            try { $call(); $this->fail('phải bị chặn'); } catch (InvalidArgumentException) {}
        }
        $this->assertSame('needs_review', $v->fresh()->review_status);
        $this->assertCount(1, $this->sink->entries);
    }

    public function test_approver_approves_others_article(): void
    {
        $author = $this->user('writer');
        $v = $this->make(creator: $author);
        $this->wf->approve($this->user('approver'), $v);
        $this->assertSame('approved', $v->fresh()->review_status);
        $this->assertSame('approved', $v->article->fresh()->workflow_status);
        $this->assertNotNull($v->fresh()->reviewed_at);
        $this->assertCount(1, $this->sink->entries);
        $this->assertFalse($this->sink->entries[0]['self_approved']);
    }

    public function test_self_approve_requires_note_and_logs_flag(): void
    {
        $me = $this->user('approver');
        $v = $this->make(creator: $me);
        try { $this->wf->approve($me, $v); $this->fail('phải bị chặn'); } catch (InvalidArgumentException) {}
        $this->assertSame('needs_review', $v->fresh()->review_status);
        $this->assertSame('needs_review', $v->article->fresh()->workflow_status);
        $this->assertCount(0, $this->sink->entries);

        $this->wf->approve($me, $v, 'Tự sửa và tự duyệt');
        $this->assertSame('approved', $v->fresh()->review_status);
        $this->assertTrue($this->sink->entries[0]['self_approved']);
        $this->assertCount(1, $this->sink->entries);
    }

    public function test_reject_requires_reason(): void
    {
        $ap = $this->user('approver');
        $v = $this->make();
        try { $this->wf->reject($ap, $v, '  '); $this->fail('phải bị chặn'); } catch (InvalidArgumentException) {}
        $this->assertSame('needs_review', $v->fresh()->review_status);
        $this->wf->reject($ap, $v, 'Thiếu nguồn');
        $this->assertSame('rejected', $v->fresh()->review_status);
        $this->assertSame('rejected', $v->article->fresh()->workflow_status);
        $this->assertSame('Thiếu nguồn', $v->fresh()->review_notes);
        $this->assertCount(1, $this->sink->entries);
    }

    public function test_invalid_transitions_blocked(): void
    {
        $ap = $this->user('approver');
        $drafting = $this->make('drafting', 'needs_review');
        $this->expectBlocked(fn () => $this->wf->approve($ap, $drafting));
        $unapproved = $this->make('needs_review', 'needs_review');
        $this->expectBlocked(fn () => $this->wf->schedule($ap, $unapproved, $this->future(), $this->site));

        $old = $this->make();
        ArticleVersion::create(['article_id' => $old->article_id, 'version' => 2, 'title' => 't', 'slug' => 's', 'html_content' => '<p>y</p>', 'review_status' => 'needs_review']);
        $this->expectBlocked(fn () => $this->wf->approve($ap, $old));
        $this->assertCount(0, $this->sink->entries);
    }

    public function test_old_version_cannot_be_scheduled_after_new_version(): void
    {
        $ap = $this->user('approver');
        $v = $this->make('approved', 'approved');
        ArticleVersion::create(['article_id' => $v->article_id, 'version' => 2, 'title' => 't', 'slug' => 's', 'html_content' => '<p>y</p>']);
        $this->expectBlocked(fn () => $this->wf->schedule($ap, $v, $this->future(), $this->site));
        $this->assertSame(0, Publication::count());
    }

    public function test_schedule_creates_one_publication_with_utc_and_no_duplicate(): void
    {
        $ap = $this->user('approver');
        $v = $this->make('approved', 'approved');
        $at = $this->future();
        $p = $this->wf->schedule($ap, $v, $at, $this->site);

        $this->assertSame(1, Publication::count());
        $this->assertSame($at->copy()->utc()->format('Y-m-d H:i'), substr($p->fresh()->getRawOriginal('scheduled_at_utc'), 0, 16));
        $this->assertSame('Asia/Ho_Chi_Minh', $p->timezone);
        $this->assertSame('scheduled', $p->status);
        $this->assertSame("pub-{$this->site->id}-a{$v->article_id}-v1", $p->publication_key);
        $this->assertSame('scheduled', $v->article->fresh()->workflow_status);
        $this->assertCount(1, $this->sink->entries);

        $this->expectBlocked(fn () => $this->wf->schedule($ap, $v, $this->future(), $this->site));
        $this->assertSame(1, Publication::count());
        $this->assertCount(1, $this->sink->entries);
    }

    public function test_schedule_blocks_inactive_website_and_past_time(): void
    {
        $ap = $this->user('approver');
        $v = $this->make('approved', 'approved');
        $this->site->update(['status' => 'pending']);
        $this->expectBlocked(fn () => $this->wf->schedule($ap, $v, $this->future(), $this->site));
        $this->site->update(['status' => 'active']);
        $this->expectBlocked(fn () => $this->wf->schedule($ap, $v, Carbon::now('Asia/Ho_Chi_Minh')->subHour(), $this->site));
        $this->assertSame(0, Publication::count());
        $this->assertCount(0, $this->sink->entries);
    }

    public function test_inactive_user_blocked(): void
    {
        $u = $this->user('approver', false);
        $v = $this->make();
        $this->expectBlocked(fn () => $this->wf->approve($u, $v));
        $this->expectBlocked(fn () => $this->wf->submitForReview($this->user('writer', false), $this->make('planned', 'rejected')));
        $this->assertCount(0, $this->sink->entries);
    }

    private function expectBlocked(callable $fn): void
    {
        try {
            $fn();
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('Phải bị chặn');
    }
}
