<?php

namespace App\Filament\Support\Review;

use App\Filament\Support\Audit\AuditAction;
use App\Filament\Support\Audit\AuditRecorder;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\Quality\ContentQualityChecker;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Chuyển trạng thái duyệt/xếp lịch. Mọi kiểm tra ở server; mỗi hành động thành công ghi đúng 1 log audit.
 * Bản ghi DB và log nằm trong cùng transaction: AuditRecorder ném lỗi => DB rollback.
 */
class ReviewWorkflow
{
    public const TIMEZONE = 'Asia/Ho_Chi_Minh';

    /** Lý do ngoại lệ tối thiểu (ký tự) để duyệt bài còn lỗi P0. */
    public const MIN_OVERRIDE_REASON = 10;

    public function __construct(
        private readonly AuditRecorder $audit = new AuditRecorder(),
        private readonly ContentQualityChecker $quality = new ContentQualityChecker(),
    )
    {
    }

    /** Quy ước khóa: 1 khóa cho mỗi (website, bài, số phiên bản) — do hệ thống sinh, không nhận từ người dùng. */
    public static function publicationKey(int $websiteId, int $articleId, int $version): string
    {
        return "pub-{$websiteId}-a{$articleId}-v{$version}";
    }

    public function submitForReview(User $actor, ArticleVersion $version): void
    {
        $this->assertActive($actor);
        if (! $actor->isWriter()) {
            throw new InvalidArgumentException('Vai trò hiện tại không đủ quyền gửi duyệt.');
        }

        DB::transaction(function () use ($actor, $version) {
            [$article, $version] = $this->lock($version);
            if (! in_array($article->workflow_status, ['planned', 'rejected', 'drafting'], true)) {
                throw new InvalidArgumentException('Chỉ gửi duyệt được bài ở trạng thái Đã lên kế hoạch, Đang viết hoặc Cần sửa.');
            }
            $this->assertLatest($article, $version);

            $version->update(['review_status' => 'needs_review']);
            $article->update(['workflow_status' => 'needs_review']);
            $this->audit->record(AuditAction::SubmitForReview, $actor, $article, $version);
        });
    }

    /**
     * Duyệt bản. Nếu kiểm chất lượng còn lỗi P0 thì bắt buộc $overrideReason (>= 10 ký tự);
     * khi đó ghi thêm log OverrideQualityBlock (kèm mã P0, không kèm nội dung) trong cùng transaction.
     */
    public function approve(User $actor, ArticleVersion $version, ?string $note = null, ?string $overrideReason = null): void
    {
        $this->assertApprover($actor);

        DB::transaction(function () use ($actor, $version, $note, $overrideReason) {
            [$article, $version] = $this->lock($version);
            if ($version->review_status !== 'needs_review' || $article->workflow_status !== 'needs_review') {
                throw new InvalidArgumentException('Chỉ duyệt được bản đang ở trạng thái Chờ duyệt.');
            }
            $this->assertLatest($article, $version);

            $blocking = $this->quality->check($version, $article)->blockingCodes();
            $overrideReason = $this->clean($overrideReason);
            if ($blocking && ($overrideReason === null || mb_strlen($overrideReason) < self::MIN_OVERRIDE_REASON)) {
                throw new InvalidArgumentException(
                    'Bài còn lỗi chặn duyệt (' . implode(', ', $blocking) . '). Hãy sửa bài, hoặc nhập lý do ngoại lệ tối thiểu '
                    . self::MIN_OVERRIDE_REASON . ' ký tự để duyệt.'
                );
            }

            $note = $this->clean($note);
            $version->update([
                'review_status' => 'approved',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_notes' => $note,
            ]);
            $article->update(['workflow_status' => 'approved']);
            // Nếu tự duyệt mà thiếu ghi chú, AuditRecorder ném lỗi và transaction rollback.
            $this->audit->record(AuditAction::Approve, $actor, $article, $version, $note);
            if ($blocking) {
                $this->audit->record(AuditAction::OverrideQualityBlock, $actor, $article, $version, $overrideReason, ['blocking_codes' => $blocking]);
            }
        });
    }

