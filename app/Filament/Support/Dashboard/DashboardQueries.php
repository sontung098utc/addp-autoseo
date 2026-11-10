<?php

namespace App\Filament\Support\Dashboard;

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Truy vấn thuần cho màn Tổng quan. Lọc theo vai trò ở server; nhận $now để test cố định thời gian.
 */
final class DashboardQueries
{
    /** Quá hạn = bài chờ duyệt HƠN số giờ này (đúng 24 giờ chưa tính là quá hạn). */
    public const OVERDUE_AFTER_HOURS = 24;

    public const RECENT_ACTIVITY_LIMIT = 8;

    public const ERROR_PREVIEW_LENGTH = 140;

    public const VN_TIMEZONE = 'Asia/Ho_Chi_Minh';

    /** Người duyệt (approver/admin) thấy toàn bộ hàng đợi; biên tập viên chỉ thấy việc của mình. */
    public static function isApprover(User $user): bool
    {
        return $user->isApprover();
    }

    /** Bản mới nhất của mỗi bài, lọc theo trạng thái duyệt. */
    private static function latestVersions(string $status)
    {
        return ArticleVersion::query()
            ->where('review_status', $status)
            ->whereIn('id', ArticleVersion::query()
                ->selectRaw('MAX(id)')->groupBy('article_id'))
            ->with('article.website');
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public static function decisions(User $user, CarbonInterface $now): Collection
    {
        if (self::isApprover($user)) {
            return self::latestVersions('needs_review')->get()->map(function (ArticleVersion $v) use ($now) {
                $waited = $v->created_at ? $v->created_at->diffInMinutes($now, false) : 0;

                return [
                    'kind' => 'review',
                    'version_id' => $v->id,
                    'article_id' => $v->article_id,
                    'keyword' => $v->article?->primary_keyword,
                    'website' => $v->article?->website?->name,
                    'waiting_minutes' => (int) max(0, $waited),
                    'waiting_label' => self::waitingLabel((int) max(0, $waited)),
                    'overdue' => $waited > self::OVERDUE_AFTER_HOURS * 60,
                    'note' => null,
                ];
            })->sortBy([['overdue', 'desc'], ['waiting_minutes', 'desc']])->values();
        }

        $rejected = self::latestVersions('rejected')
            ->whereHas('article', fn ($q) => $q->where('created_by', $user->id))
            ->get()->map(fn (ArticleVersion $v) => [
                'kind' => 'rejected',
                'version_id' => $v->id,
                    'article_id' => $v->article_id,
                'keyword' => $v->article?->primary_keyword,
                'website' => $v->article?->website?->name,
                'waiting_minutes' => 0,
                'waiting_label' => 'Cần sửa',
                'overdue' => false,
                'note' => $v->review_notes,
            ]);

        $todo = Article::query()->with('website')
            ->where('created_by', $user->id)
            ->whereIn('workflow_status', ['planned', 'drafting'])
            ->get()->map(fn (Article $a) => [
                'kind' => 'todo',
                'version_id' => null,
                'article_id' => $a->id,
                'keyword' => $a->primary_keyword,
                'website' => $a->website?->name,
                'waiting_minutes' => 0,
                'waiting_label' => $a->workflow_status === 'planned' ? 'Chưa viết' : 'Đang viết, cần gửi duyệt',
                'overdue' => false,
                'note' => null,
            ]);

        return $rejected->concat($todo)->values();
    }

    public static function waitingLabel(int $minutes): string
    {
        if ($minutes < 60) {
            return 'chờ ' . max(1, $minutes) . ' phút';
        }
        if ($minutes < 24 * 60) {
            return 'chờ ' . intdiv($minutes, 60) . ' giờ';
        }

        return 'chờ ' . intdiv($minutes, 1440) . ' ngày';
    }

    /**
     * Lượt đăng lỗi / chờ đối soát. Biên tập viên không thấy khối này.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function publicationProblems(User $user): Collection
    {
        if (! self::isApprover($user)) {
            return collect();
        }

        return Publication::query()
            ->with(['website', 'articleVersion.article'])
            ->whereIn('status', ['failed', 'needs_reconcile'])
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (Publication $p) => [
                'id' => $p->id,
                'article_id' => $p->articleVersion?->article_id,
                'keyword' => $p->articleVersion?->article?->primary_keyword,
                'website' => $p->website?->name,
                'status' => $p->status,
                'error' => self::safeError($p->last_error),
            ]);
    }

    /** Che chuỗi giống token/credential rồi rút gọn. */
    public static function safeError(?string $error): ?string
    {
        if ($error === null || $error === '') {
            return null;
        }
        $masked = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i', '$1 ***', $error);
        $masked = preg_replace('/((?:token|secret|password|passwd|api[_-]?key|authorization)\s*[=:]\s*)\S+/i', '$1***', $masked);
        $masked = preg_replace('/\b(?:sk|pk|ghp|gho|xox[a-z])[-_][A-Za-z0-9_-]{8,}/', '***', $masked);
        $masked = preg_replace('/\b[A-Za-z0-9_+\/=-]{24,}\b/', '***', $masked);

        return mb_strimwidth($masked, 0, self::ERROR_PREVIEW_LENGTH, '…');
    }

    /**
     * Mỗi website active: lượt đăng scheduled gần nhất (từ $now) hoặc null.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function nextPublications(CarbonInterface $now): Collection
    {
        return Website::query()->where('status', 'active')->orderBy('name')->get()->map(function (Website $w) use ($now) {
            $p = Publication::query()
                ->where('website_id', $w->id)->where('status', 'scheduled')
                ->where('scheduled_at_utc', '>=', $now->copy()->utc()->format('Y-m-d H:i:s'))
                ->orderBy('scheduled_at_utc')->first();
            // Cột lưu chữ số UTC; đọc thô để không bị cast theo múi giờ ứng dụng (Asia/Ho_Chi_Minh).
            $raw = $p?->getRawOriginal('scheduled_at_utc');
            $at = $raw ? Carbon::parse($raw, 'UTC') : null;

            return [
                'website' => $w->name,
                'publication_id' => $p?->id,
                'vn' => $at?->copy()->setTimezone(self::VN_TIMEZONE)->format('d/m/Y H:i'),
                'utc' => $at?->format('d/m/Y H:i'),
                'in' => $at ? $now->diffForHumans($at, ['syntax' => CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2]) : null,
            ];
        });
    }

    /**
     * 8 sự kiện duyệt/từ chối gần nhất (từ article_versions; audit_logs chưa có).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function recentActivity(): Collection
    {
        return ArticleVersion::query()
            ->whereNotNull('reviewed_at')->whereIn('review_status', ['approved', 'rejected'])
            ->with(['article', 'reviewer'])
            ->orderByDesc('reviewed_at')->limit(self::RECENT_ACTIVITY_LIMIT)->get()
            ->map(fn (ArticleVersion $v) => [
                'version_id' => $v->id,
                    'article_id' => $v->article_id,
                'keyword' => $v->article?->primary_keyword,
                'reviewer' => $v->reviewer?->name,
                'status' => $v->review_status,
                'note' => $v->review_notes,
                'at' => $v->reviewed_at?->copy()->setTimezone(self::VN_TIMEZONE)->format('d/m/Y H:i'),
            ]);
    }

    public static function heading(User $user, CarbonInterface $now): string
    {
        $n = self::decisions($user, $now)->count() + self::publicationProblems($user)->count();

        return $n === 0 ? 'Không có việc nào cần bạn quyết' : "Hôm nay có {$n} việc cần bạn quyết";
    }
}
