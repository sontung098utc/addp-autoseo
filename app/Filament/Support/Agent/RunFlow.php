<?php

namespace App\Filament\Support\Agent;

use App\Filament\Support\Schedule\VnTime;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Services\Agent\Pipeline\JobTypeRegistry;
use App\Services\Agent\Pipeline\PipelineVisual;
use App\Services\Agent\Run\RunPolicy;
use App\Services\Agent\Run\RunScorer;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * AG-S15: dựng dữ liệu hiển thị cho trang chi tiết phiên chạy TỪ DỮ LIỆU THẬT (agent_runs + agent_run_steps).
 * Trường nào bước không ghi thì không có trong kết quả (view không hiển thị), không bịa.
 */
final class RunFlow
{
    /** Các chặng chính theo thứ tự luồng thật; 'grade' và 'repair' được vẽ thành vòng lặp riêng. */
    /** @return list<string> */
    public static function main(): array
    {
        return array_keys(JobTypeRegistry::stageLabels('article_write'));
    }

    public const STATE = [
        'done' => ['Xong', 'heroicon-m-check-circle'],
        'warn' => ['Cần chú ý', 'heroicon-m-exclamation-triangle'],
        'blocked' => ['Bị chặn', 'heroicon-m-no-symbol'],
        'failed' => ['Lỗi', 'heroicon-m-x-circle'],
        'running' => ['Đang chạy', 'heroicon-m-arrow-path'],
        'stale' => ['Có thể treo', 'heroicon-m-clock'],
        'waiting' => ['Chờ bạn', 'heroicon-m-user'],
        'pending' => ['Chưa tới', 'heroicon-m-ellipsis-horizontal-circle'],
        'idle' => ['Không chạy', 'heroicon-m-minus-circle'],
        'skipped' => ['Bỏ qua', 'heroicon-m-forward'],
    ];

    /** Nhãn tiếng Việt cho dữ kiện của quyết định tự động. */
    public const FACT_LABELS = [
        'spent_vnd' => 'Đã chi (₫)', 'budget_vnd' => 'Ngân sách (₫)', 'score' => 'Điểm', 'score_before' => 'Điểm trước', 'score_after' => 'Điểm sau',
        'penalty_before' => 'Phạt trước', 'penalty_after' => 'Phạt sau', 'rounds' => 'Số vòng đã sửa', 'max_rounds' => 'Số vòng tối đa',
        'risk_level' => 'Hạng rủi ro', 'task' => 'Tác vụ', 'cost_vnd' => 'Chi phí lần gọi (₫)', 'cap_vnd' => 'Trần mỗi lần (₫)',
    ];

    public static function stateOf(?string $worst): string
    {
        return match ($worst) {
            'failed' => 'failed', 'blocked' => 'blocked', 'warn' => 'warn', 'skipped' => 'skipped', default => 'done',
        };
    }

    /** Mức xấu nhất của một nhóm bước. */
    private static function worst(Collection $steps): string
    {
        foreach (['failed', 'blocked', 'warn'] as $s) {
            if ($steps->contains('status', $s)) {
                return $s;
            }
        }

        return $steps->every(fn ($x) => $x->status === 'skipped') ? 'skipped' : 'ok';
    }

