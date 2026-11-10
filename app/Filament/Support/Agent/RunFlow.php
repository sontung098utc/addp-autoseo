<?php

namespace App\Filament\Support\Agent;

use App\Filament\Support\Schedule\VnTime;
use App\Models\AgentRun;
use App\Services\Agent\Run\RunPolicy;
use App\Services\Agent\Run\RunScorer;
use Illuminate\Support\Collection;

/**
 * AG-S15: dựng dữ liệu hiển thị cho trang chi tiết phiên chạy TỪ DỮ LIỆU THẬT (agent_runs + agent_run_steps).
 * Trường nào bước không ghi thì không có trong kết quả (view không hiển thị), không bịa.
 */
final class RunFlow
{
    /** Các chặng chính theo thứ tự luồng thật; 'grade' và 'repair' được vẽ thành vòng lặp riêng. */
    public const MAIN = ['guard', 'risk', 'brief', 'write', 'grade', 'repair', 'handoff'];

    public const STATE = [
        'done' => ['Xong', 'heroicon-m-check-circle'],
        'warn' => ['Cần chú ý', 'heroicon-m-exclamation-triangle'],
        'blocked' => ['Bị chặn', 'heroicon-m-no-symbol'],
        'failed' => ['Lỗi', 'heroicon-m-x-circle'],
        'running' => ['Đang chạy', 'heroicon-m-arrow-path'],
        'stale' => ['Có thể treo', 'heroicon-m-clock'],
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
     * @param  Collection<int, \App\Models\AgentRunStep>  $steps  đã sắp theo seq
     * @return array<string, mixed>
     */
    public static function build(AgentRun $run, Collection $steps, bool $live, bool $stale, ?string $currentStage): array
    {
        $blueprint = AgentFlowBlueprint::byStage();
        $closed = $run->status !== 'running';

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
                $target = in_array($s->stage, self::MAIN, true) ? $s->stage : 'write';
                $incidents[$target] = array_merge($incidents[$target] ?? [], $pendingInc);
                $pendingInc = [];
            }
        }
        if ($pendingInc !== []) {
            $incidents['write'] = array_merge($incidents['write'] ?? [], $pendingInc);
        }

        $nodes = [];
        foreach (self::MAIN as $code) {
            $b = $blueprint[$code] ?? ['title' => $code, 'role' => ''];
            $ran = $steps->where('stage', $code)->values();
            $isNow = $live && $currentStage === $code;
            $isStale = $stale && $currentStage === $code;
            if ($isNow) {
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
        $budget = (new RunPolicy())->budgetVnd;
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
}
