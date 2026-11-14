<?php

namespace Tests\Unit;

use App\Services\Content\CitationOrder;
use PHPUnit\Framework\TestCase;

class CitationOrderTest extends TestCase
{
    private function sup(int $n, string $kb = ''): string
    {
        return '<sup class="autoseo-cite"'.($kb !== '' ? ' data-kb="'.$kb.'"' : '').' title="Nguồn"><a href="#nguon-'.$n.'">['.$n.']</a></sup>';
    }

    public function test_danh_so_lai_theo_thu_tu_xuat_hien_va_sap_lai_danh_sach_nguon(): void
    {
        $html = '<p>A'.$this->sup(2).' B'.$this->sup(1).'</p><p>C'.$this->sup(3).'</p>'
            .'<div class="autoseo-refs"><p><strong>Nguồn tham khảo</strong></p><ol><li id="nguon-1">Một</li><li id="nguon-2">Hai</li><li id="nguon-3">Ba</li><li>Chưa trích</li></ol></div>';
        $out = CitationOrder::normalize($html);
        $this->assertStringContainsString('A<sup class="autoseo-cite" title="Nguồn"><a href="#nguon-1">[1]</a></sup> B<sup class="autoseo-cite" title="Nguồn"><a href="#nguon-2">[2]</a></sup>', $out);
        $this->assertStringContainsString('<li id="nguon-1">Hai</li><li id="nguon-2">Một</li><li id="nguon-3">Ba</li><li>Chưa trích</li>', $out, 'Nguồn thứ hai xuất hiện đầu tiên nên thành số 1.');
    }

    public function test_bot_day_chu_thich_web_lap_trong_mot_doan_nhung_giu_chu_thich_kho(): void
    {
        $html = '<p>A'.$this->sup(4).' B'.$this->sup(4).' C'.$this->sup(4).'</p><p>D'.$this->sup(4).'</p><p>K'.$this->sup(1, '12').' L'.$this->sup(1, '12').'</p>'
            .'<div class="autoseo-refs"><ol><li id="nguon-1">Kho</li><li id="nguon-4">Web</li></ol></div>';
        $out = CitationOrder::normalize($html);
        $this->assertSame(1, substr_count(explode('</p>', $out)[0], 'autoseo-cite'), 'Trong một đoạn chỉ giữ lần đầu của chú thích web.');
        $this->assertSame(2, substr_count($out, '<p>D') + substr_count($out, '<p>A'), 'Đoạn khác vẫn có chú thích riêng.');
        $this->assertSame(2, substr_count($out, 'data-kb="12"'), 'Chú thích kho giữ nguyên cho bộ kiểm trích dẫn.');
        $this->assertSame('', CitationOrder::normalize(''));
        $this->assertSame('<p>x</p>', CitationOrder::normalize('<p>x</p>'));
    }
}
