<?php

namespace App\Services\Audit;

use App\Filament\Support\Gsc\PageArticleLinker;
use App\Filament\Support\SeoAudit\CrawlerIssueMap;
use App\Filament\Support\SeoAudit\DbSeoAuditSource;
use App\Filament\Support\SeoAudit\IssueCatalog;
use App\Filament\Support\SeoAudit\ScanGuard;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\AuditSuggestion;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\DB;

/**
 * AG-S26: lỗi P0/P1 của lần quét THẬT gần nhất -> "đề xuất làm mới bài". Đề xuất do hệ thống sinh, KHÔNG tự thành việc:
 * chỉ approver/admin chấp nhận mới tạo bài kind=refresh (planned) để chiến dịch giao cho đội AI. Không tự sửa bài đã đăng.
 * URL không khớp bài nào (trang tĩnh, danh mục) vẫn được đề xuất nhưng chỉ để sửa thủ công trên CMS, không có việc cho AI.
 */
final class AuditSuggestionService
{
    /** Kết quả quét cũ hơn số ngày này thì không đề xuất (dữ liệu có thể đã lỗi thời). */
    public const MAX_AGE_DAYS = 30;

    public const PRIORITY = ['P0' => 90, 'P1' => 60];

    public function __construct(private readonly DbSeoAuditSource $source = new DbSeoAuditSource()) {}

    /** Lý do không sinh được (null = sinh được). */
    public function blocker(Website $site): ?string
    {
        $audit = $this->source->lastCompleted($site);
        if ($audit === null || $audit->completed_at === null) {
            return 'Chưa có dữ liệu quét thật cho website này.';
        }
        if ($audit->completed_at->lt(now()->subDays(self::MAX_AGE_DAYS))) {
            return 'Lần quét gần nhất đã cũ (quá '.self::MAX_AGE_DAYS.' ngày); quét lại trước khi đề xuất.';
        }

        return null;
    }

    /** @return array{created: int, existing: int, skipped_reason: ?string} */
    public function generate(Website $site): array
    {
        if ($reason = $this->blocker($site)) {
            return ['created' => 0, 'existing' => 0, 'skipped_reason' => $reason];
        }
        $audit = $this->source->lastCompleted($site);
        $index = $this->articleIndex($site);
        $created = $existing = 0;

        foreach ($this->source->pagesOf($audit) as $page) {
            $types = [];
            foreach ((array) $page->issues as $code) {
                $t = CrawlerIssueMap::type((string) $code);
                if ($t !== null && IssueCatalog::severity($t) !== IssueCatalog::P2) {
                    $types[$t] = true;
                }
            }
            if ($types === []) {
                continue;
            }
            $types = array_keys($types);
            $severity = in_array(IssueCatalog::P0, array_map([IssueCatalog::class, 'severity'], $types), true) ? 'P0' : 'P1';
            $url = (string) $page->url;

            // Đã có đề xuất đang mở/đã chấp nhận cho URL này (kể cả từ lần quét trước) thì không tạo thêm.
            $open = AuditSuggestion::query()->where('website_id', $site->id)->where('url', $url)->whereIn('status', ['de_xuat', 'da_chap_nhan'])->exists();
            $same = AuditSuggestion::query()->where('website_id', $site->id)->where('url', $url)->where('seo_audit_id', $audit->id)->exists();
            if ($open || $same) {
                $existing++;

                continue;
            }
            AuditSuggestion::create([
                'website_id' => $site->id, 'seo_audit_id' => $audit->id, 'crawled_url_id' => $page->id, 'url' => $url,
                'article_id' => $this->match($url, $index), 'issue_codes' => $types, 'severity' => $severity,
                'priority' => self::PRIORITY[$severity], 'status' => 'de_xuat',
            ]);
            $created++;
        }

        return ['created' => $created, 'existing' => $existing, 'skipped_reason' => null];
    }

