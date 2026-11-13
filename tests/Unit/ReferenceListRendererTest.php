<?php

namespace Tests\Unit;

use App\Services\Content\ReferenceListRenderer;
use PHPUnit\Framework\TestCase;

class ReferenceListRendererTest extends TestCase
{
    public function test_them_khoi_nguon_chi_voi_lien_ket_http_that(): void
    {
        $out = ReferenceListRenderer::append('<p>a</p>', [
            ['title' => 'Hướng dẫn', 'domain' => 'who.int', 'url' => 'https://www.who.int/x?a=1&b=2'],
            ['title' => 'Xấu', 'domain' => 'e.com', 'url' => 'javascript:alert(1)'],
        ]);
        $this->assertStringContainsString('Nguồn tham khảo', $out);
        $this->assertStringContainsString('href="https://www.who.int/x?a=1&amp;b=2"', $out);
        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertSame('<p>a</p>', ReferenceListRenderer::append('<p>a</p>', []));
    }

    public function test_noi_tiep_vao_khoi_nguon_co_san(): void
    {
        $html = '<p>a</p><div class="autoseo-refs"><p><strong>Nguồn tham khảo</strong></p><ol><li>Cũ</li></ol></div>';
        $out = ReferenceListRenderer::append($html, [['title' => 'Mới', 'domain' => 'nhs.uk', 'url' => 'https://nhs.uk/y']]);
        $this->assertSame(1, substr_count($out, 'autoseo-refs'));
        $this->assertStringContainsString('<li>Cũ</li><li><a href="https://nhs.uk/y"', $out);
    }
}
