<?php

namespace App\Filament\Support\Demo;

/**
 * BỘ TRANG MẪU cố định (40 trang, HTML thật ngắn, cố ý chứa lỗi on-page) thay cho bước THU THẬP của crawler (task 1.1, Dev1).
 * Đây là dữ liệu MINH HỌA; việc PHÂN TÍCH bộ này là THẬT (OnPageAnalyzer).
 *
 * "Mức quét" ($level) mô phỏng việc website được sửa dần: mỗi lỗi có (appear, fix): lỗi hiện ở mức L nếu appear <= L < fix.
 * Mức 1 = lần quét gần nhất lúc mới mở, mức 0 = lần quét trước đó; mỗi "Quét lại" tăng mức thêm 1 để thấy xu hướng.
 */
final class SeoAuditDemoData
{
    public const TITLE_SUFFIX = ' - Tuyển sinh Trường ĐH ABC';

    private const DUP_TITLE = 'Thông tin tuyển sinh năm 2027 - Trường ĐH ABC';

    private const DUP_META = 'Cập nhật thông tin tuyển sinh, học bổng, học phí và hồ sơ nhập học mới nhất của Trường ĐH ABC.';

    private const SENTENCE = 'Sinh viên cần chuẩn bị đầy đủ hồ sơ theo hướng dẫn của nhà trường và nộp đúng thời hạn để được xét tuyển trong kỳ này.';

    /** @var array<string, list<array{string, string}>> [thư mục => [[slug, chủ đề]]] */
    private const SECTIONS = [
        'hoc-bong' => [['hoc-bong-toan-phan-2027', 'Học bổng toàn phần 2027'], ['hoc-bong-khuyen-khich', 'Học bổng khuyến khích'], ['hoc-bong-tai-nang', 'Học bổng tài năng'], ['hoc-bong-vuot-kho', 'Học bổng vượt khó'], ['hoc-bong-quoc-te', 'Học bổng quốc tế'], ['hoc-bong-doanh-nghiep', 'Học bổng doanh nghiệp'], ['hoc-bong-nu-sinh', 'Học bổng nữ sinh viên'], ['hoc-bong-thac-si', 'Học bổng thạc sĩ']],
        'ho-so' => [['ho-so-nhap-hoc', 'Hồ sơ nhập học'], ['ho-so-xet-tuyen', 'Hồ sơ xét tuyển'], ['ho-so-chuyen-truong', 'Hồ sơ chuyển trường'], ['ho-so-bao-luu', 'Hồ sơ bảo lưu kết quả'], ['ho-so-ky-tuc-xa', 'Hồ sơ ký túc xá'], ['ho-so-mien-giam', 'Hồ sơ miễn giảm học phí'], ['ho-so-du-hoc', 'Hồ sơ trao đổi du học'], ['ho-so-tot-nghiep', 'Hồ sơ xét tốt nghiệp']],
        'nganh-hoc' => [['marketing-ra-truong', 'Ngành Marketing'], ['quan-tri-kinh-doanh', 'Ngành Quản trị kinh doanh'], ['cong-nghe-thong-tin', 'Ngành Công nghệ thông tin'], ['ke-toan-kiem-toan', 'Ngành Kế toán kiểm toán'], ['ngon-ngu-anh', 'Ngành Ngôn ngữ Anh'], ['luat-kinh-te', 'Ngành Luật kinh tế'], ['tai-chinh-ngan-hang', 'Ngành Tài chính ngân hàng'], ['logistics', 'Ngành Logistics']],
        'hoc-phi' => [['hoc-phi-kinh-te-2027', 'Học phí khối kinh tế 2027'], ['hoc-phi-ky-thuat-2027', 'Học phí khối kỹ thuật'], ['hoc-phi-ngon-ngu-2027', 'Học phí khối ngôn ngữ'], ['hoc-phi-thac-si', 'Học phí hệ thạc sĩ'], ['hoc-phi-lien-thong', 'Học phí hệ liên thông'], ['lo-trinh-dong-phi', 'Lộ trình đóng học phí']],
        'blog' => [['diem-chuan-nam-ngoai', 'Điểm chuẩn năm ngoái'], ['cach-chon-nganh', 'Cách chọn ngành phù hợp'], ['kinh-nghiem-phong-van', 'Kinh nghiệm phỏng vấn'], ['cuoc-song-sinh-vien', 'Cuộc sống sinh viên'], ['viec-lam-sau-ra-truong', 'Việc làm sau ra trường'], ['lich-thi-tuyen-sinh', 'Lịch thi tuyển sinh']],
    ];

    /** Các URL đích trả 404 (liên kết gãy trỏ vào đây). */
    private const DEAD = ['/tai-lieu/mau-don-hoc-bong.pdf', '/tai-lieu/danh-sach-giay-to.pdf', '/nganh-hoc/marketing-cu', '/diem-chuan/2025'];

