<?php

namespace App\Filament\Support\AiUsage;

use App\Filament\Support\Schedule\VnTime;
use Carbon\CarbonImmutable;

/**
 * Tính số liệu Chi phí AI bằng PHP thuần từ các dòng article_versions đã đọc (xem UsageReader).
 *
 * Mỗi dòng: id, article_id, website_id, website, title, model, provider, prompt, tokens, cost (float|null), at (CarbonImmutable).
 * Kỳ là nửa mở [from, to) theo giờ Asia/Ho_Chi_Minh.
 *
 * Định nghĩa (hiện nguyên văn trên giao diện):
 *  - Chi phí kỳ = tổng cost_vnd các phiên bản được sinh TRONG kỳ.
 *  - TB/bài = tổng cost_vnd của MỌI phiên bản thuộc các bài có phiên bản sinh trong kỳ / số bài đó (sinh lại tính vào bài).
 *  - "Chưa ghi chi phí" = cost_vnd null, hoặc cost_vnd = 0 mà tokens_used = 0 (pipeline ghi 0 khi nguồn không trả số liệu).
 *    Các dòng này KHÔNG cộng như 0 đồng vào trung bình: bài chỉ gồm dòng như vậy bị loại khỏi mẫu số.
 */
final class UsageAggregator
{
    /** @var list<array<string,mixed>> */
    private array $rows;

    /** @var array<int,list<array<string,mixed>>> article_id => mọi phiên bản */
    private array $byArticle = [];

    /** @param list<array<string,mixed>> $rows toàn bộ phiên bản (mọi thời điểm) trong phạm vi website đang xem */
    public function __construct(array $rows)
    {
        $this->rows = array_values($rows);
        foreach ($this->rows as $r) {
            if ($r['article_id'] === 0) {
                continue; // phiên không gắn bài (G2): cộng vào chi phí, không đếm là bài
            }
            $this->byArticle[$r['article_id']][] = $r;
        }
    }

    public static function unrecorded(array $r): bool
    {
        return $r['cost'] === null || ((float) $r['cost'] == 0.0 && (int) $r['tokens'] === 0);
    }

    /** @return list<array<string,mixed>> */
    private function inPeriod(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return array_values(array_filter($this->rows, fn ($r) => $r['at']->gte($from) && $r['at']->lt($to)));
    }

    /** Tổng chi phí đã ghi của một tập dòng; null nếu không dòng nào đã ghi. */
    private static function recordedCost(array $rows): ?float
    {
        $sum = null;
        foreach ($rows as $r) {
            if (! self::unrecorded($r)) {
                $sum = ($sum ?? 0.0) + (float) $r['cost'];
            }
        }

        return $sum;
    }

    /** @return array<string,mixed>|null null khi không có phiên bản nào trong kỳ */
    public function summary(CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        $in = $this->inPeriod($from, $to);
        if ($in === []) {
            return null;
        }
        $articleIds = array_values(array_filter(array_unique(array_column($in, 'article_id'))));
        $totalAll = 0.0;
        $articlesWithCost = 0;
        $regen = 0;
        $extra = 0;
        foreach ($articleIds as $aid) {
            $all = $this->byArticle[$aid];
            $c = self::recordedCost($all);
            if ($c !== null) {
                $totalAll += $c;
                $articlesWithCost++;
            }
            if (count($all) >= 2) {
                $regen++;
                $extra += count($all) - 1;
            }
        }

        return [
            'versions' => count($in),
            'articles' => count($articleIds),
            'tokens' => array_sum(array_column($in, 'tokens')),
            'cost' => self::recordedCost($in),
            'unrecorded' => count(array_filter($in, fn ($r) => self::unrecorded($r))),
            'avg_per_article' => $articlesWithCost > 0 ? $totalAll / $articlesWithCost : null,
            'articles_with_cost' => $articlesWithCost,
            'regen_articles' => $regen,
            'extra_versions' => $extra,
        ];
    }

    /** Chi phí các phiên bản sinh trong tháng dương lịch (giờ VN) chứa $anyDayInMonth. */
    public function monthCost(CarbonImmutable $anyDayInMonth): ?float
    {
        $start = $anyDayInMonth->setTimezone(VnTime::TZ)->startOfMonth();

        return self::recordedCost($this->inPeriod($start, $start->addMonth()));
    }

