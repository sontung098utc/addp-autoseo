<?php

namespace App\Filament\Support\Dashboard;

use App\Filament\Support\Schedule\VnTime;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\AiProvider;
use App\Models\Article;
use App\Models\Campaign;
use App\Models\Publication;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;

/**
 * Số liệu cho các biểu đồ của hai màn Tổng quan. Chỉ đếm dữ liệu thật; chưa có thì trả mảng rỗng/0 để widget hiện trạng thái trống.
 * Gom theo NGÀY GIỜ VN ở PHP (không dùng hàm ngày của DB) để chạy giống nhau trên SQLite/MySQL/Postgres.
 * Cột *_utc đọc là UTC; cột Eloquent tự ghi (started_at, created_at) đọc theo app.timezone, như VnTime.
 */
final class ChartQueries
{
    /** Màu theo ý nghĩa, dùng chung mọi biểu đồ: tốt / chờ / lỗi / thông tin / thương hiệu / trung tính. */
    public const COLOR = [
        'good' => '#16a34a',
        'wait' => '#d97706',
        'bad' => '#dc2626',
        'info' => '#2563eb',
        'brand' => '#0f6b5c',
        'muted' => '#94a3b8',
    ];

    /** Trạng thái phiên chạy gộp thành 4 nhóm cho biểu đồ kết quả. */
    public const RUN_GROUPS = [
        'ok' => ['label' => 'Thành công', 'color' => 'good', 'statuses' => ['ready_for_review', 'done', 'approved', 'published']],
        'held' => ['label' => 'Giữ nháp', 'color' => 'wait', 'statuses' => ['held']],
        'failed' => ['label' => 'Lỗi', 'color' => 'bad', 'statuses' => ['failed']],
        'blocked' => ['label' => 'Bị chặn', 'color' => 'muted', 'statuses' => ['blocked']],
    ];

    /** Vòng đời bài theo thứ tự đi qua; "rejected" tách riêng vì là nhánh rẽ. */
    public const LIFECYCLE = [
        'planned' => 'Kế hoạch',
        'drafting' => 'Đang soạn',
        'needs_review' => 'Chờ duyệt',
        'approved' => 'Đã duyệt',
        'scheduled' => 'Đã xếp lịch',
        'published' => 'Đã đăng',
    ];

    /** @return list<string> Y-m-d theo giờ VN, cũ nhất trước, kết thúc ở ngày của $now. */
    public static function dayKeys(int $days, CarbonInterface $now): array
    {
        $today = CarbonImmutable::instance($now)->setTimezone(VnTime::TZ)->startOfDay();
        $keys = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $keys[] = $today->subDays($i)->format('Y-m-d');
        }

