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
        $this->assertStringContainsString('<sup class="autoseo-cite" data-kb="'.$a->id.'">[1]</sup>', $out);
        $this->assertStringContainsString('data-kb="'.$b->id.','.$a->id.'">[1, 2]</sup>', $out);
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
}
