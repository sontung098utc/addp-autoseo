<?php

namespace App\Filament\Support\ContentPlan;

use App\Filament\Support\Audit\AuditAction;
use App\Filament\Support\Audit\AuditRecorder;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\Campaign;
use App\Models\Keyword;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Xóa kế hoạch: bài "Đã lên kế hoạch" chưa viết và chiến dịch chưa chạy. KHÔNG bao giờ xóa bài đã có bản viết, đã có phiên đội AI hoặc đã có lượt đăng.
 * Mỗi lần xóa ghi một dòng nhật ký (ai, xóa gì, bao nhiêu). Từ khóa trong kho từ khóa được giữ nguyên.
 */
final class PlanDeleter
{
    public function __construct(private readonly AuditRecorder $audit = new AuditRecorder) {}

    /**
     * Xóa các bài kế hoạch chưa viết. Bài không đủ điều kiện được bỏ qua kèm lý do (không ném lỗi khi chỉ một phần bị chặn).
     *
     * @param  list<int>  $articleIds
     * @return array{deleted:int, skipped:list<string>}
     */
    public function deletePlanned(User $actor, Website $site, array $articleIds, string $scope = 'bài chọn'): array
    {
        $this->guard($actor);
        $ids = array_values(array_unique(array_filter(array_map('intval', $articleIds), fn ($i) => $i > 0)));
        if ($ids === []) {
            throw new InvalidArgumentException('Chưa chọn bài nào để xóa.');
        }
        $deleted = 0;
        $skipped = [];
        DB::transaction(function () use ($ids, $site, &$deleted, &$skipped): void {
            foreach (Article::query()->whereIn('id', $ids)->lockForUpdate()->get() as $a) {
                $why = $this->blockReason($a, $site);
                if ($why !== null) {
                    $skipped[] = '«'.$a->primary_keyword.'»: '.$why;

                    continue;
                }
                $kw = $a->keyword_id;
                $a->delete();
                $this->releaseKeyword($kw);
                $deleted++;
            }
        });
        if ($deleted === 0 && $skipped === []) {
            throw new InvalidArgumentException('Không tìm thấy bài để xóa, tải lại trang.');
        }
        if ($deleted > 0) {
            $this->audit->record(AuditAction::DeletePlan, $actor, null, null, null, ['website_id' => $site->id, 'scope' => $scope, 'deleted' => $deleted, 'skipped' => count($skipped)]);
        }

        return ['deleted' => $deleted, 'skipped' => $skipped];
    }

    /**
     * Xóa mọi bài chờ viết của một nhóm ("solo" = bài lẻ, hoặc id chiến dịch).
     *
     * @return array{deleted:int, skipped:list<string>}
     */
    public function deleteGroupPlanned(User $actor, Website $site, string $group): array
    {
        $this->guard($actor);
        $q = Article::query()->where('website_id', $site->id)->where('workflow_status', 'planned');
        if ($group === 'solo') {
            $q->whereNull('campaign_id');
        } elseif (ctype_digit($group)) {
            $q->where('campaign_id', (int) $group);
        } else {
            throw new InvalidArgumentException('Nhóm không hợp lệ.');
        }
        $ids = $q->pluck('id')->all();
        if ($ids === []) {
            throw new InvalidArgumentException('Nhóm này không còn bài chờ viết để xóa.');
        }

        return $this->deletePlanned($actor, $site, $ids, $group === 'solo' ? 'bài lẻ' : 'chiến dịch #'.$group);
    }

    /**
     * Xóa một chiến dịch chưa chạy. Chặn khi: đang chạy, đã có phiên đội AI, hoặc còn bài đã viết/đang viết.
     * $withPlanned = true xóa luôn các bài chờ viết của nó; false thì giữ chúng thành bài lẻ.
     *
     * @return array{name:string, deleted:int, kept:int}
     */
    public function deleteCampaign(User $actor, Website $site, Campaign $campaign, bool $withPlanned): array
    {
        $this->guard($actor);
        if ((int) $campaign->website_id !== (int) $site->id) {
            throw new InvalidArgumentException('Chiến dịch không thuộc website này.');
        }
        if ($campaign->status === Campaign::STATUS_ACTIVE) {
            throw new InvalidArgumentException('Chiến dịch đang chạy. Tạm dừng chiến dịch trước rồi mới xóa.');
        }
        $name = (string) $campaign->name;
        $deleted = 0;
        $kept = 0;
        DB::transaction(function () use ($campaign, $withPlanned, &$deleted, &$kept): void {
            $articles = Article::query()->where('campaign_id', $campaign->id)->lockForUpdate()->get();
            $written = $articles->filter(fn (Article $a) => $a->workflow_status !== 'planned' || $this->hasWork($a));
            if ($written->isNotEmpty()) {
                throw new InvalidArgumentException('Chiến dịch có '.$written->count().' bài đã viết hoặc đang viết nên không xóa được. Chỉ xóa được chiến dịch chưa chạy.');
            }
            if (AgentRun::query()->where('campaign_id', $campaign->id)->exists()) {
                throw new InvalidArgumentException('Chiến dịch đã có phiên chạy của đội AI nên không xóa được.');
            }
            foreach ($articles as $a) {
                if ($withPlanned) {
                    $kw = $a->keyword_id;
                    $a->delete();
                    $this->releaseKeyword($kw);
                    $deleted++;
                } else {
                    $a->forceFill(['campaign_id' => null])->save();
                    $kept++;
                }
            }
            $campaign->delete();
        });
        $this->audit->record(AuditAction::DeleteCampaign, $actor, null, null, null, ['website_id' => $site->id, 'campaign' => $name, 'deleted_articles' => $deleted, 'kept_as_solo' => $kept]);

        return ['name' => $name, 'deleted' => $deleted, 'kept' => $kept];
    }

    private function guard(User $actor): void
    {
        if (! $actor->is_active || ! $actor->isApprover()) {
            throw new InvalidArgumentException('Chỉ trưởng nhóm duyệt bài trở lên mới xóa được kế hoạch.');
        }
    }

    /** null = xóa được; còn lại là lý do chặn (nói bằng lời người dùng hiểu). */
    private function blockReason(Article $a, Website $site): ?string
    {
        if ((int) $a->website_id !== (int) $site->id) {
            return 'không thuộc website này';
        }
        if ($a->workflow_status !== 'planned') {
            return 'đã qua bước lên kế hoạch (đã viết hoặc đang viết)';
        }
        if ($this->hasWork($a)) {
            return 'đã có bản viết hoặc phiên đội AI';
        }

        return null;
    }

    /** Từ khóa của bài vừa xóa: nếu không còn bài nào dùng thì trả về "Chưa dùng" để lên kế hoạch lại được. */
    private function releaseKeyword(?int $keywordId): void
    {
        if ($keywordId === null || Article::query()->where('keyword_id', $keywordId)->exists()) {
            return;
        }
        Keyword::query()->whereKey($keywordId)->where('status', 'planned')->update(['status' => 'new']);
    }

        /** Có dấu vết công việc thật: bản viết, phiên đội AI, hoặc lượt đăng (qua bản viết). */
    private function hasWork(Article $a): bool
    {
        return $a->versions()->exists() || AgentRun::query()->where('article_id', $a->id)->exists();
    }
}
