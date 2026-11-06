<?php

namespace App\Filament\Support\Demo;

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Website;
use RuntimeException;

/**
 * Dữ liệu MẪU để xem thử/test màn Kiểm chất lượng. Không phải dữ liệu thật; kết quả kiểm tra vẫn tính từ nội dung.
 * Mọi bài mẫu có primary_keyword bắt đầu bằng '[Mẫu] '. Chỉ chạy ở môi trường local/testing.
 *
 * Chạy:  php artisan tinker --execute="App\Filament\Support\Demo\QualityDemoData::seed();"
 * Xóa:   php artisan tinker --execute="App\Filament\Support\Demo\QualityDemoData::purge();"
 */
class QualityDemoData
{
    public const PREFIX = '[Mẫu] ';

    private const KW = 'học bổng toàn phần';

    private const LEX = ['sinh', 'viên', 'trường', 'đại', 'học', 'ngành', 'tuyển', 'chương', 'trình', 'kỳ', 'năng', 'lực', 'điều', 'kiện',
        'hồ', 'sơ', 'thời', 'gian', 'đăng', 'ký', 'xét', 'kết', 'quả', 'phỏng', 'vấn', 'nhà', 'quản', 'lý', 'tư', 'vấn', 'cơ', 'hội',
        'việc', 'làm', 'doanh', 'nghiệp', 'thực', 'tập', 'kỹ', 'thuật', 'công', 'nghệ', 'thông', 'tin', 'ngoại', 'ngữ', 'giảng', 'viên'];

    /** Tạo lại bộ mẫu (idempotent: xóa mẫu cũ rồi tạo mới). @return array<string,int> tên kịch bản => id bài */
    public static function seed(): array
    {
        self::guard();
        self::purge();

        $site = Website::where('status', 'active')->where('name', 'not like', self::PREFIX . '%')->orderBy('id')->first()
            ?? Website::create(['name' => self::PREFIX . 'Website kiểm chất lượng', 'cms_type' => 'wordpress', 'base_url' => 'https://mau.example.test', 'encrypted_credentials' => ['demo' => 'khong-co-that'], 'status' => 'active']);

        $out = [];
        $make = function (string $key, string $status, array $versions) use ($site, &$out): void {
            $a = Article::create(['website_id' => $site->id, 'primary_keyword' => self::PREFIX . $key, 'workflow_status' => $status]);
            foreach ($versions as $i => $v) {
                ArticleVersion::create($v + ['article_id' => $a->id, 'version' => $i + 1, 'title' => self::PREFIX . $key, 'slug' => 'mau-' . $a->id . '-v' . ($i + 1)]);
            }
            $out[$key] = $a->id;
        };

        $good = self::attrs(self::html(900, 30));
        $base = self::words(900, 2);
        $copied = implode(' ', array_slice(explode(' ', $base), 0, 650)) . ' ' . self::words(250, 3);

        // (1) Bài tốt: qua hết luật.
        $make('1 bài tốt', 'needs_review', [$good]);
        // (2) Bài ngắn + thiếu từ khóa + meta sai.
        $make('2 bài ngắn thiếu từ khóa', 'needs_review', [[
            'html_content' => '<p>' . self::words(120, 4) . '</p>', 'seo_title' => str_repeat('Tiêu đề SEO quá dài ', 5),
            'meta_description' => 'Mô tả quá ngắn.', 'focus_keyword' => self::KW, 'review_status' => 'needs_review',
        ]]);
        // (3) Bài trùng ~60% với một bài đã đăng cùng website.
        $make('3a bài gốc đã đăng', 'published', [['review_status' => 'approved'] + self::attrs('<h2>Tổng quan về ' . self::KW . '</h2><p>' . self::KW . ' ' . $base . '</p><h2>Điều kiện</h2><p>' . self::words(5, 9) . '</p>')]);
        $make('3b bài trùng bài gốc', 'needs_review', [self::attrs('<h2>Tổng quan về ' . self::KW . '</h2><p>' . self::KW . ' ' . $copied . '</p><h2>Điều kiện</h2><p>' . self::words(5, 10) . '</p>')]);
        // (4) Đổi số liệu so với bản đã duyệt mà không có nguồn.
        $para = fn (string $n) => "<p>Học bổng toàn phần trị giá {$n} triệu đồng mỗi năm cho sinh viên đạt điều kiện xét tuyển.</p>";
        $make('4 đổi số liệu không nguồn', 'needs_review', [
            ['review_status' => 'approved', 'sources_cited' => ['https://mau.example.test/quyet-dinh-hoc-bong']] + self::attrs(self::html(850, 50, $para('30'))),
            ['review_status' => 'needs_review', 'sources_cited' => ['https://mau.example.test/quyet-dinh-hoc-bong']] + self::attrs(self::html(850, 50, $para('45'))),
        ]);
        // (5) Nội dung nguy hiểm.
        $make('5 chứa script', 'needs_review', [self::attrs(self::html(900, 40, '<script>alert("mẫu")</script><img src="x" onerror="alert(1)">'))]);

        return $out;
    }

    /** Xóa đúng các bản ghi mẫu (bài có từ khóa bắt đầu '[Mẫu] ' và website mẫu do seed tạo). */
    public static function purge(): void
    {
        self::guard();
        $ids = Article::where('primary_keyword', 'like', self::PREFIX . '%')->pluck('id');
        ArticleVersion::whereIn('article_id', $ids)->delete();
        Article::whereIn('id', $ids)->delete();
        Website::where('name', 'like', self::PREFIX . '%')->doesntHave('articles')->delete();
    }

    private static function guard(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Dữ liệu mẫu chỉ được chạy ở môi trường local hoặc testing.');
        }
    }

    private static function attrs(string $html): array
    {
        return [
            'html_content' => $html, 'focus_keyword' => self::KW, 'seo_title' => self::KW . ': điều kiện và hồ sơ',
            'meta_description' => 'Hướng dẫn học bổng toàn phần cho sinh viên: điều kiện xét tuyển, hồ sơ cần chuẩn bị và thời gian đăng ký chi tiết, dễ hiểu, cập nhật.',
            'review_status' => 'needs_review',
        ];
    }

    private static function html(int $n, int $seed, string $extra = ''): string
    {
        $per = intdiv($n - 40, 3);

        return '<h2>Tổng quan về ' . self::KW . '</h2><p>Bài viết này giải thích ' . self::KW . ' cho sinh viên. ' . self::words($per, $seed) . '</p>' . $extra
            . '<h2>Điều kiện xét tuyển</h2><p>' . self::words($per, $seed + 1) . '</p><h2>Hồ sơ và thời gian</h2><p>' . self::words($per, $seed + 2) . '</p>';
    }

    private static function words(int $n, int $seed): string
    {
        $x = $seed * 7919 + 13;
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $x = ($x * 1103515245 + 12345) & 0x7fffffff;
            $out[] = self::LEX[($x >> 8) % count(self::LEX)];
        }

        return implode(' ', $out);
    }
}
