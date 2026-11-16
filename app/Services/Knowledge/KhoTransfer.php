<?php

namespace App\Services\Knowledge;

use Illuminate\Support\Facades\DB;

/**
 * Chuyển kho Trí tuệ từ máy này sang máy khác (vd. máy Dev2 → server) mà KHÔNG mang theo bí mật và KHÔNG đè dữ liệu có sẵn.
 *
 * Xuất: các Trí tuệ gắn với website được chọn, kèm hồ sơ, nguồn, chủ đề, nguồn tài liệu, mục kiến thức (và bản sử), ra một mảng JSON.
 * Không xuất: người dùng, nhà cung cấp AI, thông tin kết nối website, bài viết, lượt đăng. Các cột trỏ tới người dùng được để trống khi nhập (id người dùng ở máy khác không khớp).
 * Nhập: chỉ THÊM. Trí tuệ có cùng slug đã tồn tại thì bỏ qua cả Trí tuệ đó (không đè, không nhân đôi). Id được cấp lại và nối lại theo ánh xạ.
 * Website được nối theo địa chỉ gốc (base_url); không khớp thì Trí tuệ vẫn được nhập nhưng chưa gắn website nào.
 */
final class KhoTransfer
{
    public const VERSION = 1;

    /** @param list<int> $websiteIds */
    public static function export(array $websiteIds): array
    {
        $sites = DB::table('websites')->whereIn('id', $websiteIds)->get(['id', 'base_url'])->keyBy('id');
        $brainIds = DB::table('brain_website')->whereIn('website_id', $websiteIds)->pluck('brain_id')->unique()->values();
        $out = ['version' => self::VERSION, 'exported_at' => now()->toIso8601String(), 'brains' => []];
        foreach (DB::table('brains')->whereIn('id', $brainIds)->orderBy('id')->get() as $brain) {
            $items = DB::table('knowledge_items')->where('brain_id', $brain->id)->whereNull('deleted_at')->get();
            $sourceIds = $items->pluck('source_id')->filter()->merge(DB::table('knowledge_sources')->where('brain_id', $brain->id)->pluck('id'))->unique()->values();
            $topicIds = $items->pluck('topic_id')->filter()->merge(DB::table('knowledge_topics')->where('brain_id', $brain->id)->pluck('id'))->unique()->values()->all();
            $topics = collect();
            while ($topicIds !== []) { // lấy luôn chủ đề cha
                $rows = DB::table('knowledge_topics')->whereIn('id', $topicIds)->whereNotIn('id', $topics->pluck('id'))->get();
                $topics = $topics->concat($rows);
                $topicIds = $rows->pluck('parent_id')->filter()->unique()->diff($topics->pluck('id'))->values()->all();
            }
            $out['brains'][] = [
                'brain' => (array) $brain,
                'profile' => ($p = DB::table('brain_profiles')->where('brain_id', $brain->id)->first()) ? (array) $p : null,
                'sources' => DB::table('brain_sources')->where('brain_id', $brain->id)->get()->map(fn ($r) => (array) $r)->all(),
                'websites' => DB::table('brain_website')->where('brain_id', $brain->id)->whereIn('website_id', $websiteIds)->get()
                    ->map(fn ($r) => ['base_url' => $sites[$r->website_id]->base_url, 'priority' => $r->priority])->all(),
                'topics' => $topics->map(fn ($r) => (array) $r)->all(),
                'knowledge_sources' => DB::table('knowledge_sources')->whereIn('id', $sourceIds)->get()->map(fn ($r) => (array) $r)->all(),
                'items' => $items->map(fn ($r) => (array) $r)->all(),
                'versions' => DB::table('knowledge_item_versions')->whereIn('item_id', $items->pluck('id'))->get()->map(fn ($r) => (array) $r)->all(),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string,int>  $siteMap  base_url (đã chuẩn hóa) => website_id trên máy này, ghi đè việc tự nối theo địa chỉ
     * @return array{brains_added:int, brains_skipped:list<string>, items:int, sources:int, topics:int, versions:int, attached:int, unmatched_sites:list<string>}
     */
    public static function import(array $data, array $siteMap = [], bool $dryRun = false): array
    {
        if (($data['version'] ?? null) !== self::VERSION) {
            throw new \InvalidArgumentException('Tệp xuất không đúng phiên bản (cần '.self::VERSION.').');
        }
        $local = DB::table('websites')->get(['id', 'base_url'])->mapWithKeys(fn ($w) => [self::norm((string) $w->base_url) => (int) $w->id])->all();
        $map = $siteMap;
        foreach ($siteMap as $k => $v) {
            $map[self::norm($k)] = $v;
        }
        $sum = ['brains_added' => 0, 'brains_skipped' => [], 'items' => 0, 'sources' => 0, 'topics' => 0, 'versions' => 0, 'attached' => 0, 'unmatched_sites' => []];

        DB::beginTransaction();
        try {
            foreach ($data['brains'] ?? [] as $b) {
                $row = $b['brain'];
                if (DB::table('brains')->where('slug', $row['slug'])->exists()) {
                    $sum['brains_skipped'][] = (string) $row['name'];

                    continue;
                }
                $brainId = DB::table('brains')->insertGetId(self::clean($row, ['id'], ['created_by']));
                $sum['brains_added']++;
                if (! empty($b['profile'])) {
                    DB::table('brain_profiles')->insert(array_merge(self::clean($b['profile'], ['id'], ['updated_by']), ['brain_id' => $brainId]));
                }
                foreach ($b['sources'] ?? [] as $s) {
                    DB::table('brain_sources')->insert(['brain_id' => $brainId] + self::clean($s, ['id'], ['created_by', 'confirmed_by']));
                }
                $siteId = null;
                foreach ($b['websites'] ?? [] as $w) {
                    $key = self::norm((string) $w['base_url']);
                    $id = $map[$key] ?? $local[$key] ?? null;
                    if ($id === null) {
                        $sum['unmatched_sites'][] = (string) $w['base_url'];

                        continue;
                    }
                    $siteId ??= $id;
                    $sum['attached'] += DB::table('brain_website')->insertOrIgnore(['brain_id' => $brainId, 'website_id' => $id, 'priority' => $w['priority'] ?? 1, 'created_at' => now(), 'updated_at' => now()]);
                }
                $topicMap = [];
                $pending = $b['topics'] ?? [];
                for ($guard = 0; $pending !== [] && $guard < 20; $guard++) { // cha trước, con sau
                    $left = [];
                    foreach ($pending as $t) {
                        if ($t['parent_id'] !== null && ! isset($topicMap[$t['parent_id']])) {
                            $left[] = $t;

                            continue;
                        }
                        $topicMap[$t['id']] = DB::table('knowledge_topics')->insertGetId(array_merge(self::clean($t, ['id'], []),
                            ['brain_id' => $brainId, 'website_id' => $siteId, 'parent_id' => $t['parent_id'] === null ? null : $topicMap[$t['parent_id']]]));
                        $sum['topics']++;
                    }
                    if (count($left) === count($pending)) {
                        break;
                    }
                    $pending = $left;
                }
                $sourceMap = [];
                foreach ($b['knowledge_sources'] ?? [] as $s) {
                    $sourceMap[$s['id']] = DB::table('knowledge_sources')->insertGetId(['brain_id' => $brainId, 'website_id' => $siteId, 'created_by' => null]
                        + self::clean($s, ['id'], ['created_by', 'website_id', 'brain_id']));
                    $sum['sources']++;
                }
                $itemMap = [];
                foreach ($b['items'] ?? [] as $i) {
                    $itemMap[$i['id']] = DB::table('knowledge_items')->insertGetId(['brain_id' => $brainId, 'website_id' => $siteId, 'approved_by' => null,
                        'source_id' => ($i['source_id'] ?? null) !== null ? ($sourceMap[$i['source_id']] ?? null) : null,
                        'topic_id' => ($i['topic_id'] ?? null) !== null ? ($topicMap[$i['topic_id']] ?? null) : null]
                        + self::clean($i, ['id', 'deleted_at'], ['approved_by', 'created_by', 'website_id', 'brain_id', 'source_id', 'topic_id']));
                    $sum['items']++;
                }
                foreach ($b['versions'] ?? [] as $v) {
                    if (isset($itemMap[$v['item_id']])) {
                        DB::table('knowledge_item_versions')->insert(['item_id' => $itemMap[$v['item_id']], 'changed_by' => null] + self::clean($v, ['id'], ['changed_by', 'item_id']));
                        $sum['versions']++;
                    }
                }
            }
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        $sum['unmatched_sites'] = array_values(array_unique($sum['unmatched_sites']));

        return $sum;
    }

    /** Bỏ cột $drop; đặt NULL cho cột $null (id người dùng, khóa ngoại sẽ được gán lại). */
    private static function clean(array $row, array $drop, array $null): array
    {
        foreach ($drop as $c) {
            unset($row[$c]);
        }
        foreach ($null as $c) {
            if (array_key_exists($c, $row)) {
                $row[$c] = null;
            }
        }

        return $row;
    }

    private static function norm(string $url): string
    {
        return rtrim(strtolower(preg_replace('#^https?://(www\.)?#i', '', trim($url)) ?? ''), '/');
    }
}