    /** Chấp nhận: tạo bài refresh đã lên kế hoạch. Chỉ approver/admin. */
    public function accept(AuditSuggestion $s, User $user): Article
    {
        $this->authorize($user);

        return DB::transaction(function () use ($s, $user) {
            $s = AuditSuggestion::query()->whereKey($s->id)->lockForUpdate()->firstOrFail();
            if ($s->status !== 'de_xuat') {
                throw new AuditSuggestionException('Đề xuất này đã được xử lý.');
            }
            $orig = $s->article_id ? Article::query()->where('website_id', $s->website_id)->find($s->article_id) : null;
            if (! $orig) {
                throw new AuditSuggestionException('Trang không phải bài viết do hệ thống quản lý: cần sửa thủ công trên CMS, không giao được cho đội AI.');
            }
            $task = Article::create([
                'website_id' => $s->website_id, 'created_by' => $user->id, 'primary_keyword' => $orig->primary_keyword,
                'workflow_status' => 'planned', 'kind' => 'refresh', 'origin' => 'audit', 'origin_ref' => mb_substr($s->url, 0, 255),
                'keyword_id' => $orig->keyword_id, 'priority' => $s->priority,
            ]);
            $s->update(['status' => 'da_chap_nhan', 'article_task_id' => $task->id, 'decided_by' => $user->id, 'decided_at' => now()]);

            return $task;
        });
    }

    /** Chấp nhận hàng loạt (chỉ các đề xuất có bài khớp). @param list<int> $ids @return array{accepted: int, failed: int} */
    public function acceptMany(array $ids, User $user): array
    {
        $this->authorize($user);
        $ok = $fail = 0;
        foreach (AuditSuggestion::query()->whereIn('id', $ids)->orderByDesc('priority')->orderBy('id')->get() as $s) {
            try {
                $this->accept($s, $user);
                $ok++;
            } catch (AuditSuggestionException) {
                $fail++;
            }
        }

        return ['accepted' => $ok, 'failed' => $fail];
    }

    public function dismiss(AuditSuggestion $s, User $user): void
    {
        $this->authorize($user);
        if ($s->status !== 'de_xuat') {
            throw new AuditSuggestionException('Đề xuất này đã được xử lý.');
        }
        $s->update(['status' => 'bo_qua', 'decided_by' => $user->id, 'decided_at' => now()]);
    }

    private function authorize(User $user): void
    {
        if (! ScanGuard::canAct($user)) {
            throw new AuditSuggestionException('Chỉ approver trở lên được chấp nhận hoặc bỏ qua đề xuất.');
        }
    }

    /**
     * Chỉ mục URL chuẩn hóa/slug -> article_id của website. Ưu tiên published_url của lượt đăng đã xuất bản;
     * slug chỉ dùng cho phiên bản đã duyệt và so khớp CHÍNH XÁC đoạn cuối của đường dẫn.
     *
     * @return array{urls: array<string,int>, slugs: array<string,int>}
     */
    private function articleIndex(Website $site): array
    {
        $urls = [];
        $pubs = Publication::query()->where('website_id', $site->id)->where('status', 'published')->whereNotNull('published_url')->orderBy('id')->with('articleVersion')->get();
        foreach ($pubs as $p) {
            if ($p->articleVersion) {
                $urls[PageArticleLinker::normalize((string) $p->published_url)] ??= $p->articleVersion->article_id;
            }
        }
        $slugs = [];
        $vers = ArticleVersion::query()->where('review_status', 'approved')->whereNotNull('slug')->where('slug', '!=', '')
            ->whereHas('article', fn ($q) => $q->where('website_id', $site->id))->orderBy('id')->get(['id', 'article_id', 'slug']);
        foreach ($vers as $v) {
            $slugs[(string) $v->slug] ??= $v->article_id;
        }

        return ['urls' => $urls, 'slugs' => $slugs];
    }

    /** @param array{urls: array<string,int>, slugs: array<string,int>} $index */
    private function match(string $url, array $index): ?int
    {
        $hit = $index['urls'][PageArticleLinker::normalize($url)] ?? null;
        if ($hit !== null) {
            return $hit;
        }
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $last = $path === '' ? '' : basename($path);
        $last = preg_replace('/\.(html?|php)$/i', '', $last) ?? $last;

        return $last !== '' ? ($index['slugs'][$last] ?? null) : null;
    }
}
