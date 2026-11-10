<?php

namespace App\Services\Agent\Readiness;

use App\Filament\Support\Audit\LogAuditSink;
use App\Models\Article;
use App\Models\Brain;
use App\Models\User;
use App\Models\Website;
use Carbon\Carbon;

/**
 * Tự gắn Trí tuệ khớp chủ đề vào website khi giao bài, để người dùng không phải tự cấu hình.
 * Chỉ gắn khi chắc: có cụm 2 từ liền nhau trùng và điểm ≥ 50; tối đa 2 Trí tuệ mỗi bài; website đã có ≥ 3 Trí tuệ thì không gắn thêm.
 * Ghi nhật ký audit (ai/hệ thống, lúc nào, vì sao) và lưu dấu vết trong brains.settings['auto_attached'][website_id] (không cần migration)
 * để giao diện hiện "Đã tự gắn … (hoàn tác)". Idempotent: Trí tuệ đã gắn thì không gắn lại.
 */
final class BrainAutoAttach
{
    public const MIN_SCORE = 50;

    public const MAX_PER_ARTICLE = 2;

    public const MAX_PER_SITE = 3;

    /** @return list<array{id:int,name:string,reason:string}> các Trí tuệ vừa được gắn (rỗng nếu không có gì mới) */
    public static function ensureFor(Article $article, ?User $by = null): array
    {
        $site = $article->website;
        if (! $site || trim((string) $article->primary_keyword) === '') {
            return [];
        }
        $done = [];
        foreach (self::candidates($article, $site->brains()->pluck('brains.id')->all()) as $s) {
            $brain = Brain::query()->where('status', 'active')->find($s['id']);
            if (! $brain) {
                continue;
            }
            $priority = (int) ($site->brains()->max('brain_website.priority') ?? 0) + 10;
            $site->brains()->syncWithoutDetaching([$brain->id => ['priority' => $priority]]);
            $at = now('UTC')->toIso8601String();
            $log = (array) ($brain->settings['auto_attached'] ?? []);
            $log[(string) $site->id] = ['at' => $at, 'reason' => $s['reason'], 'by' => $by?->id, 'article_id' => $article->id];
            $brain->update(['settings' => array_merge((array) $brain->settings, ['auto_attached' => $log])]);
            (new LogAuditSink)->write(['action' => 'brain_auto_attach', 'actor_id' => $by?->id, 'actor_role' => $by?->role ?? 'system', 'website_id' => $site->id,
                'brain_id' => $brain->id, 'what' => 'Tự gắn Trí tuệ vào website', 'detail' => ['reason' => $s['reason'], 'score' => $s['score'], 'article_id' => $article->id], 'at_utc' => $at]);
            $done[] = ['id' => $brain->id, 'name' => $brain->name, 'reason' => $s['reason']];
        }

        return $done;
    }

    /**
     * Trí tuệ SẼ được tự gắn (cùng luật với ensureFor, không ghi gì): dùng cho "AI sẽ tự làm gì" trước khi giao.
     *
     * @param  list<int>  $attachedIds
     * @return list<array{id:int,name:string,reason:string,score:int}>
     */
    public static function candidates(Article $article, array $attachedIds): array
    {
        $room = self::MAX_PER_SITE - count($attachedIds);
        if ($room <= 0) {
            return [];
        }
        $ok = array_filter(BrainSuggester::forArticle($article, $attachedIds, self::MAX_PER_ARTICLE), fn ($s) => ($s['bigram'] ?? false) && $s['score'] >= self::MIN_SCORE);

        return array_slice(array_values($ok), 0, $room);
    }

    /** @return list<array{brain_id:int,name:string,at:string,reason:string,auto:bool}> các lần tự gắn gần đây còn hiệu lực, mới nhất trước */
    public static function recent(Website $site, int $days = 14): array
    {
        $since = now('UTC')->subDays($days);
        $out = [];
        foreach ($site->brains()->get() as $b) {
            $e = $b->settings['auto_attached'][(string) $site->id] ?? null;
            if (! is_array($e) || empty($e['at']) || Carbon::parse($e['at'])->lt($since)) {
                continue;
            }
            $out[] = ['brain_id' => $b->id, 'name' => $b->name, 'at' => (string) $e['at'], 'reason' => (string) ($e['reason'] ?? ''), 'auto' => true];
        }
        usort($out, fn ($a, $b) => strcmp($b['at'], $a['at']));

        return $out;
    }

    /** Hoàn tác: gỡ Trí tuệ khỏi website, xóa dấu vết tự gắn, ghi nhật ký. Không đụng dữ liệu của Trí tuệ. */
    public static function detach(Website $site, Brain $brain, ?User $by): void
    {
        $site->brains()->detach($brain->id);
        $log = (array) ($brain->settings['auto_attached'] ?? []);
        unset($log[(string) $site->id]);
        $settings = (array) $brain->settings;
        $log === [] ? $settings = array_diff_key($settings, ['auto_attached' => 1]) : $settings['auto_attached'] = $log;
        $brain->update(['settings' => $settings]);
        (new LogAuditSink)->write(['action' => 'brain_detach', 'actor_id' => $by?->id, 'actor_role' => $by?->role ?? 'system', 'website_id' => $site->id,
            'brain_id' => $brain->id, 'what' => 'Gỡ Trí tuệ khỏi website (hoàn tác tự gắn)', 'detail' => [], 'at_utc' => now('UTC')->toIso8601String()]);
    }
}
