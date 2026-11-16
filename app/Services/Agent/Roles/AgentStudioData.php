<?php

namespace App\Services\Agent\Roles;

use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\ArticleVersion;
use App\Services\Agent\Pipeline\JobTypeRegistry;
use App\Services\Ai\Playbook\PlaybookLoader;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Dữ liệu chỉ-đọc cho Agent Studio: vị trí trong luồng, phòng huấn luyện, hoạt động. Mọi truy vấn lỗi đều trả rỗng, không làm hỏng trang. */
final class AgentStudioData
{
    /**
     * Mỗi loại việc một hàng: các bước (đánh dấu bước của vai trò). Bước có khóa 'role' thì theo khóa đó, không thì suy từ RoleRegistry::STEP_ROLE.
     *
     * @return list<array{id: string, title: string, status: string, steps: list<array{id: string, label: string, mine: bool, planned: bool, kind: string}>, mine: int, tasks: list<string>}>
     */
    public static function positions(string $roleKey): array
    {
        $out = [];
        foreach (JobTypeRegistry::all() as $job) {
            $steps = [];
            $tasks = [];
            foreach ((array) $job['steps'] as $s) {
                $r = $s['role'] ?? RoleRegistry::STEP_ROLE[$s['id']] ?? 'system';
                $mine = $r === $roleKey;
                $steps[] = ['id' => $s['id'], 'label' => $s['label'], 'mine' => $mine, 'planned' => ! empty($s['planned']), 'kind' => (string) ($s['kind'] ?? 'rule')];
                if ($mine) {
                    $tasks[] = $s['label'].(! empty($s['when']) ? ' — '.$s['when'] : '');
                }
            }
            $out[] = ['id' => $job['id'], 'title' => $job['title'], 'status' => $job['status'], 'steps' => $steps, 'mine' => count($tasks), 'tasks' => $tasks];
        }

        return $out;
    }

