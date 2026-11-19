<?php

namespace App\Filament\Support\AiUsage;

use App\Models\AgentRun;

/**
 * MỘT hàm tính "chi phí đội AI" = tổng agent_runs.cost_vnd (cả luồng: dàn ý, nghiên cứu, viết, chấm) của các phiên trong [từ, đến).
 * Tổng quan ("hôm nay") và Bản tin ("hôm qua") dùng chung; khác nhau chỉ ở cửa sổ thời gian, nhãn phải nêu rõ cửa sổ.
 * $from/$to là chuỗi theo app.timezone (VnTime::dayRangeApp), vì started_at/created_at do Eloquent ghi theo múi giờ đó.
 */
final class RunCost
{
    public static function between(string $from, string $to, string $column = 'started_at'): float
    {
        return (float) AgentRun::query()->where($column, '>=', $from)->where($column, '<', $to)->sum('cost_vnd');
    }
}
