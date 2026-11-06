<?php

namespace App\Filament\Support\Demo;

/** Dữ liệu MINH HỌA cho màn Đối thủ (chỉ dùng khi DemoMode::enabled()). Tên miền .example là tên giả. */
final class CompetitorsDemoData
{
    /** @return list<string> */
    public static function sampleDomains(): array
    {
        return ['tuyensinhhub.example', 'hocbong360.example', 'nganhhoc.example'];
    }

    /** @return list<array{keyword:string, intent:string, volume:int, difficulty:int}> */
    public static function keywordPool(): array
    {
        return [
            ['keyword' => 'học bổng toàn phần du học 2027', 'intent' => 'informational', 'volume' => 6600, 'difficulty' => 52],
            ['keyword' => 'điều kiện xét tuyển học bạ', 'intent' => 'informational', 'volume' => 9900, 'difficulty' => 61],
            ['keyword' => 'ngành marketing học những gì', 'intent' => 'informational', 'volume' => 4400, 'difficulty' => 38],
            ['keyword' => 'so sánh ngành thương mại điện tử và marketing', 'intent' => 'commercial', 'volume' => 1900, 'difficulty' => 29],
            ['keyword' => 'học phí ngành marketing các trường 2027', 'intent' => 'commercial', 'volume' => 3600, 'difficulty' => 44],
            ['keyword' => 'kỹ năng cần có khi học marketing', 'intent' => 'informational', 'volume' => 1300, 'difficulty' => 22],
            ['keyword' => 'lộ trình học bổng chính phủ', 'intent' => 'informational', 'volume' => 2700, 'difficulty' => 47],
            ['keyword' => 'test trắc nghiệm chọn ngành', 'intent' => 'navigational', 'volume' => 8100, 'difficulty' => 58],
            ['keyword' => 'học bổng du học thạc sĩ châu âu', 'intent' => 'informational', 'volume' => 2900, 'difficulty' => 41],
            ['keyword' => 'cách viết thư xin học bổng', 'intent' => 'informational', 'volume' => 1700, 'difficulty' => 26],
        ];
    }

    /**
     * Thứ hạng giả lập XÁC ĐỊNH theo (tên miền, từ khóa): ổn định giữa các lần chạy.
     *
     * @param  list<string>  $domains
     * @return list<array{keyword:string, intent:string, volume:int, difficulty:int, ranks:array<string,int>}>
     */
    public static function rankings(array $domains): array
    {
        $out = [];
        foreach (self::keywordPool() as $k) {
            $ranks = [];
            foreach ($domains as $d) {
                $h = crc32($d.'|'.$k['keyword']);
                if ($h % 3 !== 0) {
                    $ranks[$d] = 1 + ($h >> 3) % 10;
                }
            }
            if ($ranks !== []) {
                $out[] = $k + ['ranks' => $ranks];
            }
        }

        return $out;
    }
}