    /**
     * Việc cần dạy: bước warn/failed/blocked của vai trò, và (với Người viết) bài bị từ chối kèm ghi chú người duyệt.
     *
     * @return list<array{key: string, run_id: ?int, label: string, detail: string, draft: string, at: ?string}>
     */
    public static function trainingIssues(string $roleKey, int $limit = 15): array
    {
        $out = [];
        try {
            foreach (AgentRunStep::query()->where('role', $roleKey)->whereIn('status', ['warn', 'failed', 'blocked'])->orderByDesc('id')->limit($limit)->get() as $s) {
                $detail = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $s->detail)) ?? '');
                $out[] = ['key' => 'step-'.$s->id, 'run_id' => (int) $s->agent_run_id, 'label' => ['warn' => 'Cảnh báo', 'failed' => 'Lỗi', 'blocked' => 'Bị chặn'][$s->status].': '.$s->title,
                    'detail' => mb_substr($detail, 0, 300), 'draft' => mb_substr('Khi gặp tình huống "'.$s->title.'", cần tránh: '.($detail !== '' ? $detail : 'lỗi này'), 0, 480), 'at' => $s->created_at?->toIso8601String()];
            }
            if ($roleKey === 'writer') {
                foreach (ArticleVersion::query()->where('review_status', 'rejected')->whereNotNull('review_notes')->orderByDesc('id')->limit($limit)->get() as $v) {
                    $note = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $v->review_notes)) ?? '');
                    if ($note === '') {
                        continue;
                    }
                    $runId = AgentRun::query()->where('final_version_id', $v->id)->value('id');
                    $out[] = ['key' => 'ver-'.$v->id, 'run_id' => $runId ? (int) $runId : null, 'label' => 'Bài bị từ chối (bản #'.$v->id.')',
                        'detail' => mb_substr($note, 0, 300), 'draft' => mb_substr('Người duyệt từ chối bài vì: '.$note.' — lần sau tránh lỗi này.', 0, 480), 'at' => $v->updated_at?->toIso8601String()];
                }
            }
        } catch (Throwable) {
        }

        return $out;
    }

    /** Nhãn trạng thái phiên chạy (cùng bảng chữ với trang Pipeline / Xem phiên). */
    public const STATUS_LABEL = ['running' => 'Đang chạy', 'ready_for_review' => 'Chờ duyệt', 'held' => 'Giữ nháp', 'blocked' => 'Bị chặn', 'failed' => 'Lỗi', 'approved' => 'Đã duyệt', 'published' => 'Đã đăng', 'ok' => 'Xong', 'completed' => 'Xong'];

    public const STATUS_TONE = ['running' => 'info', 'ready_for_review' => 'ok', 'held' => 'warn', 'blocked' => 'danger', 'failed' => 'danger', 'approved' => 'ok', 'published' => 'ok', 'ok' => 'ok', 'completed' => 'ok'];

    public static function statusLabel(string $status): string
    {
        return self::STATUS_LABEL[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    /** "Viết bài #34": tên loại việc tiếng Việt từ JobTypeRegistry, không lộ mã thô. */
    public static function runTitle(string $jobType, int $id): string
    {
        $title = null;
        try {
            $title = JobTypeRegistry::find($jobType)['title'] ?? null;
        } catch (Throwable) {
        }

        return ($title ?: 'Phiên chạy').' #'.$id;
    }

    private static function ago(?Carbon $at): ?string
    {
        try {
            return $at?->locale('vi')->diffForHumans(null, CarbonInterface::DIFF_RELATIVE_TO_NOW, true);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{steps: int, ok: int, ok_rate: ?int, cost_vnd: float, runs: list<array{id: int, status: string, title: string, label: string, tone: string, at: ?string, ago: ?string}>} 30 ngày gần nhất */
    public static function activity(string $roleKey): array
    {
        $empty = ['steps' => 0, 'ok' => 0, 'ok_rate' => null, 'cost_vnd' => 0.0, 'runs' => []];
        try {
            $since = now()->subDays(30);
            $q = AgentRunStep::query()->where('role', $roleKey)->where('created_at', '>=', $since);
            $agg = (clone $q)->selectRaw("count(*) as n, sum(case when status in ('ok','skipped') then 1 else 0 end) as ok, coalesce(sum(cost_vnd),0) as cost")->first();
            $n = (int) ($agg->n ?? 0);
            $ids = (clone $q)->select('agent_run_id', DB::raw('max(id) as last_id'))->groupBy('agent_run_id')->orderByDesc('last_id')->limit(10)->pluck('agent_run_id')->all();
            $runs = AgentRun::query()->whereIn('id', $ids)->orderByDesc('id')->get()->map(fn ($r) => ['id' => (int) $r->id, 'status' => (string) $r->status, 'title' => self::runTitle((string) $r->job_type, (int) $r->id), 'label' => self::statusLabel((string) $r->status), 'tone' => self::STATUS_TONE[(string) $r->status] ?? 'muted', 'at' => $r->created_at?->toIso8601String(), 'ago' => self::ago($r->created_at)])->all();

            return ['steps' => $n, 'ok' => (int) ($agg->ok ?? 0), 'ok_rate' => $n > 0 ? (int) round(100 * (int) $agg->ok / $n) : null, 'cost_vnd' => (float) ($agg->cost ?? 0), 'runs' => $runs] + $empty;
        } catch (Throwable) {
            return $empty;
        }
    }

    /** Nội dung playbook gốc (claude-seo) của một kỹ năng "<role>/<key>" làm khởi điểm cho kỹ năng tùy chỉnh; null nếu chưa có. */
    public static function playbookBody(string $ref): ?array
    {
        try {
            [$r, $k] = explode('/', $ref, 2);
            $pb = (new PlaybookLoader)->findLatest($r, $k);

            return $pb ? ['title' => $pb->title ?: $k, 'body' => $pb->body] : null;
        } catch (Throwable) {
            return null;
        }
    }
}
