<?php

namespace App\Services\Planning;

use App\Filament\Support\Planning\DuplicateKeywordException;
use App\Filament\Support\Planning\PlannedArticleCreator;
use App\Models\Article;
use App\Models\Keyword;
use App\Models\KeywordGap;
use App\Models\User;
use App\Models\Website;
use App\Services\Brand\BrandProfileData;
use InvalidArgumentException;

/**
 * Loại việc content_plan (luật, 0 token, không mạng). propose() chỉ tính; create() tạo bài planned.
 * Điểm ưu tiên 0–100 (cùng thang articles.priority, cao = làm trước): lượt tìm tối đa 50, độ phủ cụm tối đa 30,
 * khoảng trống đối thủ tối đa 20; lệch chủ đề trừ 30.
 */
final class ContentPlanner
{
    /** Nhãn nguồn số liệu lượt tìm hiện ở lý do đề xuất. */
    private const VOLUME_SOURCES = ['csv' => 'CSV', 'tay' => 'nhập tay', 'dataforseo' => 'DataForSEO', 'gsc' => 'Search Console', 'mock' => 'giả lập'];

    /** Cụm đã có 0/1/2/3+ bài → điểm độ phủ. */
    private const COVERAGE = [30, 20, 10, 0];

    /** @return list<array{keyword_id:int,keyword:string,cluster:?string,volume:?int,volume_source:?string,priority:int,reasons:list<string>,off_topic:bool}> */
    public function propose(Website $site, int $limit = 10): array
    {
        $profile = BrandProfileData::forWebsite($site->id);
        $clusterPosts = Article::where('website_id', $site->id)->whereNotNull('cluster_id')
            ->selectRaw('cluster_id, count(*) as n')->groupBy('cluster_id')->pluck('n', 'cluster_id')->all();
        $gaps = KeywordGap::where('website_id', $site->id)->whereNotNull('priority_score')
            ->whereNotIn('status', ['da_them_tu_khoa', 'bo_qua'])->get()
            ->groupBy(fn (KeywordGap $g) => Keyword::keyFor($g->keyword))->map(fn ($g) => (int) $g->max('priority_score'));

        $taken = Article::where('website_id', $site->id)->pluck('primary_keyword')
            ->mapWithKeys(fn ($k) => [PlannedArticleCreator::normalize((string) $k) => true])->all();
        $cands = Keyword::with('cluster')->where('website_id', $site->id)->where('status', 'new')->get()
            ->reject(fn (Keyword $k) => isset($taken[PlannedArticleCreator::normalize($k->keyword)]));

        $maxVol = (int) $cands->max('volume');
        $rows = [];
        foreach ($cands as $k) {
            $reasons = [];
            $score = 0;
            if ($k->volume === null) {
                $reasons[] = 'chưa có số liệu lượt tìm';
            } else {
                $v = $maxVol > 0 ? (int) round(50 * $k->volume / $maxVol) : 0;
                $score += $v;
                $src = self::VOLUME_SOURCES[$k->volume_source ?? ''] ?? null;
                $reasons[] = 'lượt tìm '.number_format($k->volume, 0, ',', '.').($src ? ' ('.$src.')' : '');
            }
            if ($k->cluster_id) {
                $n = (int) ($clusterPosts[$k->cluster_id] ?? 0);
                $score += self::COVERAGE[min($n, 3)];
                $reasons[] = $n === 0 ? 'cụm chưa có bài' : "cụm đã có $n bài";
            }
            if (isset($gaps[Keyword::keyFor($k->keyword)])) {
                $g = min(100, $gaps[Keyword::keyFor($k->keyword)]);
                $score += (int) round($g / 5);
                $reasons[] = "khoảng trống đối thủ (điểm $g)";
            }
            $off = BrandProfileData::looksOffTopic($profile, $k->keyword);
            if ($off) {
                $score = max(0, $score - 30);
                $reasons[] = 'có vẻ lệch lĩnh vực trong hồ sơ doanh nghiệp';
            }
            $rows[] = ['keyword_id' => $k->id, 'keyword' => $k->keyword, 'cluster' => $k->cluster?->name, 'volume' => $k->volume, 'volume_source' => $k->volume_source,
                'priority' => min(100, $score), 'reasons' => $reasons, 'off_topic' => $off];
        }
        usort($rows, fn ($a, $b) => [$a['off_topic'], $b['priority'], $b['volume'] === null ? -1 : $b['volume'], $a['keyword']]
            <=> [$b['off_topic'], $a['priority'], $a['volume'] === null ? -1 : $a['volume'], $b['keyword']]);

        return array_slice($rows, 0, max(0, $limit));
    }

    /** @param  list<int>  $keywordIds  @return array{created:int,skipped:list<string>} */
    public function create(User $actor, Website $site, array $keywordIds): array
    {
        if (! $actor->is_active || ! $actor->isWriter()) {
            throw new InvalidArgumentException('Vai trò hiện tại không đủ quyền tạo bài.');
        }
        if ($site->status !== 'active') {
            throw new InvalidArgumentException('Website đích chưa ở trạng thái Hoạt động.');
        }
        $byId = collect($this->propose($site, PHP_INT_MAX))->keyBy('keyword_id');
        $profile = BrandProfileData::forWebsite($site->id);
        $creator = new PlannedArticleCreator;
        $created = 0;
        $skipped = [];
        foreach (array_unique(array_map('intval', $keywordIds)) as $id) {
            $kw = Keyword::where('website_id', $site->id)->find($id);
            if (! $kw) {
                $skipped[] = "#$id: không thuộc website này";

                continue;
            }
            if (PlannedArticleCreator::existing($site, $kw->keyword)) {
                $skipped[] = "{$kw->keyword}: đã có bài";

                continue;
            }
            if (BrandProfileData::looksOffTopic($profile, $kw->keyword)) {
                $skipped[] = "{$kw->keyword}: có vẻ lệch lĩnh vực trong hồ sơ doanh nghiệp";

                continue;
            }
            if (! isset($byId[$id])) {
                $skipped[] = "{$kw->keyword}: không còn trong danh sách đề xuất (chưa ở trạng thái mới)";

                continue;
            }
            try {
                $a = $creator->create($actor, $site, $kw->keyword, $kw->cluster?->name, $kw->intent ?: 'informational', [], $kw, 'plan');
                $a->update(['priority' => $byId[$id]['priority']]);
                $created++;
            } catch (DuplicateKeywordException) {
                $skipped[] = "{$kw->keyword}: đã có bài";
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }
}
