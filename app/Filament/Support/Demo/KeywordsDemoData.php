<?php

namespace App\Filament\Support\Demo;

/** Dữ liệu MẪU cho màn Từ khóa (chỉ khi DemoMode::enabled() và kho chưa từng được khởi tạo). Không phải số thật. */
final class KeywordsDemoData
{
    /** @return list<array{0:string,1:?int,2:string}> [từ khóa, volume, ý định] */
    public static function keywords(): array
    {
        return [
            ['học bổng du học Nhật Bản', 1900, 'informational'], ['học bổng du học Hàn Quốc', 1600, 'informational'], ['học bổng du học Úc', 1300, 'informational'],
            ['học bổng toàn phần là gì', 880, 'informational'], ['điều kiện xin học bổng toàn phần', 720, 'informational'], ['cách săn học bổng toàn phần', 590, 'informational'],
            ['học bổng chính phủ Hàn Quốc GKS', 480, 'informational'], ['hồ sơ xin học bổng cần những gì', 390, 'informational'], ['học bổng MEXT Nhật Bản', 320, 'informational'],
            ['tuyển sinh đại học 2026', 5400, 'informational'], ['tuyển sinh đại học 2026 điểm chuẩn', 4400, 'informational'], ['phương thức tuyển sinh đại học 2026', 2900, 'informational'],
            ['tuyển sinh đại học 2026 khi nào', 1300, 'informational'], ['đăng ký xét tuyển đại học online', 1600, 'transactional'], ['tuyển sinh cao đẳng 2026', 1000, 'informational'],
            ['lệ phí xét tuyển đại học', 590, 'informational'], ['ngành công nghệ thông tin học gì', 2400, 'informational'], ['ngành công nghệ thông tin ra trường làm gì', 1900, 'informational'],
            ['ngành công nghệ thông tin lương bao nhiêu', 1600, 'informational'], ['ngành marketing học gì', 1300, 'informational'], ['ngành marketing ra trường làm gì', 1000, 'informational'],
            ['ngành quản trị kinh doanh học gì', 1300, 'informational'], ['ngành quản trị kinh doanh ra trường làm gì', 880, 'informational'], ['ngành ngôn ngữ Anh học gì', 1100, 'informational'],
            ['ngành y khoa điểm chuẩn', 2900, 'informational'], ['ngành dược học những trường nào', 720, 'commercial'], ['so sánh ngành kế toán và kiểm toán', 480, 'commercial'],
            ['trường đại học tốt nhất cho ngành công nghệ thông tin', 590, 'commercial'], ['review trường đại học ngoại thương', 390, 'commercial'], ['học phí đại học 2026', 3600, 'informational'],
            ['học phí đại học ngoại thương', 1600, 'informational'], ['học phí đại học bách khoa', 1300, 'informational'], ['vay vốn sinh viên học phí', 880, 'transactional'],
            ['du học Nhật Bản chi phí bao nhiêu', 1900, 'informational'], ['du học Hàn Quốc chi phí bao nhiêu', 1600, 'informational'], ['du học Úc chi phí bao nhiêu', 880, 'informational'],
            ['chứng chỉ IELTS cần bao nhiêu điểm xin học bổng', 720, 'informational'], ['luyện thi IELTS online', 2400, 'commercial'], ['đăng ký thi IELTS', 1900, 'transactional'],
            ['cổng thông tin tuyển sinh bộ giáo dục', 320, 'navigational'],
        ];
    }

    /** Khởi tạo kho lần đầu: chỉ khi bật chế độ minh họa và chưa từng có dữ liệu (null, khác với rỗng do người dùng xóa hết). */
    public static function seedIfEmpty(): void
    {
        if (! DemoMode::enabled() || DemoStore::get('keywords.list') !== null) {
            return;
        }
        $list = [];
        foreach (self::keywords() as $i => [$kw, $vol, $intent]) {
            $list[] = ['id' => $i + 1, 'keyword' => $kw, 'volume' => $vol, 'difficulty' => null, 'intent' => $intent, 'source' => $i % 7 === 6 ? 'tay' : 'csv', 'cluster' => null];
        }
        DemoStore::put('keywords.list', $list);
        DemoStore::put('keywords.clusters', []);
    }
}
