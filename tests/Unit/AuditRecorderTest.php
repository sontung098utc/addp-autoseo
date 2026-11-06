<?php

namespace Tests\Unit;

use App\Filament\Support\Audit\AuditAction;
use App\Filament\Support\Audit\AuditRecorder;
use App\Filament\Support\Audit\AuditSink;
use App\Filament\Support\Audit\LogAuditSink;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Tests\TestCase;

class AuditRecorderTest extends TestCase
{
    private function sink(): object
    {
        return new class implements AuditSink {
            public array $entries = [];

            public function write(array $entry): void
            {
                $this->entries[] = $entry;
            }
        };
    }

    private function user(int $id, string $role, bool $active = true): User
    {
        return (new User)->forceFill(['id' => $id, 'role' => $role, 'is_active' => $active]);
    }

    private function article(int $createdBy = 1): Article
    {
        return (new Article)->forceFill(['id' => 10, 'website_id' => 5, 'created_by' => $createdBy]);
    }

    public function test_moi_action_ghi_dung_mot_entry_du_truong(): void
    {
        foreach (AuditAction::cases() as $action) {
            $sink = $this->sink();
            $entry = (new AuditRecorder($sink))->record(
                $action,
                $this->user(2, 'approver'),
                $this->article(1),
                (new ArticleVersion)->forceFill(['id' => 7]),
                'lý do',
            );

            $this->assertCount(1, $sink->entries, $action->value);
            $this->assertSame($entry, $sink->entries[0]);
            foreach (['action', 'actor_id', 'actor_role', 'article_id', 'article_version_id', 'website_id', 'reason', 'self_approved', 'at_utc', 'extra'] as $k) {
                $this->assertArrayHasKey($k, $entry);
            }
            $this->assertSame($action->value, $entry['action']);
            $this->assertSame([2, 'approver', 10, 7, 5], [$entry['actor_id'], $entry['actor_role'], $entry['article_id'], $entry['article_version_id'], $entry['website_id']]);
        }
    }

    public function test_self_approved_true_va_false(): void
    {
        $r = new AuditRecorder($this->sink());
        $this->assertTrue($r->record(AuditAction::Approve, $this->user(1, 'approver'), $this->article(1), null, 'tự duyệt')['self_approved']);
        $this->assertFalse($r->record(AuditAction::Approve, $this->user(2, 'approver'), $this->article(1))['self_approved']);
        $this->assertTrue($r->record(AuditAction::Schedule, $this->user(1, 'admin'), $this->article(1))['self_approved']);
        $this->assertFalse($r->record(AuditAction::Reject, $this->user(1, 'approver'), $this->article(1), null, 'x')['self_approved']);
    }

    public function test_tu_duyet_thieu_ly_do_bi_tu_choi_va_khong_ghi(): void
    {
        $sink = $this->sink();
        try {
            (new AuditRecorder($sink))->record(AuditAction::Approve, $this->user(1, 'approver'), $this->article(1), null, '   ');
            $this->fail('Phải ném lỗi');
        } catch (InvalidArgumentException) {
        }
        $this->assertSame([], $sink->entries);
    }

    public function test_action_bat_buoc_ly_do_va_ly_do_trang_coi_nhu_rong(): void
    {
        foreach ([AuditAction::Reject, AuditAction::OverrideQualityBlock, AuditAction::RevokeWebsite, AuditAction::EmergencyStop] as $action) {
            foreach ([null, '', " \t\n "] as $reason) {
                $sink = $this->sink();
                try {
                    (new AuditRecorder($sink))->record($action, $this->user(2, 'admin'), $this->article(), null, $reason);
                    $this->fail('Phải ném lỗi: '.$action->value);
                } catch (InvalidArgumentException) {
                }
                $this->assertSame([], $sink->entries);
            }
        }
    }

    public function test_writer_bi_chan_nhung_duoc_gui_duyet(): void
    {
        $sink = $this->sink();
        $r = new AuditRecorder($sink);
        $writer = $this->user(3, 'writer');

        foreach ([AuditAction::Approve, AuditAction::Reject, AuditAction::Schedule, AuditAction::OverrideQualityBlock] as $action) {
            try {
                $r->record($action, $writer, $this->article(), null, 'lý do');
                $this->fail('Phải ném lỗi: '.$action->value);
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame([], $sink->entries);

        $r->record(AuditAction::SubmitForReview, $writer, $this->article(3));
        $this->assertCount(1, $sink->entries);
    }

    public function test_user_inactive_bi_chan(): void
    {
        $sink = $this->sink();
        $this->expectException(InvalidArgumentException::class);
        try {
            (new AuditRecorder($sink))->record(AuditAction::SubmitForReview, $this->user(2, 'admin', false), $this->article());
        } finally {
            $this->assertSame([], $sink->entries);
        }
    }

    public function test_redact_long_nhau_va_khong_ghi_html_content(): void
    {
        $sink = $this->sink();
        $entry = (new AuditRecorder($sink))->record(AuditAction::Approve, $this->user(2, 'approver'), $this->article(1), null, null, [
            'ip' => '1.2.3.4',
            'Password' => 'abc',
            'html_content' => '<p>nội dung</p>',
            'nested' => ['API_KEY' => 'k', 'ok' => 1, 'deep' => ['x_Token_y' => 't', 'Authorization' => 'Bearer z', 'client_secret' => 's', 'db_credentials' => 'c']],
        ]);

        $this->assertSame([
            'ip' => '1.2.3.4',
            'Password' => '[REDACTED]',
            'nested' => ['API_KEY' => '[REDACTED]', 'ok' => 1, 'deep' => [
                'x_Token_y' => '[REDACTED]', 'Authorization' => '[REDACTED]', 'client_secret' => '[REDACTED]', 'db_credentials' => '[REDACTED]',
            ]],
        ], $entry['extra']);
        $this->assertStringNotContainsString('nội dung', json_encode($entry, JSON_UNESCAPED_UNICODE));
    }

    public function test_redact_khong_che_nham_tokens_used(): void
    {
        $entry = (new AuditRecorder($this->sink()))->record(AuditAction::Approve, $this->user(2, 'approver'), $this->article(1), null, null, [
            'tokens_used' => 1200,
            'access_token' => 'abc',
            'token' => 'abc',
        ]);

        $this->assertSame(['tokens_used' => 1200, 'access_token' => '[REDACTED]', 'token' => '[REDACTED]'], $entry['extra']);
    }

    public function test_at_utc_la_utc(): void
    {
        $entry = (new AuditRecorder($this->sink()))->record(AuditAction::SubmitForReview, $this->user(2, 'writer'), $this->article());
        $this->assertMatchesRegularExpression('/(Z|\+00:00)$/', $entry['at_utc']);
    }

    public function test_sink_mac_dinh_ghi_vao_log(): void
    {
        Log::shouldReceive('info')->once()->with('audit', \Mockery::on(fn ($e) => $e['action'] === 'approve'));
        (new AuditRecorder(new LogAuditSink))->record(AuditAction::Approve, $this->user(2, 'approver'), $this->article(1));
    }
}
