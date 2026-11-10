<?php

namespace Tests\Feature\Agent\Refresh;

use App\Services\Agent\Refresh\HtmlSections;
use PHPUnit\Framework\TestCase;

class HtmlSectionsTest extends TestCase
{
    private const HTML = "<p>Mở đầu &amp; giới thiệu.</p>\n<H2 class=\"a\" id='x'>Học phí &amp; <em>lệ phí</em></H2>\n<p>Nội dung 1</p>\n<h3>Con</h3><p>Chi tiết</p>\n<h2>\n  Điều kiện\n  tuyển sinh\n</h2>\r\n<div><ul><li>Một</li></ul></div>\n<h2>Kết luận</h2><p>Hết.</p>";

    public function test_split_intro_and_sections(): void
    {
        $r = HtmlSections::split(self::HTML);
        $this->assertSame("<p>Mở đầu &amp; giới thiệu.</p>\n", $r['intro']);
        $this->assertCount(3, $r['sections']);
        $this->assertSame(0, $r['sections'][0]['index']);
        $this->assertSame('Học phí & lệ phí', $r['sections'][0]['heading']);
        $this->assertSame('Điều kiện tuyển sinh', $r['sections'][1]['heading']);
        $this->assertSame('Kết luận', $r['sections'][2]['heading']);
        $this->assertStringContainsString('<h3>Con</h3>', $r['sections'][0]['html']);
    }

    public function test_split_then_join_is_lossless(): void
    {
        $r = HtmlSections::split(self::HTML);
        $this->assertSame(self::HTML, $r['intro'].implode('', array_column($r['sections'], 'html')));
        $this->assertSame(self::HTML, HtmlSections::replace(self::HTML, []));
    }

    public function test_replace_touches_only_target_section(): void
    {
        $r = HtmlSections::split(self::HTML);
        $new = "<h2>Học phí mới</h2>\n<p>Mới</p>\n";
        $out = HtmlSections::replace(self::HTML, [0 => $new]);
        $this->assertSame($r['intro'].$new.$r['sections'][1]['html'].$r['sections'][2]['html'], $out);
        $again = HtmlSections::split($out);
        $this->assertSame($r['sections'][2]['html'], $again['sections'][2]['html']);
        $this->assertSame('Học phí mới', $again['sections'][0]['heading']);
    }

    public function test_replace_ignores_unknown_index(): void
    {
        $this->assertSame(self::HTML, HtmlSections::replace(self::HTML, [9 => '<h2>x</h2>']));
    }

    public function test_no_h2_is_intro_only(): void
    {
        $html = '<p>Chỉ có đoạn</p><h3>Cấp 3</h3>';
        $this->assertSame(['intro' => $html, 'sections' => []], HtmlSections::split($html));
        $this->assertSame($html, HtmlSections::replace($html, [0 => 'x']));
    }

    public function test_h2_in_comment_and_h20_tag_are_not_sections(): void
    {
        $html = '<p>a</p><!-- <h2>ẩn</h2> --><h20>x</h20><h2>Thật</h2><p>b</p>';
        $r = HtmlSections::split($html);
        $this->assertCount(1, $r['sections']);
        $this->assertSame('Thật', $r['sections'][0]['heading']);
        $this->assertSame($html, $r['intro'].$r['sections'][0]['html']);
    }

    public function test_html_starting_with_h2_has_empty_intro(): void
    {
        $r = HtmlSections::split('<h2>A</h2><p>x</p>');
        $this->assertSame('', $r['intro']);
        $this->assertCount(1, $r['sections']);
    }
}
