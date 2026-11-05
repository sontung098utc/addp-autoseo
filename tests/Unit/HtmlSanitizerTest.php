<?php

namespace Tests\Unit;

use App\Services\Content\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

class HtmlSanitizerTest extends TestCase
{
    public function test_it_removes_malicious_script_tags(): void
    {
        $dirty = '<h2>Tiêu đề bài viết</h2><p>Đoạn văn.</p><script>alert("XSS Attack!");</script>';
        $clean = HtmlSanitizer::clean($dirty);

        $this->assertStringNotContainsString('<script>', $clean);
        $this->assertStringNotContainsString('alert', $clean);
        $this->assertStringContainsString('<h2>Tiêu đề bài viết</h2>', $clean);
    }

    public function test_it_removes_inline_event_handlers(): void
    {
        $dirty = '<p onmouseover="badCode()" onclick="stealCookie()">Nội dung bài viết</p><img src="pic.jpg" onerror="evil()"/>';
        $clean = HtmlSanitizer::clean($dirty);

        $this->assertStringNotContainsString('onmouseover', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('onerror', $clean);
    }

    public function test_it_removes_javascript_pseudo_protocols(): void
    {
        $dirty = '<a href="javascript:alert(1)">Click me</a><a href="https://example.com">An toàn</a>';
        $clean = HtmlSanitizer::clean($dirty);

        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringContainsString('href="https://example.com"', $clean);
    }

    public function test_it_preserves_valid_semantic_html(): void
    {
        $valid = '<h2>Đề mục H2</h2><p>Nội dung đoạn văn với <strong>chữ đậm</strong> và <em>chữ nghiêng</em>.</p><ul><li>Ý 1</li><li>Ý 2</li></ul>';
        $clean = HtmlSanitizer::clean($valid);

        $this->assertEquals($valid, $clean);
    }
}
