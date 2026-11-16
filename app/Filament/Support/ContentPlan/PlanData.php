<?php

namespace App\Filament\Support\ContentPlan;

use App\Filament\Support\Demo\DemoStore;
use App\Filament\Support\Planning\PlannedArticleCreator;
use App\Filament\Support\Schedule\VnTime;
use App\Models\Article;
use App\Models\Publication;
use App\Models\Website;
use Carbon\CarbonImmutable;

/** Đọc dữ liệu THẬT cho màn Kế hoạch nội dung. Ngày dự kiến của bài "Đã lên kế hoạch" lưu tạm ở DemoStore. */
final class PlanData
{
    /** @return array<int, string> id => "Y-m-d H:i" giờ VN */
    public static function plannedDates(): array
    {
        return (array) DemoStore::get('contentplan.dates', []);
    }

    public static function setPlannedDate(int $articleId, string $vnDateTime): void
    {
        $d = self::plannedDates();
        $d[$articleId] = $vnDateTime;
        DemoStore::put('contentplan.dates', $d);
    }

    /**
     * Bài của website kèm ngày (giờ VN) nếu có: bài đã xếp lịch/đã đăng lấy từ publications, bài planned lấy từ DemoStore.
     *
     * @return list<array{id:int, keyword:string, title:string, status:string, cluster:?string, campaign_id:?int, at:?CarbonImmutable, source:string}>
     */
    public static function articles(Website $site): array
    {
        $articles = Article::with('latestVersion')->where('website_id', $site->id)->orderBy('id')->get();
        $pubs = Publication::with('articleVersion')->where('website_id', $site->id)->get()
            ->groupBy(fn (Publication $p) => $p->articleVersion?->article_id);
        $planned = self::plannedDates();
        $out = [];
        foreach ($articles as $a) {
            $at = null;
            $source = 'none';
            $p = $pubs->get($a->id)?->sortByDesc('id')->first();
            if ($p) {
                $raw = $p->published_at_utc ? VnTime::fromRaw($p, 'published_at_utc') : VnTime::fromRaw($p, 'scheduled_at_utc');
                $at = $raw ? VnTime::toVn($raw) : null;
                $source = $at ? 'publication' : 'none';
            } elseif ($a->workflow_status === 'planned' && isset($planned[$a->id])) {
                $at = CarbonImmutable::parse($planned[$a->id], VnTime::TZ);
                $source = 'planned';
            }
            $out[] = ['id' => $a->id, 'keyword' => (string) $a->primary_keyword, 'title' => (string) ($a->latestVersion?->title ?: $a->primary_keyword), 'status' => (string) $a->workflow_status, 'cluster' => $a->topic_cluster, 'campaign_id' => $a->campaign_id, 'at' => $at, 'source' => $source];
        }

        return $out;
    }

    /** Số bài theo từng ngày VN (Y-m-d) và giờ đã dùng. @return array<string, list<string>> ngày => các "H:i" */
    public static function dayTimes(array $articles, ?int $exceptId = null): array
    {
        $d = [];
        foreach ($articles as $a) {
            if ($a['at'] && $a['id'] !== $exceptId) {
                $d[$a['at']->format('Y-m-d')][] = $a['at']->format('H:i');
            }
        }

        return $d;
    }

    /** Bài thuộc cụm: topic_cluster trùng tên hoặc từ khóa chính thuộc cụm. */
    public static function inCluster(array $articles, array $cluster): array
    {
        $kw = array_flip(array_map([PlannedArticleCreator::class, 'normalize'], $cluster['keywords']));

        return array_values(array_filter($articles, fn ($a) => $a['cluster'] === $cluster['name'] || isset($kw[PlannedArticleCreator::normalize($a['keyword'])])));
    }
}
