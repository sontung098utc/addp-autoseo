<?php

namespace App\Services\Agent\Trust;

use App\Models\AgentRun;
use Carbon\CarbonInterface;

/**
 * AG-S12: chỉ số "mức tự động" THẬT, tính từ agent_runs. Không có dữ liệu thì trả null (hiển thị "Chưa có dữ liệu"),
 * KHÔNG BAO GIỜ điền 0 hay chia cho 0 để giả là đã đo.
 *
 * Phiên "kết thúc" = ready_for_review | held | failed. Phiên blocked (dừng khẩn cấp, website tắt) và running không tính
 * vì không phản ánh chất lượng của đội AI.
 */
final class TrustMetrics
{
    public const FINISHED = ['ready_for_review', 'held', 'failed'];

    /** @return array<string, array{value: float|null, n: int}> */
    public static function compute(?CarbonInterface $since = null): array
    {
        $q = AgentRun::query()->whereIn('status', self::FINISHED)->where('job_type', 'article_write'); // mức tự động của việc viết bài; loại việc khác có thước đo riêng
        if ($since) {
            $q->where('created_at', '>=', $since);
        }
        $runs = $q->get(['status', 'repair_rounds', 'cost_vnd', 'ai_mode', 'started_at', 'finished_at']);

        $ready = $runs->where('status', 'ready_for_review');
        $repaired = $runs->where('repair_rounds', '>', 0);
        $durations = $ready->filter(fn ($r) => $r->started_at && $r->finished_at)
            ->map(fn ($r) => (float) $r->started_at->diffInSeconds($r->finished_at, true));

        return [
            'finished' => ['value' => $runs->count() ?: null, 'n' => $runs->count()],
            'completion_rate' => self::ratio($ready->count(), $runs->count()),
            'repair_success_rate' => self::ratio($repaired->where('status', 'ready_for_review')->count(), $repaired->count()),
            'avg_repair_rounds' => self::mean($runs->pluck('repair_rounds')->map(fn ($v) => (float) $v)->all()),
            'hold_rate' => self::ratio($runs->where('status', 'held')->count(), $runs->count()),
            'fail_rate' => self::ratio($runs->where('status', 'failed')->count(), $runs->count()),
            // Chi phí cả phiên lỗi/giữ nháp cũng tính, chia cho số bản nháp thật sự tạo ra.
            'cost_per_draft' => $ready->isEmpty() ? ['value' => null, 'n' => 0]
                : ['value' => (float) $runs->sum('cost_vnd') / $ready->count(), 'n' => $ready->count()],
            'seconds_to_draft' => self::mean($durations->values()->all()),
            // Số bài đã qua tay người duyệt mà vẫn do AI giả lập: phải = 0 mới đạt, nhưng chỉ có nghĩa khi có bài.
            'mock_in_drafts' => $ready->isEmpty() ? ['value' => null, 'n' => 0]
                : ['value' => (float) $ready->where('ai_mode', 'mock')->count(), 'n' => $ready->count()],
        ];
    }

    /** Chỉ số CHƯA có cách đo. Luôn "Chưa có dữ liệu", kèm lý do. @return array<string, string> */
    public static function unmeasured(): array
    {
        return [
            'Kiểm mẫu sau đăng' => 'Chưa có bước kiểm mẫu bài sau khi đăng.',
            'Số lần gỡ bài' => 'Chưa có chức năng gỡ bài, nên chưa đếm được.',
            'Hiệu quả Search Console' => 'Search Console chưa kết nối (đang là dữ liệu minh họa).',
        ];
    }

    /** @return array{value: float|null, n: int} */
    private static function ratio(int $part, int $whole): array
    {
        return $whole === 0 ? ['value' => null, 'n' => 0] : ['value' => $part / $whole, 'n' => $whole];
    }

    /** @param list<float> $values @return array{value: float|null, n: int} */
    private static function mean(array $values): array
    {
        return $values === [] ? ['value' => null, 'n' => 0] : ['value' => array_sum($values) / count($values), 'n' => count($values)];
    }
}
