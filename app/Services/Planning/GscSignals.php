<?php

namespace App\Services\Planning;

use App\Filament\Support\Gsc\GscService;
use App\Filament\Support\Gsc\OpportunityFinder;
use App\Filament\Support\Planning\PlannedArticleCreator;
use App\Models\Article;
use App\Models\Keyword;
use App\Models\User;
use App\Models\Website;
use InvalidArgumentException;

/**
 * Tín hiệu từ Search Console THẬT cho kế hoạch nội dung. Chỉ lấy khi website có nguồn thật (không bao giờ dùng số minh họa để chấm điểm).
 * Luật cơ hội dùng lại OpportunityFinder (cơ hội lên trang 1, CTR thấp, tụt hạng) nên số liệu và lý do giống màn Search Console.
 * Không tự tạo gì: candidates() chỉ liệt kê, import() chỉ chạy khi người dùng duyệt.
 */
final class GscSignals
{
    /** Điểm tối đa phần Search Console trong điểm ưu tiên 0–100 của ContentPlanner. */
    public const MAX_POINTS = 25;

    /**
     * @return array{period:string, days:int, queries:array<string,array{keyword:string,clicks:int,impressions:int,ctr:float,position:float,rules:list<string>,reasons:list<string>,points:int}>}|null
     *   null = chưa có Search Console thật cho website, lỗi, hoặc chưa có dữ liệu (kèm lý do qua $why)
     */
    public static function forWebsite(Website $site, int $days = 28, ?string &$why = null, ?GscService $gsc = null): ?array
    {
        $gsc ??= GscService::forWebsite($site);
        if (! $gsc->isReal()) {
            $why = 'Website chưa nối Search Console thật (cần GSC_SERVICE_ACCOUNT_JSON và thêm service account vào thuộc tính của site).';

            return null;
        }
        $rep = $gsc->report($days);
        if ($rep['error'] !== null) {
            $why = 'Search Console lỗi: '.$rep['error'];

            return null;
        }
        if ($rep['empty']) {
            $why = 'Search Console chưa trả dữ liệu trong kỳ đo.';

            return null;
        }
        $items = $rep['items']['query'] ?? [];
        $opps = [];
        foreach (OpportunityFinder::find($items) as $o) {
            $opps[$o['key']][] = $o;
        }
        $maxImp = 0;
        foreach ($items as $it) {
            if (isset($opps[$it['key']])) {
                $maxImp = max($maxImp, (int) $it['cur']['impressions']);
            }
        }
        $q = [];
        foreach ($items as $it) {
            $key = Keyword::keyFor($it['key']);
            $c = $it['cur'];
            $o = $opps[$it['key']] ?? [];
            $rules = array_values(array_unique(array_column($o, 'rule')));
            $points = 0;
            if (in_array(OpportunityFinder::RULE_PAGE1, $rules, true)) {
                $points += 10 + ($maxImp > 0 ? (int) round(12 * $c['impressions'] / $maxImp) : 0); // lượt hiển thị lớn hơn thì cơ hội lớn hơn
            }
            if (in_array(OpportunityFinder::RULE_LOW_CTR, $rules, true)) {
                $points += 3;
            }
            $q[$key] = ['keyword' => (string) $it['key'], 'clicks' => (int) $c['clicks'], 'impressions' => (int) $c['impressions'], 'ctr' => (float) $c['ctr'], 'position' => (float) $c['position'],
                'rules' => $rules, 'reasons' => array_column($o, 'reason'), 'points' => min(self::MAX_POINTS, $points)];
        }

        return ['period' => $rep['periods']['current']['from']->format('d/m/Y').' – '.$rep['periods']['current']['to']->format('d/m/Y'), 'days' => $days, 'queries' => $q];
    }

    /**
     * Truy vấn có cơ hội viết mới (lên trang 1 / CTR thấp) mà website CHƯA có từ khóa hay bài. Tụt hạng không vào đây (đó là việc làm mới bài).
     *
     * @param  array<string,mixed>  $signals  kết quả forWebsite()
     * @return list<array{keyword:string,impressions:int,clicks:int,position:float,rules:list<string>,reasons:list<string>,points:int}>
     */
    public static function candidates(Website $site, array $signals, int $limit = 20): array
    {
        $have = Keyword::query()->where('website_id', $site->id)->pluck('keyword_key')->flip()->all();
        $taken = Article::query()->where('website_id', $site->id)->pluck('primary_keyword')->mapWithKeys(fn ($k) => [Keyword::keyFor((string) $k) => true])->all();
        $rows = [];
        foreach ($signals['queries'] as $key => $s) {
            if ($s['points'] <= 0 || isset($have[$key]) || isset($taken[$key])) {
                continue;
            }
            $rows[] = ['keyword' => $s['keyword'], 'impressions' => $s['impressions'], 'clicks' => $s['clicks'], 'position' => $s['position'], 'rules' => $s['rules'], 'reasons' => $s['reasons'], 'points' => $s['points']];
        }
        usort($rows, fn ($a, $b) => [$b['points'], $b['impressions'], $a['keyword']] <=> [$a['points'], $a['impressions'], $b['keyword']]);

        return array_slice($rows, 0, max(0, $limit));
    }

    /**
     * Người dùng duyệt: đưa các truy vấn Search Console vào kho từ khóa (nguồn gsc, trạng thái mới). Lượt tìm để trống vì impressions KHÔNG phải lượt tìm.
     *
     * @param  list<string>  $queries
     * @return array{added:int, skipped:list<string>}
     */
    public static function import(User $actor, Website $site, array $queries): array
    {
        if (! ContentPlanner::canPlan($actor)) {
            throw new InvalidArgumentException('Vai trò hiện tại không đủ quyền thêm từ khóa.');
        }
        $added = 0;
        $skipped = [];
        foreach (array_unique(array_map(fn ($q) => PlannedArticleCreator::normalize((string) $q), $queries)) as $q) {
            $q = trim((string) $q);
            $key = Keyword::keyFor($q);
            if ($key === '' || mb_strlen($q) > 190) {
                $skipped[] = "{$q}: không hợp lệ";

                continue;
            }
            if (Keyword::query()->where('website_id', $site->id)->where('keyword_key', $key)->exists()) {
                $skipped[] = "{$q}: đã có trong kho từ khóa";

                continue;
            }
            Keyword::create(['website_id' => $site->id, 'keyword' => $q, 'keyword_key' => $key, 'intent' => 'informational', 'source' => 'gsc', 'status' => 'new', 'created_by' => $actor->id]);
            $added++;
        }

        return ['added' => $added, 'skipped' => $skipped];
    }
}
