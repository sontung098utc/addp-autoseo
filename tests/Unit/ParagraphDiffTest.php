<?php

namespace Tests\Unit;

use App\Filament\Support\Diff\ParagraphDiff;
use PHPUnit\Framework\TestCase;

class ParagraphDiffTest extends TestCase
{
    private function types(array $diff): array
    {
        return array_column($diff, 'type');
    }

    public function test_giong_het_nhau_toan_equal(): void
    {
        $html = '<h2>Tiêu đề</h2><p>Đoạn một.</p><p>Đoạn hai.</p>';
        $diff = ParagraphDiff::compare($html, $html);
        $this->assertSame(['equal', 'equal', 'equal'], $this->types($diff));
        $this->assertSame('h2', $diff[0]['tag']);
    }

    public function test_them_doan(): void
    {
        $diff = ParagraphDiff::compare('<p>Một</p><p>Ba</p>', '<p>Một</p><p>Hai hoàn toàn khác</p><p>Ba</p>');
        $this->assertSame(['equal', 'added', 'equal'], $this->types($diff));
        $this->assertNull($diff[1]['old']);
        $this->assertSame('Hai hoàn toàn khác', $diff[1]['new']);
    }

    public function test_xoa_doan(): void
    {
        $diff = ParagraphDiff::compare('<p>Một</p><p>Hai hoàn toàn khác</p><p>Ba</p>', '<p>Một</p><p>Ba</p>');
        $this->assertSame(['equal', 'removed', 'equal'], $this->types($diff));
        $this->assertNull($diff[1]['new']);
    }

    public function test_sua_doan_thanh_changed(): void
    {
        $diff = ParagraphDiff::compare(
            '<p>Khóa học tiếng Anh dành cho người đi làm.</p>',
            '<p>Khóa học tiếng Anh dành cho học sinh cấp ba.</p>'
        );
        $this->assertSame(['changed'], $this->types($diff));
        $html = ParagraphDiff::renderHtml($diff);
        $this->assertStringContainsString('<del', $html);
        $this->assertStringContainsString('<ins', $html);
    }

    public function test_doi_so_co_dau_cham_phay_phan_tram(): void
    {
        $diff = ParagraphDiff::compare(
            '<p>Học phí 12.000.000 đồng, giảm 10% đến 30/11, tăng 12,5 lần.</p>',
            '<p>Học phí 15.000.000 đồng, giảm 15% đến 30/12, tăng 12,5 lần.</p>'
        );
        $this->assertSame('changed', $diff[0]['type']);
        $pairs = array_map(fn ($c) => [$c['old'], $c['new']], $diff[0]['numberChanges']);
        $this->assertContains(['12.000.000', '15.000.000'], $pairs);
        $this->assertContains(['10%', '15%'], $pairs);
        $this->assertContains(['30/11', '30/12'], $pairs);
        $this->assertCount(3, $pairs); // 12,5 không đổi
    }

    public function test_doi_so_kem_don_vi_va_nam(): void
    {
        $diff = ParagraphDiff::compare('<p>Học phí 5 triệu năm 2026.</p>', '<p>Học phí 7 triệu năm 2027.</p>');
        $pairs = array_map(fn ($c) => [$c['old'], $c['new']], $diff[0]['numberChanges']);
        $this->assertContains(['5 triệu', '7 triệu'], $pairs);
        $this->assertContains(['2026', '2027'], $pairs);
    }

    public function test_khong_doi_so_thi_numberChanges_rong(): void
    {
        $diff = ParagraphDiff::compare('<p>Giá 100 đồng cho khách cũ</p>', '<p>Giá 100 đồng cho khách mới</p>');
        $this->assertSame('changed', $diff[0]['type']);
        $this->assertSame([], $diff[0]['numberChanges']);
    }

    public function test_doi_heading(): void
    {
        $diff = ParagraphDiff::compare('<h2>Lịch khai giảng 2026</h2><p>Nội dung</p>', '<h2>Lịch khai giảng 2027</h2><p>Nội dung</p>');
        $this->assertSame(['changed', 'equal'], $this->types($diff));
        $this->assertSame('h2', $diff[0]['tag']);
    }

    public function test_tieng_viet_co_dau_khong_mat_dau(): void
    {
        $diff = ParagraphDiff::compare('<p>Đăng ký ngay để nhận ưu đãi</p>', '<p>Đăng ký ngay để nhận ưu đãi</p>');
        $this->assertSame('Đăng ký ngay để nhận ưu đãi', $diff[0]['new']);
        $this->assertStringContainsString('Đăng ký', ParagraphDiff::renderHtml($diff));
    }

    public function test_rong_va_mot_ben_rong(): void
    {
        $this->assertSame([], ParagraphDiff::compare('', ''));
        $this->assertSame(['added', 'added'], $this->types(ParagraphDiff::compare('', '<p>A</p><p>B</p>')));
        $this->assertSame(['removed'], $this->types(ParagraphDiff::compare('<p>A</p>', '')));
        $this->assertSame('', ParagraphDiff::renderHtml([]));
    }

    public function test_html_hong_khong_nem_exception(): void
    {
        $diff = ParagraphDiff::compare('<p>Chưa đóng <b>đậm<p>Đoạn hai', '<div><p>Đoạn hai</p>');
        $this->assertIsArray($diff);
        $this->assertNotEmpty($diff);
        $this->assertIsString(ParagraphDiff::renderHtml($diff));
    }

    public function test_xss_bi_escape(): void
    {
        $bad = '<script>alert(1)</script><img src=x onerror=alert(2)><p>"><b>x</b> <script>alert(3)</script></p>';
        $diff = ParagraphDiff::compare($bad, '<p>Khác &lt;img src=x onerror=alert(4)&gt; "><b>y</b></p>' . $bad);
        $html = ParagraphDiff::renderHtml($diff);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertDoesNotMatchRegularExpression('/<[^>]*\son\w+\s*=/i', $html);
    }

    public function test_dao_thu_tu_doan(): void
    {
        $diff = ParagraphDiff::compare('<p>Alpha đầu tiên</p><p>Beta thứ hai</p>', '<p>Beta thứ hai</p><p>Alpha đầu tiên</p>');
        $types = $this->types($diff);
        $this->assertContains('removed', $types);
        $this->assertContains('added', $types);
        $this->assertNotContains('changed', $types);
    }
}