    /**
     * @param  Collection<int, AgentRunStep>  $steps  đã sắp theo seq
     * @return array<string, mixed>
     */
    public static function build(AgentRun $run, Collection $steps, bool $live, bool $stale, ?string $currentStage): array
    {
        $blueprint = AgentFlowBlueprint::byStage();
        [$codes, $labels] = self::stagesOf($run, $steps);
        $codes = self::withCheckpoint($codes, $steps);
        $registryLabels = JobTypeRegistry::stageLabels((string) ($run->job_type ?: 'article_write'), true);
        $jobDef = collect(JobTypeRegistry::find((string) ($run->job_type ?: 'article_write'))['steps'] ?? [])->keyBy('id');

        // Thời lượng mỗi bước: khoảng cách giữa mốc ghi của bước này và bước trước (bước đầu: từ lúc bắt đầu phiên). Chỉ là ước lượng theo mốc ghi.
        $prev = VnTime::fromRaw($run, 'started_at') ?? VnTime::fromRaw($run, 'created_at');
        $durations = [];
        $clock = [];
        foreach ($steps as $s) {
            $at = VnTime::fromRaw($s, 'created_at');
            $clock[$s->id] = $at;
            $durations[$s->id] = ($at && $prev) ? max(0, (int) abs($at->diffInSeconds($prev, false))) : null;
            $prev = $at ?? $prev;
        }

        // Thử lại / chuyển model dự phòng thuộc về chặng kế tiếp được thử (bước sau chúng không phải retry/failover).
        $incidents = [];
        $pendingInc = [];
        foreach ($steps as $s) {
            if (in_array($s->stage, ['retry', 'failover'], true)) {
                $pendingInc[] = $s;

                continue;
            }
            if ($pendingInc !== []) {
                $target = in_array($s->stage, $codes, true) ? $s->stage : $codes[0] ?? 'write';
                $incidents[$target] = array_merge($incidents[$target] ?? [], $pendingInc);
                $pendingInc = [];
            }
        }
        if ($pendingInc !== []) {
            $incidents['write'] = array_merge($incidents['write'] ?? [], $pendingInc);
        }

        $nodes = [];
        foreach ($codes as $code) {
            $b = $blueprint[$code] ?? ['title' => $code, 'role' => ''];
            $b['title'] = $labels[$code] ?? ($code === 'checkpoint' ? 'Điểm dừng' : (isset($blueprint[$code]) ? $b['title'] : ($registryLabels[$code] ?? $code)));
            $ran = $steps->where('stage', $code)->values();
            $isNow = $live && $currentStage === $code;
            $isStale = $stale && $currentStage === $code;
            $def = $jobDef[$code] ?? [];
            $isCheckpoint = $code === 'checkpoint';
            $waiting = $isCheckpoint && self::isWaiting($run, $ran);
            if ($waiting) {
                $state = 'waiting';
            } elseif ($isNow) {
                $state = 'running';
            } elseif ($isStale) {
                $state = 'stale';
            } elseif ($ran->isNotEmpty()) {
                // Chấm lặp theo vòng: các vòng trượt đã được sửa, trạng thái chặng là của lần chấm CUỐI (các vòng trước hiện ở vòng Chấm ↔ Sửa).
                $state = self::stateOf($code === 'grade' ? self::worst($ran->slice(-1)) : self::worst($ran));
            } else {
                $state = ($live || $stale) ? 'pending' : 'idle';
            }
            // Chặng sửa chỉ xuất hiện khi thật sự có vòng sửa; không chạy thì vẫn hiện mờ để thấy luồng.
            $first = $ran->first();
            $models = $ran->pluck('model')->filter()->unique()->values()->all();
            $nodes[] = [
                'code' => $code,
                'title' => $b['title'],
                'state' => $state,
                'role' => $first->data['role_name'] ?? $b['role'],
                'skill' => $first->data['skill_label'] ?? null,
                'models' => $models,
                'count' => $ran->count(),
                'seconds' => $ran->isEmpty() ? null : $ran->sum(fn ($x) => $durations[$x->id] ?? 0),
                'tokens' => (int) $ran->sum('tokens_used'),
                'cost' => (float) $ran->sum('cost_vnd'),
                'incidents' => array_map(fn ($i) => self::incident($i), $incidents[$code] ?? []),
                'faded' => $state === 'idle',
                'kind' => ($isCheckpoint || ! empty($def['human'])) ? 'human' : ($def['kind'] ?? 'rule'),
                'who' => $isCheckpoint ? ['key' => 'human', 'name' => 'Bạn', 'title' => 'Bạn', 'avatar' => 'B'] : PipelineVisual::profile((string) ($def['role'] ?? 'system')),
                'steps' => $ran->all(),
                'waiting' => $waiting,
            ];
        }

        // Vòng chấm/sửa: mỗi bước chấm là một điểm; delta so với điểm liền trước.
        $rounds = [];
        $prevScore = null;
        foreach ($steps->where('stage', 'grade') as $g) {
            if (! isset($g->data['score'])) {
                continue;
            }
            $score = (int) $g->data['score'];
            $round = (int) ($g->data['round'] ?? count($rounds));
            $rounds[] = [
                'label' => $round === 0 ? 'Bản đầu' : "Sửa {$round}",
                'score' => $score,
                'delta' => $prevScore === null ? null : $score - $prevScore,
                'threshold' => (int) ($g->data['threshold'] ?? RunScorer::REFERENCE_THRESHOLD),
                'blocking' => $g->data['blocking'] ?? [],
                'failed' => $g->data['failed'] ?? [],
            ];
            $prevScore = $score;
        }
        $last = $rounds === [] ? null : $rounds[array_key_last($rounds)];

        $total = $steps->sum('tokens_used');
        $cost = (float) $steps->sum('cost_vnd');
        $budget = (new RunPolicy)->budgetVnd;
        $end = VnTime::fromRaw($run, 'finished_at');
        $start = VnTime::fromRaw($run, 'started_at') ?? VnTime::fromRaw($run, 'created_at');
        $seconds = $end && $start ? max(0, (int) abs($end->diffInSeconds($start, false))) : RunActivity::elapsedSeconds($run);

        return [
            'nodes' => $nodes,
            'rounds' => $rounds,
            'finalScore' => $last['score'] ?? null,
            'threshold' => $last['threshold'] ?? RunScorer::REFERENCE_THRESHOLD,
            'tokens' => max((int) $run->tokens_used, (int) $total),
            'cost' => max((float) $run->cost_vnd, $cost),
            'budget' => $budget,
            'budgetPct' => $budget > 0 ? (int) min(100, round(max((float) $run->cost_vnd, $cost) / $budget * 100)) : 0,
            'seconds' => $seconds,
            'durations' => $durations,
            'clock' => $clock,
            'decisions' => $steps->where('stage', 'decision')->values()->map(fn ($d) => self::decision($d))->all(),
        ];
    }