    /** @return list<array<string,mixed>> mỗi ngày VN trong kỳ một dòng (ngày không có phiên bản: versions = 0) */
    public function daily(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = [];
        for ($d = $from->startOfDay(); $d->lt($to); $d = $d->addDay()) {
            $days[$d->format('Y-m-d')] = ['date' => $d, 'versions' => 0, 'tokens' => 0, 'cost' => 0.0, 'unrecorded' => 0, 'by_model' => []];
        }
        foreach ($this->inPeriod($from, $to) as $r) {
            $k = $r['at']->setTimezone(VnTime::TZ)->format('Y-m-d');
            $days[$k]['versions']++;
            $days[$k]['tokens'] += (int) $r['tokens'];
            if (self::unrecorded($r)) {
                $days[$k]['unrecorded']++;
            } else {
                $days[$k]['cost'] += (float) $r['cost'];
                $m = $r['model'] ?: '(không rõ)';
                $days[$k]['by_model'][$m] = ($days[$k]['by_model'][$m] ?? 0.0) + (float) $r['cost'];
            }
        }

        return array_values($days);
    }

    /**
     * Nhóm theo model|prompt|website. TB/bài ở đây tính TRONG NHÓM: chi phí các phiên bản của nhóm trong kỳ / số bài khác nhau của nhóm.
     *
     * @param  'model'|'prompt'|'website'  $by
     * @return list<array<string,mixed>> sắp theo chi phí giảm dần
     */
    public function groupBy(string $by, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $in = $this->inPeriod($from, $to);
        $groups = [];
        foreach ($in as $r) {
            $key = match ($by) {
                'model' => $r['model'] ?: '(không rõ)',
                'prompt' => $r['prompt'] ?: '(không rõ)',
                'website' => (string) $r['website_id'],
            };
            $groups[$key][] = $r;
        }
        $total = (float) (self::recordedCost($in) ?? 0.0);
        $out = [];
        foreach ($groups as $key => $rs) {
            $cost = self::recordedCost($rs);
            $recordedCount = count(array_filter($rs, fn ($r) => ! self::unrecorded($r)));
            $articles = count(array_filter(array_unique(array_column($rs, 'article_id'))));
            $articlesCost = count(array_filter(array_unique(array_column(array_filter($rs, fn ($r) => ! self::unrecorded($r)), 'article_id'))));
            $out[] = [
                'key' => $key,
                'label' => $by === 'website' ? ($rs[0]['website'] ?? $key) : $key,
                'provider' => $rs[0]['provider'] ?? null,
                'versions' => count($rs),
                'articles' => $articles,
                'tokens' => array_sum(array_column($rs, 'tokens')),
                'cost' => $cost,
                'unrecorded' => count($rs) - $recordedCount,
                'avg_per_version' => $cost !== null && $recordedCount > 0 ? $cost / $recordedCount : null,
                'avg_per_article' => $cost !== null && $articlesCost > 0 ? $cost / $articlesCost : null,
                'share' => $cost !== null && $total > 0 ? $cost / $total : null,
            ];
        }
        usort($out, fn ($a, $b) => [$b['cost'] ?? -1, $a['label']] <=> [$a['cost'] ?? -1, $b['label']]);

        return $out;
    }

    /**
     * Bài có phiên bản trong kỳ, xếp theo tổng chi phí MỌI phiên bản của bài (giảm dần; hòa thì article_id nhỏ trước).
     *
     * @return list<array<string,mixed>>
     */
    public function topArticles(CarbonImmutable $from, CarbonImmutable $to, int $limit = 10): array
    {
        $ids = array_values(array_filter(array_unique(array_column($this->inPeriod($from, $to), 'article_id'))));
        $out = [];
        foreach ($ids as $aid) {
            $all = $this->byArticle[$aid];
            $cost = self::recordedCost($all);
            if ($cost === null) {
                continue;
            }
            usort($all, fn ($a, $b) => $b['id'] <=> $a['id']);
            $latest = $all[0];
            $out[] = [
                'article_id' => $aid,
                'version_id' => $latest['id'],
                'title' => $latest['title'],
                'website' => $latest['website'],
                'versions' => count($all),
                'tokens' => array_sum(array_column($all, 'tokens')),
                'cost' => $cost,
                'models' => array_values(array_unique(array_filter(array_column($all, 'model')))),
            ];
        }
        usort($out, fn ($a, $b) => [$b['cost'], $a['article_id']] <=> [$a['cost'], $b['article_id']]);

        return array_slice($out, 0, $limit);
    }
}
