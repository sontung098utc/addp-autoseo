<?php

namespace App\Services\Keywords;

use App\Contracts\KeywordMetricsProvider;
use App\Models\Keyword;
use App\Models\Website;
use App\Services\Agent\Run\JobRunRecorder;
use Illuminate\Support\Facades\Auth;

/**
 * Điền lượt tìm THẬT cho từ khóa của một website từ nhà cung cấp số liệu (mặc định DataForSEO).
 *
 * - Chọn từ khóa chưa có volume, hoặc số do nhà cung cấp đã cũ hơn refresh_days. Số CSV/nhập tay/Search Console không bị ghi đè trừ options['overwrite'].
 * - Không bao giờ điền số giả: nhà cung cấp thiếu dữ liệu thì volume giữ null (chỉ đánh dấu đã tra, để không tra lại và tốn tiền ngay).
 * - Tối đa max_per_run từ khóa, theo lô; dừng khi chi phí ước tính chạm trần ngân sách phiên.
 * - Ghi MỘT phiên agent_runs (job_type=market_research) với bước `metrics`.
 */
final class KeywordMetricsEnricher
{
    /** Nhãn volume_source được coi là do nhà cung cấp, được phép tra lại khi cũ. */
    private const PROVIDER_SOURCES = ['dataforseo', 'mock'];

    public function __construct(private readonly ?KeywordMetricsProvider $provider = null, private readonly ?float $budgetVnd = null, private readonly bool $demo = false) {}

    /**
     * @param  list<int>|null  $keywordIds  null = mọi từ khóa của website
     * @param  array{overwrite?:bool, limit?:int}  $options
     * @return array{updated:int, skipped:int, cost_vnd:float, provider:string, stopped_reason:?string}
     *
     * @throws KeywordMetricsUnavailable khi chưa có nguồn số liệu
     */
    public function enrich(Website $site, ?array $keywordIds = null, array $options = []): array
    {
        if ($this->provider !== null) {
            $provider = $this->provider;
            $demo = $this->demo || $provider->name() === 'mock';
        } else {
            $r = KeywordMetricsResolver::resolve();
            $provider = $r['provider'];
            $demo = $r['demo'];
        }
        $out = ['updated' => 0, 'skipped' => 0, 'cost_vnd' => 0.0, 'provider' => $provider->name(), 'stopped_reason' => null];

        $ids = $this->select($site, $keywordIds, (bool) ($options['overwrite'] ?? false), $this->limit($options));
        if ($ids === []) {
            return $out;
        }
        $budget = $this->budgetVnd ?? KeywordResearchService::budgetVnd(null);
        $run = JobRunRecorder::start('market_research', $site, ['task' => 'metrics', 'keyword_count' => count($ids), 'provider' => $out['provider']], Auth::user(), 'manual', $demo ? 'mock' : 'real');

        $rate = (float) config('keywords.usd_vnd_rate', 25000);
        $batch = max(1, (int) config('keywords.batch', 100));
        $updatedIds = [];
        foreach (array_chunk($ids, $batch) as $chunk) {
            $est = round(ceil(count($chunk) / 100) * (float) config('keywords.cost_per_100_keywords_vnd', 0), 2);
            if ($est > 0 && $budget < $out['cost_vnd'] + $est) {
                $out['stopped_reason'] = 'Chạm trần chi phí '.number_format($budget, 0, ',', '.').' đ (ước tính). Đã dừng, các từ khóa còn lại chưa tra.';
                $out['skipped'] += count($ids) - $out['updated'] - $out['skipped'];
                break;
            }
            $models = Keyword::query()->where('website_id', $site->id)->whereIn('id', $chunk)->get();
            try {
                $res = $provider->metrics($models->pluck('keyword')->all());
            } catch (KeywordMetricsFailed $e) {
                $out['stopped_reason'] = $e->getMessage();
                $out['skipped'] += count($ids) - $out['updated'] - $out['skipped'];
                break;
            }
            $out['cost_vnd'] = round($out['cost_vnd'] + (float) $res['cost_vnd'], 2);
            $now = now();
            foreach ($models as $k) {
                $row = $res['rows'][Keyword::keyFor($k->keyword)] ?? null;
                if ($row === null || $row['volume'] === null) {
                    $k->update(['metrics_at' => $now]); // đã tra, nhà cung cấp chưa có số: giữ volume null
                    $out['skipped']++;

                    continue;
                }
                $k->update([
                    'volume' => $row['volume'], 'volume_source' => $provider->name(), 'metrics_at' => $now,
                    'competition' => $row['competition'], 'cpc_vnd' => $row['cpc'] !== null ? (int) round($row['cpc'] * $rate) : null,
                ]);
                $out['updated']++;
                $updatedIds[] = $k->id;
            }
        }
        JobRunRecorder::step($run, 'metrics', 'Lấy lượt tìm thật', $out['updated'] > 0 ? 'completed' : ($out['stopped_reason'] ? 'failed' : 'skipped'),
            ['keywords' => count($ids), 'updated' => $out['updated'], 'skipped' => $out['skipped'], 'provider' => $out['provider'], 'cost_is_estimate' => true],
            $out['provider'], 0, $out['cost_vnd'], $out['stopped_reason'] ?? 'Chi phí là ước tính theo cấu hình, không phải hóa đơn.');
        $status = $out['updated'] === 0 && $out['stopped_reason'] !== null ? 'failed' : 'ready_for_review';
        JobRunRecorder::finish($run, $status, ['keyword_ids' => $updatedIds, 'updated' => $out['updated']], $out['stopped_reason']);

        return $out;
    }

    /** Số từ khóa sẽ được tra nếu chạy bây giờ (để hộp xác nhận nói đúng). */
    public function pendingCount(Website $site, ?array $keywordIds = null, array $options = []): int
    {
        return count($this->select($site, $keywordIds, (bool) ($options['overwrite'] ?? false), $this->limit($options)));
    }

    private function limit(array $options): int
    {
        $max = max(1, (int) config('keywords.max_per_run', 300));

        return isset($options['limit']) ? max(1, min($max, (int) $options['limit'])) : $max;
    }

    /** @return list<int> */
    private function select(Website $site, ?array $keywordIds, bool $overwrite, int $limit): array
    {
        $cutoff = now()->subDays(max(1, (int) config('keywords.refresh_days', 30)));
        $q = Keyword::query()->where('website_id', $site->id);
        if ($keywordIds !== null) {
            $q->whereIn('id', $keywordIds === [] ? [0] : $keywordIds);
        }
        if (! $overwrite) {
            $q->where(function ($w) use ($cutoff) {
                // chưa có số, và chưa được tra gần đây
                $w->where(fn ($x) => $x->whereNull('volume')->where(fn ($y) => $y->whereNull('metrics_at')->orWhere('metrics_at', '<', $cutoff)))
                    // hoặc số của nhà cung cấp đã cũ (số CSV/tay không nằm ở đây)
                    ->orWhere(fn ($x) => $x->whereIn('volume_source', self::PROVIDER_SOURCES)->where('metrics_at', '<', $cutoff));
            });
        }

        return $q->orderBy('id')->limit($limit)->pluck('id')->map(fn ($i) => (int) $i)->all();
    }
}
