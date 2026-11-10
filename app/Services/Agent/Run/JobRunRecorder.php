<?php

namespace App\Services\Agent\Run;

use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\Log;

/**
 * G2: ghi một phiên `agent_runs` cho loại việc KHÔNG gắn bài (ví dụ market_research), cùng quy ước với AgentRunOrchestrator.
 * Chỉ ghi số lượng, mã model, chi phí và danh sách tham chiếu; không ghi lời nhắc đầy đủ hay khóa API.
 * Lỗi khi ghi (ví dụ bảng chưa migrate) chỉ vào log, KHÔNG làm hỏng việc chính: mọi hàm trả null/bỏ qua khi không có phiên.
 */
final class JobRunRecorder
{
    private const ROLES = ['system', 'strategist', 'writer', 'editor'];

    private const STATUSES = ['running', 'ready_for_review', 'held', 'blocked', 'failed'];

    /** @var array<int,int> id phiên => seq bước cuối */
    private static array $seq = [];

    public static function start(string $jobType, Website|int $website, array $inputRefs = [], ?User $by = null, string $trigger = 'manual', string $aiMode = 'real'): ?AgentRun
    {
        try {
            $run = AgentRun::create([
                'website_id' => $website instanceof Website ? $website->id : $website, 'job_type' => $jobType, 'article_id' => null,
                'triggered_by' => $by?->id, 'trigger' => $trigger, 'status' => 'running', 'ai_mode' => $aiMode === 'mock' ? 'mock' : 'real',
                'input_refs' => $inputRefs ?: null, 'started_at' => now(),
            ]);
            self::$seq[$run->id] = 0;

            return $run;
        } catch (\Throwable $e) {
            Log::warning('JobRunRecorder: không ghi được phiên: '.class_basename($e));

            return null;
        }
    }

    public static function step(?AgentRun $run, string $stage, string $title, string $status, array $data = [], ?string $model = null, int $tokens = 0, float $cost = 0, ?string $detail = null, string $role = 'system'): ?AgentRunStep
    {
        if ($run === null) {
            return null;
        }
        try {
            $seq = self::$seq[$run->id] ?? (int) $run->steps()->max('seq');
            self::$seq[$run->id] = ++$seq;
            $now = now();

            return AgentRunStep::create([
                'agent_run_id' => $run->id, 'seq' => $seq, 'stage' => mb_substr($stage, 0, 20), 'role' => in_array($role, self::ROLES, true) ? $role : 'system',
                'status' => $status, 'title' => mb_substr($title, 0, 255), 'detail' => $detail, 'data' => $data ?: null, 'model' => $model !== null ? mb_substr($model, 0, 60) : null,
                'tokens_used' => $tokens, 'cost_vnd' => $cost, 'started_at' => $now, 'finished_at' => $now,
            ]);
        } catch (\Throwable $e) {
            Log::warning('JobRunRecorder: không ghi được bước: '.class_basename($e));

            return null;
        }
    }

    public static function finish(?AgentRun $run, string $status, ?array $outputRef = null, ?string $reason = null): ?AgentRun
    {
        if ($run === null) {
            return null;
        }
        try {
            $run->update([
                'status' => in_array($status, self::STATUSES, true) ? $status : 'failed', 'output_ref' => $outputRef, 'outcome_reason' => $reason !== null ? mb_substr($reason, 0, 1000) : null,
                'finished_at' => now(), 'tokens_used' => (int) $run->steps()->sum('tokens_used'), 'cost_vnd' => (float) $run->steps()->sum('cost_vnd'),
            ]);
            unset(self::$seq[$run->id]);

            return $run->refresh();
        } catch (\Throwable $e) {
            Log::warning('JobRunRecorder: không chốt được phiên: '.class_basename($e));

            return null;
        }
    }
}
