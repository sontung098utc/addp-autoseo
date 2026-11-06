<?php

namespace App\Filament\Support\Audit;

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\User;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class AuditRecorder
{
    private const SENSITIVE_KEYS = ['password', 'secret', 'token', 'credential', 'authorization', 'api_key'];

    private const CONTENT_KEYS = ['html_content'];

    public function __construct(private readonly AuditSink $sink = new LogAuditSink())
    {
    }

    /**
     * Kiểm tra rồi ghi 1 entry audit; ném InvalidArgumentException (không ghi gì) nếu vi phạm.
     *
     * @return array<string, mixed> entry đã ghi
     */
    public function record(
        AuditAction $action,
        User $actor,
        ?Article $article = null,
        ?ArticleVersion $version = null,
        ?string $reason = null,
        array $extra = [],
    ): array {
        if (! $actor->is_active) {
            throw new InvalidArgumentException('Tài khoản đã bị vô hiệu hóa, không thể thực hiện hành động này.');
        }

        if ($action->requiresApprover() && ! $actor->isApprover()) {
            throw new InvalidArgumentException('Vai trò hiện tại không đủ quyền thực hiện hành động này.');
        }

        $reason = $reason === null ? null : trim($reason);
        if ($reason === '') {
            $reason = null;
        }

        // ArticleVersion chưa có cột tác giả nên dùng created_by của bài; sẽ tinh chỉnh khi Dev1 thêm cột.
        $selfApproved = $action->tracksSelfApproval()
            && $article !== null
            && $article->created_by !== null
            && (int) $article->created_by === (int) $actor->id;

        if ($action->requiresReason() && $reason === null) {
            throw new InvalidArgumentException('Hành động này bắt buộc phải nhập lý do.');
        }

        if ($action === AuditAction::Approve && $selfApproved && $reason === null) {
            throw new InvalidArgumentException('Tự duyệt bài của chính mình bắt buộc phải có ghi chú tự duyệt.');
        }

        $entry = [
            'action' => $action->value,
            'actor_id' => $actor->id,
            'actor_role' => $actor->role,
            'article_id' => $article?->id,
            'article_version_id' => $version?->id,
            'website_id' => $article?->website_id,
            'reason' => $reason,
            'self_approved' => $selfApproved,
            'at_utc' => Carbon::now('UTC')->toIso8601String(),
            'extra' => $this->redact($extra),
        ];

        $this->sink->write($entry);

        return $entry;
    }

    /** Che các khóa nhạy cảm (kể cả lồng nhau) và bỏ nội dung bài viết. */
    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            $lower = strtolower((string) $key);

            if (in_array($lower, self::CONTENT_KEYS, true)) {
                unset($data[$key]);
                continue;
            }

            // 'token' chỉ khớp khi là một từ riêng (access_token, x_token_y) để không che nhầm 'tokens_used'.
            $isToken = (bool) preg_match('/(^|[_\-])token($|[_\-])/', $lower);
            $hit = $isToken;
            foreach (self::SENSITIVE_KEYS as $needle) {
                if ($needle !== 'token' && str_contains($lower, $needle)) {
                    $hit = true;
                    break;
                }
            }
            if ($hit) {
                $data[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }
}