    /**
     * Chặng của phiên: article_write dùng luồng cố định; loại việc khác lấy từ JobTypeRegistry, rồi thêm các chặng thật đã ghi trong agent_run_steps (theo seq).
     *
     * @return array{0: list<string>, 1: array<string, string>} [mã chặng theo thứ tự, mã => nhãn]
     */
    public static function stagesOf(AgentRun $run, Collection $steps): array
    {
        $type = (string) ($run->job_type ?: 'article_write');
        if ($type === 'article_write') {
            return [self::main(), []];
        }
        $labels = JobTypeRegistry::stageLabels($type, true);
        $codes = array_map('strval', array_keys($labels));
        foreach ($steps->sortBy('seq') as $s) {
            if (in_array($s->stage, ['retry', 'failover', 'decision'], true) || in_array($s->stage, $codes, true)) {
                continue;
            }
            $codes[] = $s->stage;
            $labels[$s->stage] = $s->title ?: $s->stage;
        }

        return [$codes, $labels];
    }

    /** @return array<string, mixed> */
    private static function incident($s): array
    {
        $d = $s->data ?? [];

        return [
            'kind' => $s->stage,
            'title' => $s->title,
            'attempt' => $d['attempt'] ?? null,
            'max' => $d['max'] ?? null,
            'wait' => $d['wait_seconds'] ?? null,
            'error' => $d['error'] ?? ($d['reason'] ?? null),
            'from' => isset($d['from']['model']) ? $d['from']['model'] : null,
            'to' => isset($d['to']['model']) ? $d['to']['model'] : null,
        ];
    }

    /** Quyết định tự động: cặp trước/sau (X_before/X_after) tách riêng, dữ kiện còn lại có nhãn. @return array<string, mixed> */
    private static function decision($s): array
    {
        $d = $s->data ?? [];
        $pairs = [];
        foreach (['score', 'penalty'] as $k) {
            if (isset($d[$k.'_before'], $d[$k.'_after'])) {
                $pairs[] = ['label' => $k === 'score' ? 'Điểm' : 'Điểm phạt', 'before' => $d[$k.'_before'], 'after' => $d[$k.'_after']];
            }
        }
        $facts = [];
        foreach ($d as $k => $v) {
            if (isset(self::FACT_LABELS[$k]) && is_scalar($v) && ! str_ends_with($k, '_before') && ! str_ends_with($k, '_after')) {
                $facts[] = ['label' => self::FACT_LABELS[$k], 'value' => is_numeric($v) && str_ends_with($k, '_vnd') ? number_format((float) $v, 0, ',', '.') : $v];
            }
        }

        return ['title' => $s->title, 'detail' => $s->detail, 'status' => $s->status, 'code' => $d['decision'] ?? null, 'pairs' => $pairs, 'facts' => $facts];
    }

