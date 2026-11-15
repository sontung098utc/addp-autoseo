<?php

namespace Tests\Unit;

use App\Services\Content\CitationRenderer;
use PHPUnit\Framework\TestCase;

/** Dấu pha trộn kho + web trong một ngoặc không được lọt nguyên văn vào bài giao. */
class CitationMixedMarkerTest extends TestCase
{
    public function test_tach_dau_pha_tron_kho_va_web(): void
    {
        $this->assertSame('<p>Liều 10 mg/kg.[KB#976][T2]</p>', CitationRenderer::splitMixed('<p>Liều 10 mg/kg.[KB#976, T2]</p>'));
        $this->assertSame('<p>x[KB#1][T3][KB#5]</p>', CitationRenderer::splitMixed('<p>x[KB#1, T3, KB#5]</p>'));
    }

    public function test_nhom_thuan_mot_loai_va_van_ban_thuong_giu_nguyen(): void
    {
        $this->assertSame('<p>a[T1, T2] b[KB#1, 2] c[a]</p>', CitationRenderer::splitMixed('<p>a[T1, T2] b[KB#1, 2] c[a]</p>'));
    }
}
