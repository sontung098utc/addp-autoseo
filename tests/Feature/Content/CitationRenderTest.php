<?php

namespace Tests\Feature\Content;

use App\Models\Brain;
use App\Models\KnowledgeItem;
use App\Services\Content\CitationRenderer;
use App\Services\Content\HtmlSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CitationRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_numbered_footnotes_and_reference_list_and_keeps_unknown_markers(): void
    {
        $brain = Brain::create(['name' => 'Y', 'slug' => 'y', 'domain_key' => 'y', 'language' => 'vi', 'status' => 'active', 'description' => '']);
        $a = KnowledgeItem::create(['brain_id' => $brain->id, 'type' => 'fact', 'title' => 'HbA1c mục tiêu', 'body' => 'x', 'status' => 'approved']);
        $b = KnowledgeItem::create(['brain_id' => $brain->id, 'type' => 'fact', 'title' => 'Đường huyết đói', 'body' => 'y', 'status' => 'approved']);

        $html = "<p>HbA1c nên dưới 7% [KB#{$a->id}].</p><p>Đường huyết đói [KB#{$b->id}, KB#{$a->id}] và lạ [KB#999999].</p>";
        $out = CitationRenderer::render($html);

        $this->assertStringNotContainsString("[KB#{$a->id}]", $out);
        $this->assertStringContainsString('<sup class="autoseo-cite" data-kb="'.$a->id.'" title="Nguồn: HbA1c mục tiêu"><a href="#nguon-1">[1]</a></sup>', $out, 'rê chuột chỉ cho biết nguồn, không nhắc lại nội dung mục');
        $this->assertStringContainsString('data-kb="'.$b->id.'" title="Nguồn: Đường huyết đói"><a href="#nguon-2">[2]</a></sup><sup class="autoseo-cite" data-kb="'.$a->id.'"', $out, 'nhóm [KB#b, KB#a] tách thành từng chú thích riêng');
        $this->assertStringContainsString('<li id="nguon-1"', $out);
        $this->assertStringContainsString('[KB#999999]', $out);
        $this->assertStringContainsString('Nguồn tham khảo', $out);
        $this->assertStringContainsString('HbA1c mục tiêu', $out);

        $kept = HtmlSanitizer::clean($out);
        $this->assertStringContainsString('autoseo-cite', $kept);
        $this->assertStringContainsString('<ol>', $kept);
    }

    public function test_html_without_markers_is_unchanged(): void
    {
        $this->assertSame('<p>abc</p>', CitationRenderer::render('<p>abc</p>'));
    }

    public function test_sanitizer_allows_layout_blocks_but_not_script(): void
    {
        $c = HtmlSanitizer::clean('<div class="autoseo-summary"><strong>x</strong></div><aside class="autoseo-callout"><p>y</p></aside><details><summary>q</summary><p>a</p></details><script>alert(1)</script>');
        $this->assertStringContainsString('<aside', $c);
        $this->assertStringContainsString('<details>', $c);
        $this->assertStringNotContainsString('script', $c);
    }

    public function test_ghi_chu_that_dinh_nghia_khai_niem_khac_voi_chu_thich_nguon(): void
    {
        $html = '<p>Chỉ số HbA1c[^a] phản ánh đường huyết trung bình. Đây là dấu lạ[^z] và dấu lặp[^a].</p>';
        $notes = [['key' => 'a', 'text' => 'HbA1c (hemoglobin A1c) là tỉ lệ huyết sắc tố gắn glucose trong hồng cầu.'], ['key' => 'b', 'text' => 'Ghi chú không có dấu trỏ tới nên phải bị bỏ.'], ['key' => 'c', 'text' => 'ngắn']];
        $out = \App\Services\Content\NoteRenderer::apply($html, $notes);

        $this->assertSame(1, substr_count($out, 'data-note="a"'), 'mỗi dấu một lần, dấu lặp bị bỏ');
        $this->assertStringNotContainsString('[^', $out, 'không còn dấu thô nào, kể cả dấu không có ghi chú');
        $this->assertStringContainsString('<aside class="autoseo-notes">', $out);
        $this->assertStringContainsString('<li id="ghichu-a">HbA1c (hemoglobin A1c)', $out);
        $this->assertStringNotContainsString('không có dấu trỏ tới', $out, 'ghi chú mồ côi bị bỏ');
        $this->assertStringContainsString('title="HbA1c (hemoglobin A1c) là tỉ lệ', $out);
        $this->assertSame('<p>abc</p>', \App\Services\Content\NoteRenderer::apply('<p>abc</p>', $notes));
        // Văn bản ghi chú được escape, không nhận HTML.
        $x = \App\Services\Content\NoteRenderer::apply('<p>x[^a]</p>', [['key' => 'a', 'text' => '<script>alert(1)</script> định nghĩa dài đủ mười hai ký tự']]);
        $this->assertStringNotContainsString('<script>', $x);
    }
}
