<?php

namespace Tests\Unit;

use App\Filament\Support\Audit\AuditRecorder;
use App\Filament\Support\Audit\AuditSink;
use App\Filament\Support\Review\ReviewWorkflow;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class QualityApproveTest extends TestCase
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
            public ?int $failAt = null;
            public function write(array $entry): void
            {
                if ($this->failAt !== null && count($this->entries) + 1 === $this->failAt) {
                    throw new RuntimeException('sink lỗi');
                }
                $this->entries[] = $entry;
            }
        };
        $this->wf = new ReviewWorkflow(new AuditRecorder($this->sink));
        $this->site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid() . '@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function make(bool $bad): ArticleVersion
    {
        $a = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'học bổng toàn phần', 'workflow_status' => 'needs_review']);

        return QualityFixtures::version($a, 1, 'needs_review', $bad ? ['html_content' => '<p>Quá ngắn.</p>'] : []);
    }

    private function assertUnchanged(ArticleVersion $v): void
    {
        $this->assertSame('needs_review', $v->fresh()->review_status);
        $this->assertSame('needs_review', $v->article->fresh()->workflow_status);
        $this->assertNull($v->fresh()->reviewed_by);
    }

    public function test_blocked_without_override_leaves_db_and_log_untouched(): void
    {
        $v = $this->make(true);
        try {
            $this->wf->approve($this->user('approver'), $v, 'ok');
            $this->fail('phải bị chặn');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('LEN01', $e->getMessage());
            $this->assertStringContainsString('HEAD01', $e->getMessage());
            $this->assertStringContainsString('lý do ngoại lệ', $e->getMessage());
        }
        $this->assertUnchanged($v);
        $this->assertSame([], $this->sink->entries);
    }

    public function test_short_or_blank_override_reason_is_rejected(): void
    {
        $v = $this->make(true);
        foreach ([null, '', '   ', 'ngắn quá', "  chín ký t "] as $reason) {
            try {
                $this->wf->approve($this->user('approver'), $v, null, $reason);
                $this->fail('phải bị chặn: ' . var_export($reason, true));
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertUnchanged($v);
        $this->assertSame([], $this->sink->entries);
    }

    public function test_override_with_reason_approves_and_logs_two_entries(): void
    {
        $v = $this->make(true);
        $ap = $this->user('approver');
        $this->wf->approve($ap, $v, 'ghi chú', 'Bài thông báo ngắn, đã thống nhất với biên tập');

        $this->assertSame('approved', $v->fresh()->review_status);
        $this->assertSame('approved', $v->article->fresh()->workflow_status);
        $this->assertSame(['approve', 'override_quality'], array_column($this->sink->entries, 'action'));
        $o = $this->sink->entries[1];
        $this->assertSame('Bài thông báo ngắn, đã thống nhất với biên tập', $o['reason']);
        $this->assertContains('LEN01', $o['extra']['blocking_codes']);
        $this->assertContains('HEAD01', $o['extra']['blocking_codes']);
        $this->assertStringNotContainsString('Quá ngắn', json_encode($o, JSON_UNESCAPED_UNICODE));
        $this->assertSame($ap->id, $o['actor_id']);
    }

    public function test_second_log_failure_rolls_back_approval(): void
    {
        $v = $this->make(true);
        $this->sink->failAt = 2;
        try {
            $this->wf->approve($this->user('approver'), $v, null, 'Lý do ngoại lệ đầy đủ');
            $this->fail('phải ném lỗi');
        } catch (RuntimeException) {
        }
        $this->assertUnchanged($v);
        $this->assertCount(1, $this->sink->entries, 'log Approve đã ghi nhưng DB rollback; lỗi là do sink thử nghiệm, không phải thiếu transaction');
    }

    public function test_writer_cannot_override(): void
    {
        $v = $this->make(true);
        $this->expectException(InvalidArgumentException::class);
        try {
            $this->wf->approve($this->user('writer'), $v, null, 'Lý do ngoại lệ đầy đủ');
        } finally {
            $this->assertUnchanged($v);
            $this->assertSame([], $this->sink->entries);
        }
    }

    public function test_clean_article_approves_with_single_log_and_ignores_override_reason(): void
    {
        $v = $this->make(false);
        $this->wf->approve($this->user('approver'), $v, null, 'Lý do thừa nhưng không cần');
        $this->assertSame('approved', $v->fresh()->review_status);
        $this->assertSame(['approve'], array_column($this->sink->entries, 'action'));
    }
}
