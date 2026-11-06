<?php

namespace App\Filament\Support\Demo;

use Carbon\CarbonImmutable;

/**
 * Bộ sinh số liệu Search Console MINH HỌA, xác định theo ngày (không ngẫu nhiên thật): 56 ngày kết thúc ở
 * (hôm nay - 3). Số học nhất quán: clicks <= impressions; tổng theo trang >= tổng theo truy vấn (mô phỏng
 * truy vấn ẩn danh); thiết bị/quốc gia là phần chia của tổng theo trang.
 */
final class GscDemoData
{
    public const DAYS = 56;

    public const LAG_DAYS = 3;

    public const BASE = 'https://blog.company.vn';

    /** @return list<string> đường dẫn trang mẫu */
    public static function pagePaths(): array
    {
        return ['/giay-chay-bo-nam', '/cach-chon-giay-the-thao', '/review-giay-nike-pegasus', '/bao-quan-giay-da', '/size-giay-nu', '/giay-cao-got-di-lam', '/top-dep-sandal-2026', '/vo-bong-da-chinh-hang'];
    }

    /**
     * Truy vấn mẫu: [truy vấn, chỉ số trang, vị trí kỳ trước, impressions/ngày kỳ trước, dịch vị trí kỳ này,
     * nhân impressions kỳ này, hệ số CTR so với kỳ vọng].
     *
     * @return list<array{0:string,1:int,2:float,3:int,4:float,5:float,6:float}>
     */
    public static function queries(): array
    {
        return [
            ['giày chạy bộ nam', 0, 3.2, 120, 0.4, 1.0, 1.0],
            ['giày chạy bộ nam tốt nhất', 0, 9.5, 60, -0.3, 1.1, 1.0],
            ['giày chạy bộ giá rẻ', 0, 14.2, 45, 0.2, 1.0, 0.9],
            ['cách chọn giày thể thao', 1, 8.4, 80, 0.1, 1.2, 0.35],
            ['chọn size giày thể thao', 1, 5.0, 40, 0.0, 1.0, 1.0],
            ['giày thể thao nam nữ khác nhau', 1, 17.0, 25, -0.5, 1.0, 1.0],
            ['review nike pegasus', 2, 4.1, 70, 4.2, 1.0, 1.0],
            ['nike pegasus 41 có tốt không', 2, 6.3, 50, 3.4, 1.0, 1.0],
            ['so sánh pegasus và vaporfly', 2, 11.0, 30, 0.0, 0.9, 1.0],
            ['cách bảo quản giày da', 3, 2.4, 90, 0.2, 1.0, 1.0],
            ['vệ sinh giày da lộn', 3, 7.2, 55, -0.2, 1.0, 1.0],
            ['xi đánh giày da loại nào tốt', 3, 12.6, 35, 0.0, 1.3, 0.5],
            ['bảng size giày nữ', 4, 1.8, 150, 0.1, 1.0, 1.0],
            ['đo size giày nữ chuẩn', 4, 3.7, 65, 0.0, 1.0, 1.0],
            ['size giày nữ 37 là bao nhiêu cm', 4, 8.9, 40, 0.0, 1.0, 1.0],
            ['giày cao gót đi làm', 5, 10.2, 70, 0.0, 1.0, 1.0],
            ['giày cao gót êm chân', 5, 13.8, 45, 0.3, 1.0, 0.55],
            ['giày công sở nữ', 5, 19.0, 28, 0.0, 1.0, 1.0],
            ['dép sandal nữ đẹp 2026', 6, 6.0, 100, 3.5, 0.9, 1.0],
            ['sandal đi biển', 6, 15.5, 38, 0.0, 1.0, 1.0],
            ['sandal nam quai ngang', 6, 25.0, 60, 0.0, 1.0, 1.0],
            ['áo bóng đá chính hãng', 7, 21.5, 33, 0.0, 1.0, 1.0],
            ['mua áo bóng đá ở đâu uy tín', 7, 9.0, 36, 0.0, 1.0, 0.4],
            ['cách phân biệt áo bóng đá thật giả', 7, 2.9, 20, 0.0, 1.0, 1.0],
        ];
    }

    /** CTR kỳ vọng theo vị trí: ƯỚC LƯỢNG cố định (không phải số đo của website), dùng cho mô phỏng và luật CTR thấp. */
    public static function expectedCtr(float $position): float
    {
        $table = [1 => 0.30, 2 => 0.16, 3 => 0.10, 4 => 0.07, 5 => 0.05, 6 => 0.04, 7 => 0.03, 8 => 0.025, 9 => 0.02, 10 => 0.017];
        $r = max(1, (int) round($position));

        return $table[$r] ?? ($r <= 20 ? 0.010 : 0.004);
    }

