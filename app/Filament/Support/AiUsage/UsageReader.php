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

        return array_merge($rows, self::runRows($websiteId));
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
