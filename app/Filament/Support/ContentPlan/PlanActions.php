<?php

namespace App\Filament\Support\ContentPlan;

use App\Filament\Support\Planning\DuplicateKeywordException;
use App\Filament\Support\Planning\PlannedArticleCreator;
use App\Filament\Support\Schedule\VnTime;
use App\Models\Article;
use App\Models\Keyword;
use App\Models\User;
use App\Models\Website;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Hành động THẬT của màn Kế hoạch: tạo bài từ cụm, đổi ngày dự kiến. */
final class PlanActions
{
    /**
     * Tạo bài cho các từ khóa đã chọn. Báo từng từ khóa; thành công một phần được phép.
     * Nếu có ngày: kiểm giới hạn bài/ngày TRƯỚC khi tạo (không tạo gì khi ngày không đủ chỗ).
     *
     * @param  list<string>  $keywords
     * @param  array{name:string, intent:string, keywords:list<string>}  $cluster
     * @param  array<string,string>  $intents  từ khóa => ý định (từ kho từ khóa)
     * @return list<array{keyword:string, status:'created'|'duplicate'|'error', message:string, article_id:?int}>
     */
    public function createFromCluster(User $actor, Website $site, array $cluster, array $keywords, ?string $day = null, array $intents = []): array
    {
        $keywords = array_values(array_unique(array_intersect($keywords, $cluster['keywords'])));
        if ($keywords === []) {
            throw new InvalidArgumentException('Chọn ít nhất một từ khóa chưa có bài.');
        }
        $times = [];
        if ($day) {
            $date = CarbonImmutable::parse($day, VnTime::TZ);
            if ($date->startOfDay()->lt(VnTime::now()->startOfDay())) {
                throw new InvalidArgumentException('Không đặt ngày đã qua.');
            }
            $taken = PlanData::dayTimes(PlanData::articles($site))[$date->format('Y-m-d')] ?? [];
            if ($reason = PlanRules::dayFullReason(count($taken), count($keywords))) {
                throw new InvalidArgumentException($reason);
            }
            foreach ($keywords as $_) {
                $slot = PlanRules::firstFreeSlot($taken);
                $taken[] = $slot;
                $times[] = $date->format('Y-m-d').' '.$slot;
            }
        }
        $creator = new PlannedArticleCreator;
        $results = [];
        $stored = Keyword::where('website_id', $site->id)->get()->keyBy('keyword_key');
        foreach ($keywords as $i => $k) {
            try {
                $a = $creator->create($actor, $site, $k, $cluster['name'], $intents[$k] ?? $cluster['intent'] ?? 'informational', [], $stored->get(Keyword::keyFor($k)), 'keyword');
                if ($times) {
                    PlanData::setPlannedDate($a->id, $times[$i]);
                }
                $results[] = ['keyword' => $k, 'status' => 'created', 'message' => 'Đã tạo bài'.($times ? ' · dự kiến '.$times[$i] : ''), 'article_id' => $a->id];
            } catch (DuplicateKeywordException $e) {
                $results[] = ['keyword' => $k, 'status' => 'duplicate', 'message' => 'Bị chặn: website đã có bài dùng từ khóa này.', 'article_id' => $e->existingArticleId];
            } catch (InvalidArgumentException $e) {
                $results[] = ['keyword' => $k, 'status' => 'error', 'message' => $e->getMessage(), 'article_id' => null];
            }
        }

        return $results;
    }

    /** Đổi ngày dự kiến (giờ VN, lưu tạm). Ném InvalidArgumentException kèm lý do. */
    public function reschedule(User $actor, Article $article, string $day): string
    {
        if (! $actor->is_active || ! $actor->isWriter()) {
            throw new InvalidArgumentException('Vai trò hiện tại không đủ quyền đổi ngày.');
        }
        $article->refresh();
        $site = $article->website;
        $date = CarbonImmutable::parse($day, VnTime::TZ);
        $taken = PlanData::dayTimes(PlanData::articles($site), $article->id)[$date->format('Y-m-d')] ?? [];
        if ($reason = PlanRules::rescheduleBlockReason((string) $article->workflow_status, $date, count($taken))) {
            throw new InvalidArgumentException($reason);
        }
        $slot = PlanRules::firstFreeSlot($taken);
        $dt = $date->format('Y-m-d').' '.$slot;
        if ($date->isSameDay(VnTime::now()) && CarbonImmutable::parse($dt, VnTime::TZ)->lte(VnTime::now())) {
            throw new InvalidArgumentException('Giờ trống còn lại của hôm nay đã qua; chọn ngày khác.');
        }
        PlanData::setPlannedDate($article->id, $dt);

        return $dt;
    }
}
