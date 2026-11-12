<?php

namespace App\Filament\Support\AiUsage;

use App\Filament\Support\Schedule\VnTime;
use App\Services\Agent\Pipeline\JobTypeRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Đọc THẬT article_versions (+ articles, websites). Không đọc html_content. */
final class UsageReader
{
    /** @return list<array<string,mixed>> */
    public static function rows(?int $websiteId = null): array
    {
        $q = DB::table('article_versions as v')
            ->join('articles as a', 'a.id', '=', 'v.article_id')
            ->join('websites as w', 'w.id', '=', 'a.website_id')
            ->select('v.id', 'v.article_id', 'a.website_id', 'w.name as website', 'v.title', 'v.ai_provider', 'v.ai_model', 'v.prompt_version', 'v.tokens_used', 'v.cost_vnd', 'v.created_at');
        if ($websiteId !== null) {
            $q->where('a.website_id', $websiteId);
        }
        // Model của hai nhà cung cấp GIẢ (MockAiProvider 'mock-*', DemoScriptedAiProvider 'demo-*') không phải chi phí thật: mặc định ẩn khỏi mọi số liệu.
        if (! config('agent.usage.include_fake_models', false)) {
            $q->where(fn ($w) => $w->whereNull('v.ai_model')->orWhere(fn ($m) => $m->where('v.ai_model', 'not like', 'mock-%')->where('v.ai_model', 'not like', 'demo-%')));
        }

        // created_at do Eloquent ghi theo app.timezone (không phải UTC): đọc theo múi giờ đó rồi đổi sang giờ VN.
        $rows = $q->orderBy('v.id')->get()->map(fn ($r) => [
            'id' => (int) $r->id,
            'article_id' => (int) $r->article_id,
            'website_id' => (int) $r->website_id,
            'website' => (string) $r->website,
            'title' => (string) $r->title,
            'provider' => $r->ai_provider,
            'model' => $r->ai_model,
            'prompt' => $r->prompt_version,
            'tokens' => (int) $r->tokens_used,
            'cost' => $r->cost_vnd === null ? null : (float) $r->cost_vnd,
            'at' => CarbonImmutable::parse($r->created_at, config('app.timezone'))->setTimezone(VnTime::TZ),
        ])->all();

        return array_merge(self::withRunOverhead($rows, $websiteId), self::runRows($websiteId));
    }

    /**
     * Phiên bản chỉ ghi chi phí bước VIẾT; lượt chạy còn tốn ở dàn ý, nghiên cứu, chấm... (agent_runs.cost_vnd là tổng cả luồng).
     * Phần chênh của mỗi lượt được cộng vào phiên bản cuối của chính lượt đó, nên số bài và số phiên bản không đổi.
     * Lượt có bài nhưng không ra phiên bản (thất bại) vẫn tốn tiền: thành một dòng article_id = 0 (chỉ vào chi phí, không đếm là bài).
     *
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private static function withRunOverhead(array $rows, ?int $websiteId): array
    {
        if (! Schema::hasTable('agent_runs') || ! Schema::hasColumn('agent_runs', 'final_version_id')) {
            return $rows;
        }
        $index = [];
        $articleCost = []; // tổng chi phí đã ghi của MỌI phiên bản mỗi bài: chặn tính trùng khi bài có nhiều phiên bản/lượt chạy
        foreach ($rows as $i => $r) {
            $index[$r['id']] = $i;
            $articleCost[$r['article_id']] = ($articleCost[$r['article_id']] ?? 0.0) + (float) ($r['cost'] ?? 0);
        }
        $q = DB::table('agent_runs as r')->leftJoin('websites as w', 'w.id', '=', 'r.website_id')->join('articles as a', 'a.id', '=', 'r.article_id')
            ->whereNotNull('r.article_id')->where('r.cost_vnd', '>', 0)
            ->select('r.id', 'r.website_id', 'w.name as website', 'r.final_version_id', 'r.tokens_used', 'r.cost_vnd', 'r.created_at', 'a.primary_keyword');
        if ($websiteId !== null) {
            $q->where('r.website_id', $websiteId);
        }
        $extra = [];
        foreach ($q->orderBy('r.id')->get() as $r) {
            if ($r->final_version_id !== null) {
                $i = $index[(int) $r->final_version_id] ?? null;
                if ($i === null) {
                    continue; // phiên bản không có trong phạm vi (vd model giả bị ẩn): bỏ cả lượt
                }
                $rows[$i]['cost'] = (float) ($rows[$i]['cost'] ?? 0) + max(0.0, (float) $r->cost_vnd - $articleCost[$rows[$i]['article_id']]);
                $rows[$i]['tokens'] = max((int) $rows[$i]['tokens'], (int) $r->tokens_used);
            } else {
                $extra[] = [
                    'id' => 1000000000 + (int) $r->id, 'article_id' => 0, 'website_id' => (int) $r->website_id, 'website' => (string) ($r->website ?? ''),
                    'title' => 'Lượt chạy thất bại: '.$r->primary_keyword, 'provider' => null, 'model' => null, 'prompt' => 'Lượt chạy không ra bản nháp',
                    'tokens' => (int) $r->tokens_used, 'cost' => (float) $r->cost_vnd,
                    'at' => CarbonImmutable::parse($r->created_at, config('app.timezone'))->setTimezone(VnTime::TZ),
                ];
            }
        }

        return array_merge($rows, $extra);
    }

    /**
     * G2: phiên KHÔNG gắn bài (khảo sát từ khóa...) cũng tốn tiền AI. Mỗi phiên là một dòng, article_id = 0 (quy ước "không thuộc bài nào");
     * UsageAggregator không đếm chúng vào số bài. Phiên có bài đã được tính qua article_versions nên không lấy lại ở đây.
     *
     * @return list<array<string,mixed>>
     */
    private static function runRows(?int $websiteId): array
    {
        if (! Schema::hasColumn('agent_runs', 'job_type')) {
            return [];
        }
        $q = DB::table('agent_runs as r')->leftJoin('websites as w', 'w.id', '=', 'r.website_id')
            ->whereNull('r.article_id')
            ->select('r.id', 'r.website_id', 'w.name as website', 'r.job_type', 'r.ai_mode', 'r.tokens_used', 'r.cost_vnd', 'r.created_at');
        if ($websiteId !== null) {
            $q->where('r.website_id', $websiteId);
        }

        return $q->orderBy('r.id')->get()->map(fn ($r) => [
            'id' => (int) $r->id,
            'article_id' => 0,
            'website_id' => (int) $r->website_id,
            'website' => (string) ($r->website ?? ''),
            'title' => (string) (JobTypeRegistry::find((string) $r->job_type)['title'] ?? $r->job_type),
            'provider' => null,
            'model' => null,
            'prompt' => (string) (JobTypeRegistry::find((string) $r->job_type)['title'] ?? $r->job_type),
            'tokens' => (int) $r->tokens_used,
            'cost' => $r->cost_vnd === null ? null : (float) $r->cost_vnd,
            'at' => CarbonImmutable::parse($r->created_at, config('app.timezone'))->setTimezone(VnTime::TZ),
        ])->all();
    }
}
