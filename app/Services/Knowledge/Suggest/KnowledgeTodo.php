<?php

namespace App\Services\Knowledge\Suggest;

use App\Models\KnowledgeItem;
use App\Models\Website;

/**
 * "Việc cần xem" của kho: MỘT nguồn số cho banner (Kho trí tuệ, Tổng quan) và cho mục "Việc cần bạn xem" ở trang Tạo trí tuệ doanh nghiệp.
 * Số = gợi ý cho kho (tối đa LIMIT, cùng cách đếm với danh sách hiện ở nơi đến) + mục chờ duyệt của CHÍNH website đó. Banner dẫn tới website có nhiều việc nhất, không dẫn tới website mặc định.
 */
final class KnowledgeTodo
{
    public const LIMIT = 50;

    /** @return array{suggestions: int, pending: int, total: int} */
    public static function forWebsite(Website $site): array
    {
        $s = count((new KnowledgeSuggestions)->forWebsite($site, self::LIMIT));
        $p = KnowledgeItem::query()->where('status', 'proposed')->where('website_id', $site->id)->count();

        return ['suggestions' => $s, 'pending' => $p, 'total' => $s + $p];
    }

    /** @return array{site: Website, suggestions: int, pending: int, total: int}|null website đang hoạt động có nhiều việc nhất (hòa: id nhỏ); null khi không website nào có việc */
    public static function busiest(): ?array
    {
        $best = null;
        foreach (Website::query()->where('status', 'active')->orderBy('id')->get() as $w) {
            try {
                $t = self::forWebsite($w);
            } catch (\Throwable) {
                continue; // số việc chỉ là thông tin phụ: lỗi một website thì bỏ qua website đó
            }
            if ($t['total'] > 0 && ($best === null || $t['total'] > $best['total'])) {
                $best = ['site' => $w] + $t;
            }
        }

        return $best;
    }

    public static function url(?int $websiteId): string
    {
        return \App\Filament\Pages\BrainBuildPage::getUrl($websiteId ? ['site' => $websiteId] : []).'#can-xem';
    }
}
