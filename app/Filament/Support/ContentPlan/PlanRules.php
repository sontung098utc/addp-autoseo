<?php

namespace App\Filament\Support\ContentPlan;

use App\Filament\Support\Planning\PlannedArticleCreator;
use Carbon\CarbonImmutable;

/** Luật THẬT của Kế hoạch nội dung (PHP thuần). */
final class PlanRules
{
    /** Tạm thời hằng số cho tới khi có bảng campaigns (giới hạn bài/ngày theo chiến dịch). */
    public const DAILY_LIMIT = 3;

    /** Giờ đăng chuẩn trong ngày (giờ VN). */
    public const SLOTS = ['09:00', '14:00', '19:00'];

    /**
     * Độ phủ cụm: từ khóa nào đã có bài (so khớp chuẩn hóa với primary_keyword).
     *
     * @param  list<string>  $clusterKeywords
     * @param  list<string>  $articleKeywords  primary_keyword của các bài trên website
     * @return array{covered:list<string>, free:list<string>, percent:int}
     */
    public static function coverage(array $clusterKeywords, array $articleKeywords): array
    {
        $have = array_flip(array_map([PlannedArticleCreator::class, 'normalize'], $articleKeywords));
        $covered = $free = [];
        foreach ($clusterKeywords as $k) {
            isset($have[PlannedArticleCreator::normalize($k)]) ? $covered[] = $k : $free[] = $k;
        }
        $n = count($clusterKeywords);

        return ['covered' => $covered, 'free' => $free, 'percent' => $n ? (int) round(count($covered) / $n * 100) : 0];
    }

    /**
     * "Ăn thịt từ khóa": từ khóa chuẩn hóa được nhiều bài cùng dùng.
     *
     * @param  list<array{id:int, keyword:string}>  $articles
     * @return array<string, list<int>> từ khóa chuẩn hóa => id các bài
     */
    public static function cannibalized(array $articles): array
    {
        $g = [];
        foreach ($articles as $a) {
            $g[PlannedArticleCreator::normalize($a['keyword'])][] = $a['id'];
        }

        return array_filter($g, fn ($ids) => count($ids) > 1);
    }

    /** Ngày còn chỗ? Trả lý do nếu không. @param  int  $already số bài đã có trong ngày */
    public static function dayFullReason(int $already, int $adding = 1): ?string
    {
        if ($already + $adding > self::DAILY_LIMIT) {
            return 'Ngày này đã đủ '.self::DAILY_LIMIT.' bài/ngày (giới hạn tạm cho tới khi có bảng chiến dịch).';
        }

        return null;
    }

    /** Lý do KHÔNG đổi ngày được ở màn này (null = được). */
    public static function rescheduleBlockReason(string $status, CarbonImmutable $targetDayVn, int $alreadyOnTarget, ?CarbonImmutable $nowVn = null): ?string
    {
        if ($status !== 'planned') {
            return in_array($status, ['approved', 'scheduled', 'publishing', 'published'], true)
                ? 'Bài này đã qua bước lên kế hoạch: xếp lịch đăng làm ở màn Lịch xuất bản, không đổi ngày ở đây.'
                : 'Chỉ bài ở trạng thái "Đã lên kế hoạch" mới đổi ngày ở đây.';
        }
        $now = $nowVn ?? CarbonImmutable::now('Asia/Ho_Chi_Minh');
        if ($targetDayVn->startOfDay()->lt($now->startOfDay())) {
            return 'Không đổi sang ngày đã qua.';
        }

        return self::dayFullReason($alreadyOnTarget);
    }

    /** Giờ trống đầu tiên của ngày (giờ VN) hoặc null nếu hết. @param  list<string>  $taken "H:i" đã dùng */
    public static function firstFreeSlot(array $taken): ?string
    {
        foreach (self::SLOTS as $s) {
            if (! in_array($s, $taken, true)) {
                return $s;
            }
        }

        return null;
    }
}