    /** @var array<int, list<string>> chỉ số trang => 'loại:appear:fix[:chỉ số đích 404]' */
    private const DEFECTS = [
        0 => ['noindex:0:99'], 1 => ['noindex:0:2'], 2 => ['noindex:2:99'],
        3 => ['no_title:0:99'], 4 => ['no_title:0:1'],
        5 => ['broken:0:99:0', 'img_no_alt:0:99'], 6 => ['broken:0:99:1'], 7 => ['broken:0:3:2', 'no_canonical:0:99'], 8 => ['broken:0:99:0'],
        9 => ['broken:0:2:3'], 10 => ['broken:0:99:1', 'thin:0:99'], 11 => ['broken:1:99:2'],
        12 => ['no_meta:0:99', 'no_canonical:0:99'], 13 => ['no_meta:0:99'], 14 => ['no_meta:0:4', 'img_no_alt:0:99'], 15 => ['no_meta:0:99', 'dup_title:0:99'],
        16 => ['dup_title:0:99'], 17 => ['dup_title:0:99'], 18 => ['dup_title:0:1'],
        19 => ['dup_meta:0:99', 'thin:0:99'], 20 => ['dup_meta:0:99'], 21 => ['dup_meta:0:99', 'no_canonical:0:99'],
        22 => ['thin:0:99'], 23 => ['thin:0:2'], 24 => ['thin:0:99', 'no_h1:0:99'], 25 => ['no_h1:0:99'], 26 => ['no_h1:0:1'],
        27 => ['multi_h1:0:99'], 28 => ['multi_h1:0:99', 'long_title:0:99'], 29 => ['multi_h1:2:99', 'long_title:0:99'], 30 => ['long_title:0:99'],
        31 => ['short_title:0:99'], 32 => ['short_title:0:99', 'img_no_alt:0:99'], 33 => ['img_no_alt:0:99', 'no_canonical:0:99'], 34 => ['img_no_alt:0:99'], 35 => ['img_no_alt:0:3'],
    ];

    /**
     * @return list<array{url: string, status: int, html: ?string, in_sitemap: bool}>
     */
    public static function pages(int $level): array
    {
        $pages = [];
        $i = 0;
        foreach (self::SECTIONS as $dir => $list) {
            foreach ($list as [$slug, $topic]) {
                $flags = [];
                $links = [];
                foreach (self::DEFECTS[$i] ?? [] as $spec) {
                    $parts = explode(':', $spec);
                    if ((int) $parts[1] <= $level && $level < (int) $parts[2]) {
                        $flags[$parts[0]] = true;
                        if ($parts[0] === 'broken') {
                            $links[] = self::DEAD[(int) $parts[3]];
                        }
                    }
                }
                $pages[] = [
                    'url' => "/{$dir}/{$slug}",
                    'status' => 200,
                    'html' => self::html($topic, $flags, $links, $i),
                    'in_sitemap' => true,
                ];
                $i++;
            }
        }
        foreach (self::DEAD as $dead) {
            $pages[] = ['url' => $dead, 'status' => 404, 'html' => null, 'in_sitemap' => false];
        }

        return $pages;
    }

    /** @param array<string, bool> $f @param list<string> $links */
    private static function html(string $topic, array $f, array $links, int $i): string
    {
        $title = $topic.self::TITLE_SUFFIX;
        if (! empty($f['dup_title'])) {
            $title = self::DUP_TITLE;
        }
        if (! empty($f['long_title'])) {
            $title = $topic.' - Thông tin tuyển sinh chi tiết của Trường Đại học ABC năm 2027';
        }
        if (! empty($f['short_title'])) {
            $title = 'Tuyển sinh';
        }
        $head = '<meta charset="utf-8">';
        if (empty($f['no_title'])) {
            $head .= '<title>'.$title.'</title>';
        }
        if (empty($f['no_meta'])) {
            $meta = ! empty($f['dup_meta']) ? self::DUP_META : "Thông tin chi tiết về {$topic} tại Trường ĐH ABC: điều kiện, hồ sơ, thời hạn và cách đăng ký mới nhất.";
            $head .= '<meta name="description" content="'.$meta.'">';
        }
        if (empty($f['no_canonical'])) {
            $head .= '<link rel="canonical" href="https://blog.abc.edu.vn/trang-'.$i.'">';
        }
        if (! empty($f['noindex'])) {
            $head .= '<meta name="robots" content="noindex,follow">';
        }

        $body = empty($f['no_h1']) ? "<h1>{$topic}</h1>" : "<h2>{$topic}</h2>";
        if (! empty($f['multi_h1'])) {
            $body .= '<h1>Thông báo quan trọng</h1>';
        }
        $body .= str_repeat('<p>'.self::SENTENCE.'</p>', ! empty($f['thin']) ? 4 : 13);
        $body .= ! empty($f['img_no_alt']) ? '<img src="/anh/khuon-vien-'.$i.'.jpg">' : '<img src="/anh/khuon-vien-'.$i.'.jpg" alt="Khuôn viên Trường ĐH ABC">';
        foreach ($links as $href) {
            $body .= '<p><a href="'.$href.'">Xem thêm tại đây</a></p>';
        }

        return '<!DOCTYPE html><html lang="vi"><head>'.$head.'</head><body>'.$body.'</body></html>';
    }
}