    /** Chặng 'checkpoint' (dừng chờ người) do bộ điều phối ghi; chưa có trong sổ loại việc thì chèn ngay sau chặng đã chạy liền trước nó (theo seq). @param list<string> $codes @return list<string> */
    private static function withCheckpoint(array $codes, Collection $steps): array
    {
        $cp = $steps->where('stage', 'checkpoint')->sortBy('seq')->first();
        if (! $cp || in_array('checkpoint', $codes, true)) {
            return $codes;
        }
        $prev = $steps->where('seq', '<', $cp->seq)->last(fn ($x) => in_array($x->stage, $codes, true));
        $at = $prev ? array_search($prev->stage, $codes, true) : false;
        array_splice($codes, $at === false ? 0 : (int) $at + 1, 0, ['checkpoint']);

        return $codes;
    }

    /** Phiên đang dừng chờ người ở chặng checkpoint: bước ghi cờ data.waiting, hoặc cảnh báo khi phiên đang giữ. */
    private static function isWaiting(AgentRun $run, Collection $ran): bool
    {
        $cp = $ran->last();
        if (! $cp) {
            return false;
        }
        if (isset($cp->data['waiting'])) {
            return (bool) $cp->data['waiting'];
        }

        return $cp->status === 'warn' && $run->status === 'held';
    }

    /**
     * Cảnh báo MỘT dòng, chỉ khi phiên cần người làm gì đó (chờ bạn, treo, lỗi, bị chặn, bị giữ). Phiên bình thường: null, không có câu kết luận nào.
     *
     * @param  array<string, mixed>  $flow  kết quả của build()
     * @return array{tone:string, text:string, action:?array{label:string,url:string}}|null
     */
    public static function verdict(AgentRun $run, array $flow, bool $live, bool $stale): ?array
    {
        $nodes = collect($flow['nodes']);
        $article = $run->article_id ? PipelineVisual::urlOf('App\\Filament\\Resources\\ArticleResource', ['record' => $run->article_id], 'view') : null;
        $draft = $run->final_version_id ? PipelineVisual::urlOf('App\\Filament\\Resources\\ArticleVersionResource', ['record' => $run->final_version_id], 'view') : null;
        $open = $article ? ['label' => 'Mở bài viết', 'url' => $article] : null;
        $view = $draft ? ['label' => 'Xem bản nháp', 'url' => $draft] : $open;
        $reason = trim((string) $run->outcome_reason);
        $at = fn (?array $n) => $n ? '«'.$n['title'].'»' : 'một chặng';

        if ($w = $nodes->firstWhere('state', 'waiting')) {
            return ['tone' => 'warn', 'text' => 'Chờ bạn xác nhận ở '.$at($w).'.', 'action' => $open];
        }
        if ($live) {
            return null;
        }
        if ($stale) {
            return ['tone' => 'warn', 'text' => 'Có thể bị treo ở '.$at($nodes->firstWhere('state', 'stale')).': lâu không có bước mới.', 'action' => $open];
        }
        $bad = $nodes->first(fn ($n) => in_array($n['state'], ['failed', 'blocked'], true)) ?? ($run->status === 'held' ? $nodes->last(fn ($n) => $n['state'] === 'warn') : null);

        return match ($run->status) {
            'failed' => ['tone' => 'failed', 'text' => 'Lỗi ở '.$at($bad).($reason !== '' ? ': '.Str::limit($reason, 140) : '.'), 'action' => $open],
            'blocked' => ['tone' => 'warn', 'text' => 'Bị chặn '.($bad ? 'ở '.$at($bad) : 'trước khi chạy').($reason !== '' ? ': '.Str::limit($reason, 140) : '.'), 'action' => $open],
            'held' => ['tone' => 'warn', 'text' => 'Bị giữ, cần người xem'.($reason !== '' ? ': '.Str::limit($reason, 140) : '.'), 'action' => $view],
            default => null,
        };
    }

    /** Chặng nên mở sẵn trong khung chi tiết: chặng đang chạy/chờ bạn, rồi chặng gặp sự cố, rồi chặng chạy cuối. @param list<array<string,mixed>> $nodes */
    public static function focusStage(array $nodes): ?string
    {
        $c = collect($nodes);
        foreach ([['waiting', 'running', 'stale'], ['failed', 'blocked'], ['warn']] as $states) {
            if ($n = $c->first(fn ($x) => in_array($x['state'], $states, true) && ($x['state'] !== 'warn' || $x['code'] !== 'grade'))) {
                return $n['code'];
            }
        }

        return $c->last(fn ($x) => $x['count'] > 0)['code'] ?? $c->first()['code'] ?? null;
    }
}