    public function reject(User $actor, ArticleVersion $version, ?string $reason): void
    {
        $this->assertApprover($actor);

        DB::transaction(function () use ($actor, $version, $reason) {
            [$article, $version] = $this->lock($version);
            if ($version->review_status !== 'needs_review' || $article->workflow_status !== 'needs_review') {
                throw new InvalidArgumentException('Chỉ từ chối được bản đang ở trạng thái Chờ duyệt.');
            }
            $this->assertLatest($article, $version);

            $reason = $this->clean($reason);
            $version->update([
                'review_status' => 'rejected',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_notes' => $reason,
            ]);
            $article->update(['workflow_status' => 'rejected']);
            $this->audit->record(AuditAction::Reject, $actor, $article, $version, $reason);
        });
    }

    /** @param CarbonInterface $atVn thời điểm theo giờ Việt Nam (wall-clock) */
    public function schedule(User $actor, ArticleVersion $version, CarbonInterface $atVn, Website $website): Publication
    {
        $this->assertApprover($actor);

        return DB::transaction(function () use ($actor, $version, $atVn, $website) {
            [$article, $version] = $this->lock($version);
            if ($version->review_status !== 'approved' || $article->workflow_status !== 'approved') {
                throw new InvalidArgumentException('Chỉ xếp lịch được bản đã duyệt.');
            }
            $this->assertLatest($article, $version);

            $website = Website::query()->lockForUpdate()->find($website->id);
            if (! $website || $website->status !== 'active') {
                throw new InvalidArgumentException('Website đích chưa ở trạng thái Hoạt động.');
            }

            // Lấy đúng ngày giờ người dùng nhập, hiểu là giờ VN (bỏ múi giờ của đối tượng truyền vào), rồi đổi sang UTC.
            $utc = Carbon::parse($atVn->format('Y-m-d H:i:s'), self::TIMEZONE)->utc();
            if ($utc->lessThanOrEqualTo(now('UTC'))) {
                throw new InvalidArgumentException('Thời điểm xuất bản phải ở tương lai.');
            }

            if (Publication::where('article_version_id', $version->id)->exists()) {
                throw new InvalidArgumentException('Bản này đã được xếp lịch xuất bản, không thể xếp lịch lại.');
            }

            $key = self::publicationKey($website->id, $article->id, $version->version);
            if (Publication::where('publication_key', $key)->exists()) {
                throw new InvalidArgumentException('Khóa xuất bản đã tồn tại, không thể xếp lịch trùng.');
            }

            $publication = Publication::create([
                'website_id' => $website->id,
                'article_version_id' => $version->id,
                'publication_key' => $key,
                'scheduled_at_utc' => $utc,
                'timezone' => self::TIMEZONE,
                'status' => 'scheduled',
            ]);
            $article->update(['workflow_status' => 'scheduled']);
            $this->audit->record(AuditAction::Schedule, $actor, $article, $version, null, [
                'publication_key' => $key,
                'website_id' => $website->id,
                'scheduled_at_utc' => $utc->toIso8601String(),
            ]);

            return $publication;
        });
    }

    /** Khóa dòng và đọc lại trạng thái mới nhất để tránh chạy đua. @return array{0: Article, 1: ArticleVersion} */
    private function lock(ArticleVersion $version): array
    {
        $article = Article::query()->lockForUpdate()->findOrFail($version->article_id);
        $fresh = ArticleVersion::query()->lockForUpdate()->findOrFail($version->id);

        return [$article, $fresh];
    }

    private function assertLatest(Article $article, ArticleVersion $version): void
    {
        if ((int) $article->versions()->max('version') !== (int) $version->version) {
            throw new InvalidArgumentException('Đây không phải bản mới nhất của bài, hãy thao tác trên bản mới nhất.');
        }
    }

    private function assertActive(User $actor): void
    {
        if (! $actor->is_active) {
            throw new InvalidArgumentException('Tài khoản đã bị vô hiệu hóa, không thể thực hiện hành động này.');
        }
    }

    private function assertApprover(User $actor): void
    {
        $this->assertActive($actor);
        if (! $actor->isApprover()) {
            throw new InvalidArgumentException('Vai trò hiện tại không đủ quyền thực hiện hành động này.');
        }
    }

    private function clean(?string $text): ?string
    {
        $text = $text === null ? null : trim($text);

        return $text === '' ? null : $text;
    }
}
