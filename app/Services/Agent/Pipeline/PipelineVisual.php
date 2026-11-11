<?php

namespace App\Services\Agent\Pipeline;

use App\Filament\Resources\AgentRunResource;
use App\Filament\Support\Agent\RunActivity;
use App\Filament\Support\Schedule\VnTime;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\AiModelRoute;
use App\Services\Agent\Roles\RoleRegistry;
use Filament\Resources\Resource;
use Illuminate\Support\Facades\Schema;

/**
 * Dữ liệu cho 3 chế độ xem của tab Pipeline: sơ đồ luồng theo loại việc, danh sách phiên đang chạy, "văn phòng AI".
 * Chỉ ĐỌC: từ JobTypeRegistry, RoleRegistry, agent_runs, agent_run_steps. Không chạy gì, không ghi gì.
 */
final class PipelineVisual
{
    /** Tên bảng kỹ thuật => chữ đời thường cho ô "Đầu vào". */
    private const PLAIN_INPUT = [
        'articles' => 'Bài viết và từ khóa chính', 'brand_profiles' => 'Hồ sơ doanh nghiệp', 'brain_sources' => 'Nguồn trong kho trí tuệ',
        'media_assets' => 'Thư viện ảnh', 'keywords' => 'Từ khóa trong kho', 'keyword_gaps' => 'Khoảng trống từ khóa', 'keyword_clusters' => 'Cụm từ khóa',
        'crawled_urls' => 'Các trang đã quét', 'seo_audits' => 'Kết quả audit trước', 'audit_suggestions.issue_codes' => 'Lỗi SEO từ audit', 'publications.published_url' => 'Địa chỉ bài đã đăng',
        'articles đã có' => 'Bài đã có', 'website' => 'Website công ty', 'knowledge_items' => 'Kiến thức đã duyệt', 'Search Console' => 'Số liệu Search Console', 'phiên bản hiện tại' => 'Phiên bản bài hiện tại',
    ];

    /** Màu nhấn theo loại việc (thẻ luồng, hình minh họa). */
    public const TONES = [
        'market_research' => '#4f46e5', 'site_audit' => '#d97706', 'content_plan' => '#0d9488', 'article_write' => '#2563eb',
        'article_refresh' => '#7c3aed', 'brain_build' => '#059669', 'measure' => '#e11d48',
    ];

    public static function tone(string $jobId): string
    {
        return self::TONES[$jobId] ?? '#64748b';
    }

    /** Cấu hình hiển thị của một chức vụ lấy từ AgentProfiles (W3) nếu có, không thì từ RoleRegistry. @return array{key:string,name:string,title:string,avatar:string} */
    public static function profile(string $key): array
    {
        $registry = new RoleRegistry;
        $title = $registry->roleName($key);
        $name = $title;
        $avatar = $key === 'system' ? '⚙' : mb_strtoupper(mb_substr($title, 0, 1));
        $class = 'App\\Services\\Agent\\Roles\\AgentProfiles';
        if ($key !== 'system' && class_exists($class) && method_exists($class, 'for')) {
            try {
                $p = (array) $class::for($key);
                $name = (string) ($p['display_name'] ?? $name);
                $title = (string) ($p['title'] ?? $title);
                $avatar = (string) ($p['avatar'] ?? $avatar);
            } catch (\Throwable) {
            }
        }

        return ['key' => $key, 'name' => $name, 'title' => $title, 'avatar' => $avatar];
    }

    /** Liên kết bản ghi của một loại việc; chỉ giữ liên kết có class thật và người dùng hiện tại có quyền. @return list<array{label:string,url:string,type:string}> */
    public static function recordLinks(array $job): array
    {
        $out = [];
        foreach ((array) $job['records'] as $r) {
            $url = self::urlOf((string) ($r['class'] ?? ''), ! empty($r['filter_job_type']) ? ['tableFilters' => ['job_type' => ['value' => $job['id']]]] : []);
            if ($url) {
                $out[] = ['label' => (string) $r['label'], 'url' => $url, 'type' => (string) $r['type']];
            }
        }

        return $out;
    }