    public static function lastDate(CarbonImmutable $today): CarbonImmutable
    {
        return $today->startOfDay()->subDays(self::LAG_DAYS);
    }

    private static function noise(string $a, string $b): float
    {
        return (crc32($a.'|'.$b) % 1000) / 1000;
    }

    /**
     * @param  list<string>  $realUrls URL bài đã đăng thật (nếu DB có) thay cho các trang mẫu đầu tiên
     * @return list<array{date:string,key:string,page:?string,clicks:int,impressions:int,position:float}>
     */
    public static function rows(string $dimension, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $today, array $realUrls = []): array
    {
        $last = self::lastDate($today);
        $first = $last->subDays(self::DAYS - 1);
        $urls = [];
        foreach (self::pagePaths() as $i => $path) {
            $urls[$i] = $realUrls[$i] ?? self::BASE.$path;
        }
        $currentStart = $last->subDays(27);

        $queryRows = [];
        $from = $from->startOfDay();
        for ($d = max($from, $first); $d->lte(min($to->startOfDay(), $last)); $d = $d->addDay()) {
            $date = $d->toDateString();
            $isCurrent = $d->gte($currentStart);
            foreach (self::queries() as $qi => [$q, $pi, $pos, $impr, $shift, $mult, $ctrF]) {
                $position = max(1.0, $pos + ($isCurrent ? $shift : 0) + (self::noise($q, $date) - 0.5) * 0.4);
                $im = (int) round($impr * ($isCurrent ? $mult : 1.0) * (0.85 + 0.3 * self::noise($date, $q)));
                $clicks = min($im, (int) round($im * self::expectedCtr($position) * $ctrF * (0.9 + 0.2 * self::noise($q, $date.'c'))));
                $queryRows[] = ['date' => $date, 'key' => $q, 'page' => $urls[$pi], 'clicks' => $clicks, 'impressions' => $im, 'position' => round($position, 2)];
            }
        }
        if ($dimension === 'query') {
            return $queryRows;
        }

        // Tổng theo trang = tổng các truy vấn của trang * 1,12 (làm tròn lên): phần dôi là truy vấn ẩn danh Google không trả.
        $pages = [];
        foreach ($queryRows as $r) {
            $k = $r['date']."\0".$r['page'];
            $pages[$k] ??= ['date' => $r['date'], 'key' => $r['page'], 'page' => $r['page'], 'clicks' => 0, 'impressions' => 0, 'pw' => 0.0];
            $pages[$k]['clicks'] += $r['clicks'];
            $pages[$k]['impressions'] += $r['impressions'];
            $pages[$k]['pw'] += $r['position'] * $r['impressions'];
        }
        $pageRows = [];
        foreach ($pages as $p) {
            $im = (int) ceil($p['impressions'] * 1.12);
            $pageRows[] = [
                'date' => $p['date'], 'key' => $p['key'], 'page' => $p['page'],
                'clicks' => min($im, (int) ceil($p['clicks'] * 1.12)), 'impressions' => $im,
                'position' => round($p['pw'] / max(1, $p['impressions']), 2),
            ];
        }
        if ($dimension === 'page') {
            return $pageRows;
        }

        $shares = $dimension === 'device'
            ? ['Di động' => 0.62, 'Máy tính' => 0.33, 'Máy tính bảng' => 0.05]
            : ['Việt Nam' => 0.90, 'Hoa Kỳ' => 0.04, 'Nhật Bản' => 0.03, 'Khác' => 0.03];
        $out = [];
        foreach ($pageRows as $p) {
            $remC = $p['clicks'];
            $remI = $p['impressions'];
            $names = array_keys($shares);
            foreach ($names as $idx => $name) {
                $isLast = $idx === count($names) - 1;
                $im = $isLast ? $remI : (int) floor($p['impressions'] * $shares[$name]);
                $c = $isLast ? $remC : (int) floor($p['clicks'] * $shares[$name]);
                $c = min($c, $im);
                $remI -= $im;
                $remC -= $c;
                $out[$p['date'].'|'.$name] ??= ['date' => $p['date'], 'key' => $name, 'page' => null, 'clicks' => 0, 'impressions' => 0, 'pw' => 0.0];
                $out[$p['date'].'|'.$name]['clicks'] += $c;
                $out[$p['date'].'|'.$name]['impressions'] += $im;
                $out[$p['date'].'|'.$name]['pw'] += ($p['position'] + $idx * 0.3) * $im;
            }
        }

        return array_values(array_map(fn ($o) => [
            'date' => $o['date'], 'key' => $o['key'], 'page' => null,
            'clicks' => min($o['clicks'], $o['impressions']), 'impressions' => $o['impressions'],
            'position' => round($o['pw'] / max(1, $o['impressions']), 2),
        ], $out));
    }
}
