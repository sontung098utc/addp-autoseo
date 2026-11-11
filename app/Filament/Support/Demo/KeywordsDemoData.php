<?php

namespace App\Filament\Support\Demo;

/** Dữ liệu MẪU cho màn Từ khóa (chỉ khi DemoMode::enabled() và kho chưa từng được khởi tạo). Không phải số thật. Chủ đề: website ADDP (thực phẩm chức năng, thiết bị theo dõi sức khỏe). */
final class KeywordsDemoData
{
    public const CLUSTER_SUGAR = 'Đường huyết và tiểu đường';

    public const CLUSTER_METER = 'Máy đo đường huyết';

    public const CLUSTER_BP = 'Huyết áp';

    public const CLUSTER_MEAL = 'Ăn uống cho người tiểu đường';

    /**
     * @return list<array{0:string,1:?int,2:string,3:?string,4:string,5:?string,6:?string}>
     *                                                                                      [từ khóa, volume, ý định, nguồn số liệu, nguồn từ khóa, cụm, kế hoạch (planned|article)]
     */
    public static function rows(): array
    {
        $s = self::CLUSTER_SUGAR;
        $m = self::CLUSTER_METER;
        $b = self::CLUSTER_BP;
        $a = self::CLUSTER_MEAL;

        return [
            ['chỉ số đường huyết bình thường', 4400, 'informational', 'dataforseo', 'csv', $s, 'article'],
            ['đường huyết bao nhiêu là tiểu đường', 2900, 'informational', 'dataforseo', 'csv', $s, 'article'],
            ['triệu chứng tiểu đường type 2', 3600, 'informational', 'dataforseo', 'csv', $s, null],
            ['cách hạ đường huyết nhanh', 1900, 'informational', 'dataforseo', 'csv', $s, 'planned'],
            ['đường huyết lúc đói bao nhiêu là cao', 1300, 'informational', 'dataforseo', 'tay', $s, null],
            ['máy đo đường huyết loại nào tốt', 2400, 'commercial', 'dataforseo', 'csv', $m, 'planned'],
            ['giá máy đo đường huyết', 1600, 'commercial', 'dataforseo', 'csv', $m, null],
            ['que thử đường huyết dùng thế nào', 880, 'informational', 'dataforseo', 'ai', $m, null],
            ['mua máy đo đường huyết Accu-Chek', 720, 'transactional', 'csv', 'competitor', $m, null],
            ['máy đo đường huyết tại nhà có chính xác không', 590, 'informational', null, 'ai', $m, null],
            ['máy đo huyết áp bắp tay loại nào tốt', 3200, 'commercial', 'dataforseo', 'csv', $b, 'planned'],
            ['huyết áp cao nên ăn gì', 2900, 'informational', 'dataforseo', 'csv', $b, null],
            ['huyết áp thấp là bao nhiêu', 1900, 'informational', 'dataforseo', 'csv', $b, null],
            ['bữa ăn cho người tiểu đường', 1600, 'informational', 'dataforseo', 'csv', $a, null],
            ['thực đơn người tiểu đường 7 ngày', 1300, 'informational', 'csv', 'tay', $a, null],
            ['người tiểu đường ăn trái cây gì', 2400, 'informational', 'dataforseo', 'csv', $a, null],
            ['sữa dành cho người tiểu đường', 1900, 'commercial', 'dataforseo', 'competitor', $a, null],
            ['cháo yến mạch cho người tiểu đường', 480, 'informational', null, 'ai', $a, null],
            // Chưa gom cụm
            ['thực phẩm chức năng cho người tiểu đường', 2900, 'commercial', 'dataforseo', 'csv', null, null],
            ['viên uống hỗ trợ đường huyết', 1300, 'commercial', 'dataforseo', 'csv', null, null],
            ['mỡ máu cao nên ăn gì', 3600, 'informational', 'dataforseo', 'csv', null, null],
            ['omega 3 loại nào tốt', 2400, 'commercial', 'csv', 'tay', null, null],
            ['vitamin tổng hợp cho người lớn tuổi', 1000, 'commercial', 'csv', 'tay', null, null],
            ['kiểm tra đường huyết tại nhà', 880, 'informational', null, 'ai', null, null],
            ['thực phẩm tốt cho tim mạch', null, 'informational', null, 'ai', null, null],
            ['người cao tuổi nên bổ sung canxi thế nào', null, 'informational', null, 'ai', null, null],
            ['nhà thuốc ADDP', 320, 'navigational', 'csv', 'tay', null, null],
            ['viên uống bổ sung kẽm cho người tiểu đường', null, 'commercial', null, 'competitor', null, null],
            ['đo huyết áp đúng cách tại nhà', null, 'informational', null, 'tay', null, null],
            ['chế độ ăn giảm cholesterol', null, 'informational', null, 'tay', null, null],
        ];
    }

    /** @return list<array{0:string,1:?int,2:string}> [từ khóa, volume, ý định] */
    public static function keywords(): array
    {
        return array_map(fn ($r) => [$r[0], $r[1], $r[2]], self::rows());
    }

    /** Khởi tạo kho lần đầu: chỉ khi bật chế độ minh họa và chưa từng có dữ liệu (null, khác với rỗng do người dùng xóa hết). */
    public static function seedIfEmpty(): void
    {
        if (! DemoMode::enabled() || DemoStore::get('keywords.list') !== null) {
            return;
        }
        self::reseed();
    }

    /** Ghi đè kho minh họa bằng bộ mẫu. Chỉ chạm DemoStore (cache), không đụng database. */
    public static function reseed(): void
    {
        if (! DemoMode::enabled()) {
            return;
        }
        $list = [];
        $clusters = [];
        foreach (self::rows() as $i => [$kw, $vol, $intent, $vsrc, $src, $cluster, $plan]) {
            $list[] = ['id' => $i + 1, 'keyword' => $kw, 'volume' => $vol, 'volume_source' => $vol !== null ? $vsrc : null, 'difficulty' => null, 'intent' => $intent, 'source' => $src, 'cluster' => $cluster, 'plan' => $plan];
            if ($cluster !== null) {
                $clusters[$cluster]['name'] = $cluster;
                $clusters[$cluster]['intent'] = $clusters[$cluster]['intent'] ?? $intent;
                $clusters[$cluster]['keywords'][] = $kw;
            }
        }
        DemoStore::put('keywords.list', $list);
        DemoStore::put('keywords.clusters', array_values($clusters));
        DemoStore::put('keywords.proposals', []);
    }
}
