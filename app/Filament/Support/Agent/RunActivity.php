<?php

namespace App\Filament\Support\Agent;

use App\Filament\Support\Schedule\VnTime;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Services\Agent\Pipeline\JobTypeRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * AG-S07: suy "đang chạy" từ dữ liệu thật (agent_runs + agent_run_steps), không đoán và không giả lập.
 * Bước được ghi ngay khi xong, nên chặng đang chạy = chặng kế tiếp sau bước cuối đã ghi.
 * Phiên 'running' quá STALE_MINUTES không có bước mới là "có thể bị treo": ngừng hiệu ứng.
 */
final class RunActivity
{
    public const STALE_MINUTES = 15;

    /** Nhịp poll (giây) khi có / không có phiên đang chạy. */
    public const POLL_LIVE = 2;

    public const POLL_IDLE = 10;

    /** Phiên running còn "sống" (chưa quá hạn không có bước mới). */
    public static function isLive(AgentRun $run, ?CarbonImmutable $now = null): bool
    {
        return $run->status === 'running' && ! self::isStale($run, $now);
    }

    public static function isStale(AgentRun $run, ?CarbonImmutable $now = null): bool
    {
        if ($run->status !== 'running') {
            return false;
        }
        $now ??= CarbonImmutable::now('UTC');

        return self::lastActivityAt($run)?->lt($now->subMinutes(self::STALE_MINUTES)) ?? false;
    }

    /** Mốc hoạt động gần nhất: bước cuối đã ghi, nếu chưa có bước thì lúc bắt đầu phiên. */
    public static function lastActivityAt(AgentRun $run): ?CarbonImmutable
    {
        $lastStep = $run->steps()->reorder('seq', 'desc')->first();
        $at = $lastStep ? VnTime::fromRaw($lastStep, 'created_at') : null;

        return $at ?? VnTime::fromRaw($run, 'started_at') ?? VnTime::fromRaw($run, 'created_at');
    }

    public static function elapsedSeconds(AgentRun $run, ?CarbonImmutable $now = null): int
    {
        $start = VnTime::fromRaw($run, 'started_at') ?? VnTime::fromRaw($run, 'created_at');

        return $start ? max(0, (int) abs(($now ?? CarbonImmutable::now('UTC'))->diffInSeconds($start, false))) : 0;
    }

    public static function formatElapsed(int $seconds): string
    {
        return $seconds >= 60 ? intdiv($seconds, 60).' phút '.($seconds % 60).' giây' : $seconds.' giây';
    }

    /**
     * Chặng đang chạy suy từ bước cuối: guard→risk→brief→write→grade→(repair→grade | handoff); retry→write.
     * Sau bước chấm: đạt (ok) thì bàn giao, chưa đạt thì sửa. Bàn giao xong thì phiên sắp đóng (null).
     *
     * @param  Collection<int, AgentRunStep>  $steps  đã sắp theo seq
     */
    public static function currentStage(Collection $steps): ?string
    {
        $last = $steps->last();
        if (! $last) {
            return 'guard';
        }

        // 'research' ghi hai lần: bài rủi ro cao tự chuẩn bị kiến thức TRƯỚC dàn ý, và "Chuẩn bị tài liệu" SAU dàn ý. Chưa có dàn ý thì đang lập dàn ý, có rồi thì đang viết.
        if ($last->stage === 'research') {
            return $steps->contains('stage', 'brief') ? 'write' : 'brief';
        }

        return match ($last->stage) {
            'guard' => 'risk',
            'risk' => 'brief',
            'brief' => 'write',
            'write' => 'grade',
            'retry' => 'write',
            'grade' => $last->status === 'ok' ? 'handoff' : 'repair',
            'repair' => 'grade',
            'decision' => 'handoff',
            default => null,
        };
    }

    /**
     * Như currentStage() nhưng biết loại việc: luồng bài là luồng cố định; loại việc khác chưa có luồng cố định nên lấy chặng
     * của bước cuối đã ghi (chưa có bước nào: chặng khai đầu tiên của loại việc, hoặc null).
     */
    public static function currentStageOf(AgentRun $run, Collection $steps): ?string
    {
        if (! $run->job_type || $run->job_type === 'article_write') {
            return self::currentStage($steps);
        }

        return $steps->last()?->stage ?? (array_key_first(JobTypeRegistry::stageLabels($run->job_type, true)) ?: null);
    }

    /** @return Collection<int, AgentRun> các phiên running còn sống */
    public static function liveRuns(): Collection
    {
        return AgentRun::query()->where('status', 'running')->with('steps')->get()
            ->filter(fn (AgentRun $r) => self::isLive($r))->values();
    }

    public static function liveCount(): int
    {
        return self::liveRuns()->count();
    }

    /** @return array<string, int> mã chặng => số phiên đang ở chặng đó */
    public static function liveStages(): array
    {
        $out = [];
        foreach (self::liveRuns() as $r) {
            $s = self::currentStage($r->steps);
            if ($s) {
                $out[$s] = ($out[$s] ?? 0) + 1;
            }
        }

        return $out;
    }

    /** Có phiên running (kể cả có thể treo) để biết khi nào cần poll nhanh. */
    public static function anyRunning(): bool
    {
        return AgentRun::query()->where('status', 'running')->exists();
    }
}
