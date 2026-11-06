<?php

namespace App\Filament\Support\Audit;

/** Các hành động duyệt/xuất bản bắt buộc phải có log. */
enum AuditAction: string
{
    case SubmitForReview = 'submit_review';
    case Approve = 'approve';
    case Reject = 'reject';
    case Schedule = 'schedule';
    case OverrideQualityBlock = 'override_quality';
    case RevokeWebsite = 'revoke_website';
    case EmergencyStop = 'emergency_stop';

    /** Hành động bắt buộc có lý do không rỗng. */
    public function requiresReason(): bool
    {
        return in_array($this, [self::Reject, self::OverrideQualityBlock, self::RevokeWebsite, self::EmergencyStop], true);
    }

    /** Hành động writer không được thực hiện. */
    public function requiresApprover(): bool
    {
        return $this !== self::SubmitForReview;
    }

    /** Hành động có tính cờ tự duyệt. */
    public function tracksSelfApproval(): bool
    {
        return in_array($this, [self::Approve, self::Schedule], true);
    }
}
