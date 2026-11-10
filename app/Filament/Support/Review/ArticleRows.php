<?php

namespace App\Filament\Support\Review;

use App\Filament\Support\Agent\RunActivity;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\Campaign;

/**
 * AG-S16: dữ liệu phụ cho từng dòng ở màn Bài viết (phiên gần nhất, chiến dịch, đang chạy).
 * Chỉ ĐỌC; kết quả nhớ trên chính instance Article nên mỗi lượt vẽ lại là dữ liệu mới.
 */
final class ArticleRows
{
    /** Chặng ngắn gọn cho badge (khớp mã stage của RunActivity::currentStage). */
    public const STAGE_LABELS = [
        'guard' => 'Kiểm', 'risk' => 'Rủi ro', 'brief' => 'Dàn ý', 'write' => 'Viết',
        'grade' => 'Chấm', 'repair' => 'Sửa', 'handoff' => 'Bàn giao',
    ];

    /** Nhãn ngắn của phiên cho badge ở bảng; nhãn đầy đủ nằm ở tooltip. */
    public const RUN_SHORT = [
        'running' => 'Đang chạy', 'ready_for_review' => 'Chờ duyệt', 'held' => 'Giữ nháp', 'blocked' => 'Bị chặn',
        'failed' => 'Lỗi', 'abandoned' => 'Đã bỏ',
    ];

    public static function lastRun(Article $a): ?AgentRun
    {
        if (! $a->relationLoaded('lastRun')) {
            $a->setRelation('lastRun', AgentRun::query()->where('article_id', $a->id)->with('steps')->orderByDesc('id')->first());
        }

        return $a->getRelation('lastRun');
    }

    /** Phiên đang chạy thật (không tính phiên có thể bị treo). */
    public static function liveRun(Article $a): ?AgentRun
    {
        $r = self::lastRun($a);

        return $r && RunActivity::isLive($r) ? $r : null;
    }

    public static function stageLabel(AgentRun $run): ?string
    {
        $s = RunActivity::currentStage($run->steps);

        return $s ? (self::STAGE_LABELS[$s] ?? null) : null;
    }

    public static function campaignName(Article $a): ?string
    {
        $id = self::lastRun($a)?->campaign_id;

        return $id ? Campaign::query()->whereKey($id)->value('name') : null;
    }

    public static function score(AgentRun $run): ?int
    {
        $s = $run->steps->where('stage', 'grade')->last()?->data['score'] ?? null;

        return $s === null ? null : (int) $s;
    }
}