        return $keys;
    }

    /** Nhãn trục "dd/mm" cho danh sách ngày. */
    public static function dayLabels(array $keys): array
    {
        return array_map(fn (string $k) => CarbonImmutable::parse($k)->format('d/m'), $keys);
    }

    /** Chuỗi giờ thô -> Y-m-d giờ VN. $tz là múi giờ mà DB đang lưu giá trị đó. */
    private static function vnDay(?string $raw, string $tz): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return CarbonImmutable::parse($raw, $tz)->setTimezone(VnTime::TZ)->format('Y-m-d');
    }

    private static function appTz(): string
    {
        return (string) config('app.timezone', 'UTC');
    }

    /** @param list<string> $keys @return array<string, int|float> khóa ngày -> 0 */
    private static function zeros(array $keys): array
    {
        return array_fill_keys($keys, 0);
    }

    /**
     * Lượt đăng theo ngày (đã đăng / lỗi / cần đối soát).
     *
     * @return array{labels: list<string>, published: list<int>, failed: list<int>, reconcile: list<int>, total: int}
     */
    public static function publicationsByDay(int $days, CarbonInterface $now): array
    {
        $keys = self::dayKeys($days, $now);
        $pub = $fail = $rec = self::zeros($keys);
        $from = VnTime::dayRangeUtc(CarbonImmutable::parse($keys[0], VnTime::TZ))[0];

        $rows = Publication::query()->toBase()
            ->whereIn('status', ['published', 'failed', 'needs_reconcile'])
            ->where(fn ($q) => $q->where('scheduled_at_utc', '>=', $from)->orWhere('published_at_utc', '>=', $from))
            ->get(['status', 'scheduled_at_utc', 'published_at_utc']);

        foreach ($rows as $r) {
            $day = self::vnDay($r->status === 'published' ? ($r->published_at_utc ?: $r->scheduled_at_utc) : $r->scheduled_at_utc, 'UTC');
            if ($day === null || ! array_key_exists($day, $pub)) {
                continue;
            }
            match ($r->status) {
                'published' => $pub[$day]++,
                'failed' => $fail[$day]++,
                default => $rec[$day]++,
            };
        }

        return [
            'labels' => self::dayLabels($keys),
            'published' => array_values($pub),
            'failed' => array_values($fail),
            'reconcile' => array_values($rec),
            'total' => array_sum($pub) + array_sum($fail) + array_sum($rec),
        ];
    }

    /**
     * Lịch đăng sắp tới: số lượt đã xếp lịch cho từng ngày (hôm nay + 6 ngày).
     *
     * @return array{labels: list<string>, counts: list<int>, total: int}
     */
    public static function upcomingSchedule(int $days, CarbonInterface $now): array
    {
        $today = CarbonImmutable::instance($now)->setTimezone(VnTime::TZ)->startOfDay();
        $keys = [];
        for ($i = 0; $i < $days; $i++) {
            $keys[] = $today->addDays($i)->format('Y-m-d');
        }
        $counts = self::zeros($keys);
        [$from] = VnTime::dayRangeUtc($today);
        [, $to] = VnTime::dayRangeUtc($today->addDays($days - 1));

        $rows = Publication::query()->toBase()->where('status', 'scheduled')
            ->where('scheduled_at_utc', '>=', $from)->where('scheduled_at_utc', '<', $to)
            ->get(['scheduled_at_utc']);
        foreach ($rows as $r) {
            $day = self::vnDay($r->scheduled_at_utc, 'UTC');
            if ($day !== null && array_key_exists($day, $counts)) {
                $counts[$day]++;
            }
        }

        return ['labels' => self::dayLabels($keys), 'counts' => array_values($counts), 'total' => array_sum($counts)];
    }

    /**
     * Số bài đang ở từng khâu vòng đời (ảnh chụp hiện tại, không phải tỷ lệ chuyển đổi).
     *
     * @return array{labels: list<string>, keys: list<string>, counts: list<int>, rejected: int, total: int}
     */
    public static function lifecycle(): array
    {
        $by = Article::query()->selectRaw('workflow_status, count(*) c')->groupBy('workflow_status')->pluck('c', 'workflow_status')->all();
        $counts = [];
        foreach (array_keys(self::LIFECYCLE) as $k) {
            $counts[] = (int) ($by[$k] ?? 0);
        }

        return [
            'labels' => array_values(self::LIFECYCLE),
            'keys' => array_keys(self::LIFECYCLE),
            'counts' => $counts,
            'rejected' => (int) ($by['rejected'] ?? 0),
            'total' => array_sum($counts) + (int) ($by['rejected'] ?? 0),
        ];
    }

    /**
     * Chi phí AI theo ngày (đồng) kèm trần ngày của các chiến dịch đang chạy (0 = chưa đặt trần).
     *
     * @return array{labels: list<string>, cost: list<float>, cap: float, total: float, tokens: int}
     */
    public static function costByDay(int $days, CarbonInterface $now): array
    {
        $keys = self::dayKeys($days, $now);
        $cost = self::zeros($keys);
        $from = VnTime::dayRangeApp(CarbonImmutable::parse($keys[0], VnTime::TZ))[0];
        $tokens = 0;

        $rows = AgentRun::query()->toBase()->where('started_at', '>=', $from)->get(['started_at', 'cost_vnd', 'tokens_used']);
        foreach ($rows as $r) {
            $day = self::vnDay($r->started_at, self::appTz());
            if ($day !== null && array_key_exists($day, $cost)) {
                $cost[$day] += (float) $r->cost_vnd;
                $tokens += (int) $r->tokens_used;
            }
        }

        return [
            'labels' => self::dayLabels($keys),
            'cost' => array_map(fn ($v) => round((float) $v, 0), array_values($cost)),
            'cap' => self::dailyCap(),
            'total' => round(array_sum($cost), 0),
            'tokens' => $tokens,
        ];
    }

    /** Tổng trần chi phí ngày của các chiến dịch đang chạy; 0 nếu chưa đặt hoặc chưa có cột. */
    public static function dailyCap(): float
    {
        return Schema::hasColumn('campaigns', 'daily_cost_cap_vnd')
            ? (float) Campaign::query()->where('status', 'active')->sum('daily_cost_cap_vnd')
            : 0.0;
    }

    /**
     * Kết quả các phiên chạy trong $days ngày, gộp 4 nhóm; phiên đang chạy đếm riêng.
     *
     * @return array{groups: array<string, int>, running: int, total: int, successRate: ?float}
     */
    public static function runOutcomes(int $days, CarbonInterface $now): array
    {
        $since = CarbonImmutable::instance($now)->subDays($days);
        $by = AgentRun::query()->where('created_at', '>=', $since)->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status')->all();

        $groups = [];
        foreach (self::RUN_GROUPS as $key => $g) {
            $groups[$key] = (int) array_sum(array_intersect_key($by, array_flip($g['statuses'])));
        }
        $finished = array_sum($groups);

        return [
            'groups' => $groups,
            'running' => (int) ($by['running'] ?? 0),
            'total' => (int) array_sum($by),
            'successRate' => $finished > 0 ? round($groups['ok'] / $finished * 100, 1) : null,
        ];
    }

    /**
     * Chi phí và token theo vai trò trong $days ngày; sắp xếp tốn nhất trước, bỏ vai trò 0 đồng và 0 token.
     *
     * @return list<array{role: string, cost: float, prompt: int, completion: int}>
     */
    public static function costByRole(int $days, CarbonInterface $now): array
    {
        $since = CarbonImmutable::instance($now)->subDays($days);
        $hasSplit = Schema::hasColumn('agent_run_steps', 'prompt_tokens');
        $cols = $hasSplit
            ? 'role, sum(cost_vnd) cost, sum(prompt_tokens) pt, sum(completion_tokens) ct'
            : 'role, sum(cost_vnd) cost, sum(tokens_used) pt, 0 ct';

        return AgentRunStep::query()->whereHas('run', fn ($q) => $q->where('created_at', '>=', $since))
            ->selectRaw($cols)->groupBy('role')->get()
            ->map(fn ($r) => ['role' => (string) ($r->role ?: 'Khác'), 'cost' => (float) $r->cost, 'prompt' => (int) $r->pt, 'completion' => (int) $r->ct])
            ->filter(fn ($r) => $r['cost'] > 0 || $r['prompt'] + $r['completion'] > 0)
            ->sortByDesc('cost')->values()->all();
    }

    /**
     * Chi phí và token theo model AI trong $days ngày; tốn nhất trước, bỏ model không có chi phí lẫn token. Bước không ghi model gộp thành "Không rõ model".
     *
     * @return list<array{model: string, cost: float, tokens: int, steps: int}>
     */
    public static function costByModel(int $days, CarbonInterface $now): array
    {
        $since = CarbonImmutable::instance($now)->subDays($days);
        $tokens = Schema::hasColumn('agent_run_steps', 'prompt_tokens') ? 'sum(coalesce(prompt_tokens,0) + coalesce(completion_tokens,0))' : 'sum(tokens_used)';

        return AgentRunStep::query()->whereHas('run', fn ($q) => $q->where('created_at', '>=', $since))
            ->selectRaw("model, sum(cost_vnd) cost, {$tokens} tk, count(*) n")->groupBy('model')->get()
            ->map(fn ($r) => ['model' => (string) ($r->model ?: 'Không rõ model'), 'cost' => (float) $r->cost, 'tokens' => (int) $r->tk, 'steps' => (int) $r->n])
            ->filter(fn ($r) => $r['cost'] > 0 || $r['tokens'] > 0)
            ->sortByDesc('cost')->values()->all();
    }

    /**
     * Chi phí và token theo provider AI trong $days ngày, đọc `provider_id` mà phiên chạy đã ghi trong JSON `data` của bước.
     * Bước nào chưa ghi (khâu gọi AI không đi qua đường ghi chung, hoặc phiên cũ) gộp vào dòng riêng, KHÔNG đoán từ tên model.
     * Khớp bằng LIKE trên chuỗi JSON để không phải nạp `data` (có thể rất lớn) và chạy được trên SQLite/MariaDB.
     *
     * @return array{rows: list<array{id: int, name: string, kind: string, cost: float, tokens: int, steps: int}>, unrecorded: array{cost: float, tokens: int, steps: int}, total: float, coverage: ?float}
     */
    public static function costByProvider(int $days, CarbonInterface $now): array
    {
        $since = CarbonImmutable::instance($now)->subDays($days);
        $tokenExpr = Schema::hasColumn('agent_run_steps', 'prompt_tokens') ? 'coalesce(sum(coalesce(prompt_tokens,0) + coalesce(completion_tokens,0)), 0)' : 'coalesce(sum(tokens_used), 0)';
        $base = fn () => AgentRunStep::query()->whereHas('run', fn ($q) => $q->where('created_at', '>=', $since))
            ->where(fn ($q) => $q->where('cost_vnd', '>', 0)->orWhere('tokens_used', '>', 0));
        $agg = fn ($q) => $q->selectRaw("coalesce(sum(cost_vnd), 0) c, {$tokenExpr} t, count(*) n")->first();

        $all = $agg($base());
        $rows = [];
        $sumCost = 0.0;
        $sumTokens = 0;
        $sumSteps = 0;
        foreach (AiProvider::query()->get(['id', 'name', 'kind']) as $p) {
            $r = $agg($base()->where(fn ($q) => $q->where('data', 'like', '%"provider_id":'.(int) $p->id.',%')->orWhere('data', 'like', '%"provider_id":'.(int) $p->id.'}%')));
            if ((int) $r->n === 0) {
                continue;
            }
            $rows[] = ['id' => (int) $p->id, 'name' => (string) $p->name, 'kind' => (string) $p->kind, 'cost' => (float) $r->c, 'tokens' => (int) $r->t, 'steps' => (int) $r->n];
            $sumCost += (float) $r->c;
            $sumTokens += (int) $r->t;
            $sumSteps += (int) $r->n;
        }
        usort($rows, fn ($a, $b) => $b['cost'] <=> $a['cost']);
        $total = (float) $all->c;

        return [
            'rows' => $rows,
            'unrecorded' => ['cost' => max(0.0, $total - $sumCost), 'tokens' => max(0, (int) $all->t - $sumTokens), 'steps' => max(0, (int) $all->n - $sumSteps)],
            'total' => $total,
            'coverage' => $total > 0 ? round($sumCost / $total * 100, 0) : null,
        ];
    }

    /**
     * Thời gian trung bình (giây) của từng giai đoạn, chỉ tính bước đã có đủ giờ bắt đầu và kết thúc.
     *
     * @return list<array{stage: string, seconds: float, steps: int}> chậm nhất trước
     */
    public static function stageDurations(int $days, CarbonInterface $now): array
    {
        $since = CarbonImmutable::instance($now)->subDays($days);
        $agg = [];
        $rows = AgentRunStep::query()->toBase()
            ->whereNotNull('started_at')->whereNotNull('finished_at')
            ->whereIn('agent_run_id', AgentRun::query()->select('id')->where('created_at', '>=', $since))
            ->get(['stage', 'started_at', 'finished_at']);
        foreach ($rows as $r) {
            $sec = CarbonImmutable::parse($r->started_at)->diffInSeconds(CarbonImmutable::parse($r->finished_at), false);
            if ($sec < 0) {
                continue;
            }
            $agg[$r->stage]['sum'] = ($agg[$r->stage]['sum'] ?? 0) + $sec;
            $agg[$r->stage]['n'] = ($agg[$r->stage]['n'] ?? 0) + 1;
        }
        $out = [];
        foreach ($agg as $stage => $a) {
            $out[] = ['stage' => (string) $stage, 'seconds' => round($a['sum'] / $a['n'], 1), 'steps' => $a['n']];
        }
        usort($out, fn ($a, $b) => $b['seconds'] <=> $a['seconds']);

        return $out;
    }

    /**
     * Phân bố số vòng tự sửa của các phiên đã có kết quả: 0 / 1 / 2 / 3 trở lên.
     *
     * @return array{labels: list<string>, counts: list<int>, total: int, avg: ?float}
     */
    public static function repairRounds(int $days, CarbonInterface $now): array
    {
        $since = CarbonImmutable::instance($now)->subDays($days);
        $rows = AgentRun::query()->toBase()->where('created_at', '>=', $since)->where('status', '!=', 'running')->get(['repair_rounds']);
        $b = [0, 0, 0, 0];
        $sum = 0;
        foreach ($rows as $r) {
            $n = (int) $r->repair_rounds;
            $b[min($n, 3)]++;
            $sum += $n;
        }
        $total = array_sum($b);

        return ['labels' => ['0 vòng', '1 vòng', '2 vòng', '3+ vòng'], 'counts' => $b, 'total' => $total, 'avg' => $total > 0 ? round($sum / $total, 1) : null];
    }

    /**
     * Số bài đã đăng trong tuần này (từ thứ Hai giờ VN) so với tuần trước cùng khoảng, kèm chuỗi theo ngày của 7 ngày gần nhất làm sparkline.
     *
     * @return array{thisWeek: int, lastWeek: int, spark: list<int>}
     */
    public static function publishedWeekly(CarbonInterface $now): array
    {
        $monday = VnTime::weekStart($now);
        $elapsed = max(1, (int) $monday->diffInDays(CarbonImmutable::instance($now)->setTimezone(VnTime::TZ)->startOfDay()) + 1);
        [$thisFrom] = VnTime::dayRangeUtc($monday);
        [$lastFrom] = VnTime::dayRangeUtc($monday->subWeek());
        [, $lastTo] = VnTime::dayRangeUtc($monday->subWeek()->addDays($elapsed - 1));
        $base = fn () => Publication::query()->where('status', 'published');
        $stamp = 'coalesce(published_at_utc, scheduled_at_utc)';

        return [
            'thisWeek' => $base()->whereRaw("$stamp >= ?", [$thisFrom])->count(),
            'lastWeek' => $base()->whereRaw("$stamp >= ? and $stamp < ?", [$lastFrom, $lastTo])->count(),
            'spark' => array_slice(self::publicationsByDay(7, $now)['published'], -7),
        ];
    }

    /** Chênh lệch phần trăm so với kỳ trước; null khi kỳ trước bằng 0 (không chia cho 0, không bịa mức tăng). */
    public static function deltaPercent(float $now, float $before): ?float
    {
        return $before > 0 ? round(($now - $before) / $before * 100, 0) : null;
    }
}