    /** Địa chỉ trang/Resource nếu class có thật và quyền xem; không thì null. */
    public static function urlOf(string $class, array $params = [], string $name = 'index'): ?string
    {
        if ($class === '' || ! class_exists($class)) {
            return null;
        }
        try {
            $ok = method_exists($class, 'canViewAny') ? $class::canViewAny() : (! method_exists($class, 'canAccess') || $class::canAccess());

            if (! $ok) {
                return null;
            }

            return is_subclass_of($class, \Filament\Resources\Resource::class) ? $class::getUrl($name, $params) : $class::getUrl($params);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Chữ đời thường cho mục `reads`: bỏ phần ngoặc kỹ thuật, đổi tên bảng quen thuộc. @return list<string> */
    public static function plainReads(array $reads): array
    {
        return array_values(array_map(function ($r) {
            $base = trim((string) preg_replace('/\s*\(.*?\)/u', '', (string) $r));

            return self::PLAIN_INPUT[$base] ?? $base;
        }, $reads));
    }

    /** @return list<array<string, mixed>> một sơ đồ cho mỗi loại việc, theo thứ tự registry */
    public static function flows(): array
    {
        $out = [];
        foreach (JobTypeRegistry::all() as $j) {
            $steps = [];
            foreach ($j['steps'] as $i => $s) {
                $tier = ($s['kind'] === 'llm' && isset($s['task'])) ? (AiModelRoute::TIERS[AiModelRoute::TASKS[$s['task']][1] ?? ''] ?? null) : null;
                $steps[] = [
                    'n' => $i + 1, 'id' => $s['id'], 'label' => $s['label'], 'kind' => $s['kind'], 'tier' => $tier,
                    'human' => ! empty($s['human']), 'planned' => ! empty($s['planned']), 'when' => $s['when'] ?? null,
                    'profile' => self::profile((string) ($s['role'] ?? 'system')),
                ] + ['info' => StepInfo::for($j['id'], (string) $s['id'], (string) $s['label'], (string) $s['kind'])];
            }
            $total = count($steps);
            foreach ($steps as $i => &$st) {
                $st['from'] = $i === 0 ? null : $steps[$i - 1]['label'];
                $st['to'] = $i === $total - 1 ? null : $steps[$i + 1]['label'];
            }
            unset($st);
            $links = self::recordLinks($j);
            $out[] = [
                'id' => $j['id'], 'title' => $j['title'], 'status' => $j['status'], 'steps' => $steps, 'budget' => $j['budget'],
                'human' => array_values((array) $j['human']), 'has_human' => collect($steps)->contains('human', true) || ! empty($j['human']),
                'inputs' => self::plainReads($j['reads']), 'input_links' => array_values(array_filter($links, fn ($l) => $l['type'] === 'input')),
                'output_links' => array_values(array_filter($links, fn ($l) => $l['type'] !== 'input')),
                'outputs' => collect($j['shown_at'])->map(fn ($x) => trim((string) preg_replace('/\s*\(.*?\)/u', '', $x)))->all(),
            ];
        }

        return $out;
    }

    /**
     * Chỉ số THẬT cho thẻ luồng, đếm từ agent_runs: đang chạy, số phiên 7 ngày, xong và lỗi trong 7 ngày, chi phí trung bình.
     * Phiên cũ chưa có job_type tính là article_write. Loại việc chưa có phiên nào: toàn 0 và rate = null (không bịa tỷ lệ).
     *
     * @return array<string, array{running:int, week:int, done:int, failed:int, rate:?int, avg_cost:?float}>
     */
    public static function cardStats(): array
    {
        $out = [];
        foreach (JobTypeRegistry::all() as $j) {
            $out[$j['id']] = ['running' => 0, 'week' => 0, 'done' => 0, 'failed' => 0, 'rate' => null, 'avg_cost' => null];
        }
        $hasType = Schema::hasColumn('agent_runs', 'job_type');
        $rows = AgentRun::query()->where(fn ($q) => $q->where('status', 'running')->orWhere('created_at', '>=', now()->subDays(7)))
            ->get(array_filter(['id', 'status', 'cost_vnd', 'created_at', 'updated_at', 'started_at', 'finished_at', $hasType ? 'job_type' : null]));
        $costs = [];
        foreach ($rows as $r) {
            $jt = ($hasType ? $r->job_type : null) ?: 'article_write';
            if (! isset($out[$jt]) || self::isAutoPrepare($r)) {
                continue;
            }
            if ($r->status === 'running' && RunActivity::isLive($r)) {
                $out[$jt]['running']++;
            }
            if ($r->created_at && $r->created_at->gte(now()->subDays(7))) {
                $out[$jt]['week']++;
                if (in_array($r->status, ['failed', 'blocked'], true)) {
                    $out[$jt]['failed']++;
                } elseif ($r->status !== 'running') {
                    $out[$jt]['done']++;
                }
                if ((float) $r->cost_vnd > 0) {
                    $costs[$jt][] = (float) $r->cost_vnd;
                }
            }
        }
        foreach ($out as $id => &$o) {
            $fin = $o['done'] + $o['failed'];
            $o['rate'] = $fin > 0 ? (int) round($o['done'] / $fin * 100) : null;
            $o['avg_cost'] = ! empty($costs[$id]) ? round(array_sum($costs[$id]) / count($costs[$id]), 0) : null;
        }

        return $out;
    }

    /** 5 phiên gần nhất của một loại việc. @return list<array{id:int, subject:string, status:string, when:string, url:?string}> */
    public static function recentRuns(string $jobId, int $limit = 5): array
    {
        $q = AgentRun::query()->with(['article', 'website'])->orderByDesc('id')->limit($limit * 3);
        if (Schema::hasColumn('agent_runs', 'job_type')) {
            $q->where(function ($w) use ($jobId) {
                $w->where('job_type', $jobId);
                if ($jobId === 'article_write') {
                    $w->orWhereNull('job_type');
                }
            });
        } elseif ($jobId !== 'article_write') {
            return [];
        }
        $canView = AgentRunResource::canViewAny();
        $out = [];
        foreach ($q->get() as $r) {
            if (self::isAutoPrepare($r)) {
                continue;
            }
            $out[] = [
                'id' => $r->id, 'status' => $r->status,
                'subject' => $r->article ? ($r->article->primary_keyword ?: $r->article->title ?: 'Bài #'.$r->article_id) : ($r->website ? $r->website->name : 'Phiên #'.$r->id),
                'when' => $r->created_at ? VnTime::format(VnTime::fromRaw($r, 'created_at') ?? $r->created_at, 'd/m H:i') : '',
                'url' => $canView ? AgentRunResource::getUrl('view', ['record' => $r]) : null,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** Số liệu 30 ngày của một bước. @return array{runs:int, ok:int, warn:int, failed:int, avg_cost:float}|null null = chưa có lần chạy */
    public static function stepStats(string $jobId, string $stage): ?array
    {
        $q = AgentRunStep::query()->where('stage', $stage)->where('agent_run_steps.created_at', '>=', now()->subDays(30));
        if (Schema::hasColumn('agent_runs', 'job_type')) {
            $q->whereIn('agent_run_id', AgentRun::query()->select('id')->where(function ($w) use ($jobId) {
                $w->where('job_type', $jobId);
                if ($jobId === 'article_write') {
                    $w->orWhereNull('job_type');
                }
            }));
        } elseif ($jobId !== 'article_write') {
            return null;
        }
        $rows = $q->get(['status', 'cost_vnd']);
        if ($rows->isEmpty()) {
            return null;
        }
        $by = $rows->countBy('status');

        return [
            'runs' => $rows->count(), 'ok' => (int) ($by['ok'] ?? 0), 'warn' => (int) ($by['warn'] ?? 0),
            'failed' => (int) (($by['failed'] ?? 0) + ($by['blocked'] ?? 0)), 'avg_cost' => round((float) $rows->avg('cost_vnd'), 1),
        ];
    }

    /** Phiên đang chạy hoặc vừa chạy trong 24 giờ. @return list<array<string, mixed>> */
    public static function runs(int $limit = 30): array
    {
        $runs = AgentRun::query()->with(['steps', 'article', 'website'])
            ->where(fn ($q) => $q->where('status', 'running')->orWhere('created_at', '>=', now()->subDay()))
            ->orderByRaw("case when status = 'running' then 0 else 1 end")->orderByDesc('id')->limit($limit)->get();
        $out = [];
        foreach ($runs as $r) {
            $jt = $r->job_type ?: 'article_write';
            if (self::isAutoPrepare($r)) {
                continue; // lượt xây kho tự sinh theo từng bài: gom vào dòng ghi chú, không lấn át việc chính
            }
            $job = JobTypeRegistry::find($jt);
            $live = RunActivity::isLive($r);
            $current = $live ? RunActivity::currentStageOf($r, $r->steps) : null;
            $labels = JobTypeRegistry::stageLabels($jt) ?: JobTypeRegistry::stageLabels($jt, true);
            $done = $r->steps->pluck('stage')->all();
            $bar = [];
            foreach ($labels as $id => $label) {
                $bar[] = ['id' => $id, 'label' => $label, 'state' => $id === $current ? 'running' : (in_array($id, $done, true) ? 'done' : 'todo')];
            }
            $roleOf = collect($job['steps'] ?? [])->pluck('role', 'id');
            $who = $current ? self::profile((string) ($roleOf[$current] ?? 'system')) : null;
            $out[] = [
                'run' => $r, 'job_type' => $jt, 'job_title' => $job['title'] ?? $jt, 'live' => $live, 'status' => $r->status,
                'subject' => $r->article ? 'Bài: '.($r->article->primary_keyword ?: $r->article->title ?? '#'.$r->article_id) : ($r->website ? 'Website: '.$r->website->name : 'Không gắn bài hay website'),
                'current' => $current ? ($labels[$current] ?? $current) : null, 'bar' => $bar, 'who' => $who,
                'elapsed' => RunActivity::formatElapsed(RunActivity::elapsedSeconds($r, $r->status === 'running' ? null : (VnTime::fromRaw($r, 'finished_at') ?? null))),
                'cost' => (float) $r->cost_vnd,
                'result_url' => self::resultUrl($r, $jt),
            ];
        }

        return $out;
    }

    /** Lượt xây kho do đội AI tự chạy để chuẩn bị kiến thức cho MỘT bài (purpose 'gap'); vẫn xem được ở trang Phiên chạy. */
    public static function isAutoPrepare(AgentRun $r): bool
    {
        return ($r->job_type ?? null) === 'brain_build' && (($r->input_refs['purpose'] ?? null) === 'gap');
    }

    /** Số lượt chuẩn bị kiến thức tự động trong 24 giờ qua (hiện thành ghi chú dưới danh sách "Đang chạy"). */
    public static function autoPrepareCount(): int
    {
        return AgentRun::query()->where('job_type', 'brain_build')->where('created_at', '>=', now()->subDay())->get()->filter(fn ($r) => self::isAutoPrepare($r))->count();
    }

    private static function resultUrl(AgentRun $r, string $jt): ?string
    {
        if ($r->article_id && $r->article) {
            return self::urlOf('App\\Filament\\Resources\\ArticleResource', ['record' => $r->article_id], 'view');
        }
        $page = ['market_research' => 'App\\Filament\\Pages\\KeywordsPage', 'brain_build' => 'App\\Filament\\Pages\\BrainBuildPage', 'site_audit' => 'App\\Filament\\Pages\\AuditPage', 'content_plan' => 'App\\Filament\\Pages\\ContentPlanPage'][$jt] ?? null;

        return $page ? self::urlOf($page) : null;
    }

    /** Mỗi vai trò là một bàn làm việc. @return list<array<string, mixed>> */
    public static function office(): array
    {
        $live = [];
        foreach (self::runs(100) as $row) {
            if ($row['live'] && $row['who']) {
                $live[$row['who']['key']][] = $row;
            }
        }
        $today = AgentRunStep::query()->where('created_at', '>=', now()->startOfDay())->whereNotNull('role')
            ->selectRaw('role, count(distinct agent_run_id) as runs, sum(cost_vnd) as cost')->groupBy('role')->get()->keyBy('role');
        $jobs = JobTypeRegistry::all();
        $out = [];
        foreach ((new RoleRegistry)->all() as $role) {
            $k = $role['key'];
            $joins = [];
            foreach ($jobs as $j) {
                $mine = array_values(array_filter($j['steps'], fn ($s) => ($s['role'] ?? '') === $k));
                if ($mine) {
                    $joins[] = ['job' => $j['title'], 'id' => $j['id'], 'steps' => array_column($mine, 'label')];
                }
            }
            $t = $today[$k] ?? null;
            $out[] = [
                'profile' => self::profile($k), 'readiness' => $role['readiness'] ?? null, 'joins' => $joins, 'working' => $live[$k] ?? [],
                'runs_today' => (int) ($t->runs ?? 0), 'cost_today' => (float) ($t->cost ?? 0),
            ];
        }

        return $out;
    }
}
