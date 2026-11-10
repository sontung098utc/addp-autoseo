<?php

namespace App\Filament\Support\Keywords;

use App\Models\Keyword;
use App\Models\KeywordCluster;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Kho từ khóa THẬT (bảng keywords + keyword_clusters), theo từng website. Cùng hợp đồng với DemoKeywordRepository.
 * Đề xuất gom cụm chỉ là bản nháp tạm (cache), không phải dữ liệu thật nên không lưu bảng.
 * volume/difficulty giữ NULL khi nguồn không cung cấp; không bao giờ điền số giả.
 */
final class EloquentKeywordRepository implements KeywordRepository
{
    public function __construct(private readonly int $websiteId) {}

    public function websiteId(): int
    {
        return $this->websiteId;
    }

    public function all(): array
    {
        return Keyword::where('website_id', $this->websiteId)->with('cluster:id,name')->orderBy('id')->get()
            ->map(fn (Keyword $k) => [
                'id' => $k->id,
                'keyword' => $k->keyword,
                'volume' => $k->volume,
                'difficulty' => $k->difficulty,
                'intent' => $k->intent,
                'source' => $k->source,
                'cluster' => $k->cluster?->name,
                'status' => $k->status,
            ])->all();
    }

    public function clusters(): array
    {
        return KeywordCluster::where('website_id', $this->websiteId)->with('keywords:id,cluster_id,keyword')->orderBy('id')->get()
            ->map(fn (KeywordCluster $c) => [
                'name' => $c->name,
                'intent' => $c->intent,
                'keywords' => $c->keywords->sortBy('id')->pluck('keyword')->values()->all(),
            ])->all();
    }

    public function proposals(): array
    {
        return array_values((array) Cache::get($this->proposalKey(), []));
    }

    public function saveList(array $list): void
    {
        DB::transaction(function () use ($list) {
            $clusterIds = KeywordCluster::where('website_id', $this->websiteId)->pluck('id', 'name')->all();
            $keep = [];
            foreach ($list as $r) {
                $key = Keyword::keyFor($r['keyword']);
                $keep[] = $key;
                $attrs = [
                    'keyword' => CsvKeywordParser::normalizeKeyword($r['keyword']),
                    'volume' => $r['volume'] ?? null,
                    'difficulty' => $r['difficulty'] ?? null,
                    'intent' => $r['intent'] ?? KeywordIntent::DEFAULT,
                    'cluster_id' => isset($r['cluster']) ? ($clusterIds[$r['cluster']] ?? null) : null,
                ];
                $existing = Keyword::where('website_id', $this->websiteId)->where('keyword_key', $key)->first();
                if ($existing) {
                    $existing->update($attrs);

                    continue;
                }
                Keyword::create($attrs + [
                    'website_id' => $this->websiteId,
                    'keyword_key' => $key,
                    'source' => $r['source'] ?? 'tay',
                    'status' => 'new',
                    'created_by' => Auth::id(),
                ]);
            }
            Keyword::where('website_id', $this->websiteId)->whereNotIn('keyword_key', $keep)->delete();
        });
    }

    public function saveClusters(array $clusters): void
    {
        DB::transaction(function () use ($clusters) {
            $names = [];
            $byKey = [];
            foreach ($clusters as $c) {
                $names[] = $c['name'];
                $model = KeywordCluster::updateOrCreate(
                    ['website_id' => $this->websiteId, 'name' => $c['name']],
                    ['intent' => $c['intent'] ?? KeywordIntent::DEFAULT],
                );
                foreach ($c['keywords'] as $k) {
                    $byKey[Keyword::keyFor($k)] ??= $model->id;
                }
            }
            Keyword::where('website_id', $this->websiteId)->update(['cluster_id' => null]);
            foreach ($byKey as $key => $clusterId) {
                Keyword::where('website_id', $this->websiteId)->where('keyword_key', $key)->update(['cluster_id' => $clusterId]);
            }
            KeywordCluster::where('website_id', $this->websiteId)->whereNotIn('name', $names)->delete();
        });
    }

    public function saveProposals(array $proposals): void
    {
        Cache::put($this->proposalKey(), array_values($proposals), now()->addDay());
    }

    public function proposalSource(): string
    {
        return Cache::get($this->proposalKey().'.source') === 'ai' ? 'ai' : 'mock';
    }

    public function saveProposalSource(string $source): void
    {
        Cache::put($this->proposalKey().'.source', $source === 'ai' ? 'ai' : 'mock', now()->addDay());
    }

    private function proposalKey(): string
    {
        return 'studio.keywords.proposals.'.$this->websiteId;
    }
}
