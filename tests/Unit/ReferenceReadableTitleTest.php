<?php

namespace Tests\Unit;

use App\Services\Content\ReferenceListRenderer;
use PHPUnit\Framework\TestCase;

/** Tiêu đề nguồn người đọc thấy phải đọc được: không tên đường dẫn, mảnh bảng hay trùng tên miền. */
class ReferenceReadableTitleTest extends TestCase
{
    public function test_tieu_de_hop_le_duoc_giu(): void
    {
        $this->assertSame('Ibuprofen for children: medicine for pain and high temperature - NHS', ReferenceListRenderer::readableTitle('Ibuprofen for children: medicine for pain and high temperature - NHS', 'nhs.uk'));
        $this->assertSame('Liều dùng paracetamol cho trẻ em', ReferenceListRenderer::readableTitle('Liều dùng paracetamol cho trẻ em', 'vinmec.com'));
    }

    public function test_ten_duong_dan_manh_bang_va_trung_ten_mien_bi_bo(): void
    {
        $this->assertSame('', ReferenceListRenderer::readableTitle('bi quyet pha ca phe phin ngon dam da thu thiet 03383', 'dienmayxanh.com'));
        $this->assertSame('', ReferenceListRenderer::readableTitle('Stage | Number Tested | Acceptance Criteria |', 'drugfuture.com'));
        $this->assertSame('', ReferenceListRenderer::readableTitle('dissolutiontech.com', 'dissolutiontech.com'));
        $this->assertSame('', ReferenceListRenderer::readableTitle('cach pha ca phe phin dung cach thom ngon dam da', 'viettelstore.vn'));
        $this->assertSame('', ReferenceListRenderer::readableTitle('', 'x.vn'));
        $this->assertSame("What's the Right Grind for Vietnamese Coffee?", ReferenceListRenderer::readableTitle('What&#039;s the Right Grind for Vietnamese Coffee?', 'cafely.com'));
    }

    public function test_nhan_dien_tieu_de_bi_cat(): void
    {
        $this->assertTrue(ReferenceListRenderer::looksTruncated('INTERNATIONAL CONFERENCE ON HARMONISATION OF TECHNICAL'));
        $this->assertTrue(ReferenceListRenderer::looksTruncated('Dissolution Testing and Acceptance Criteria for Immediate Release of'));
        $this->assertTrue(ReferenceListRenderer::looksTruncated('Guidance for Industry Dissolution Testing and Immediate Release for I'));
        $this->assertFalse(ReferenceListRenderer::looksTruncated('Ibuprofen for children: medicine for pain and high temperature - NHS'));
        $this->assertFalse(ReferenceListRenderer::looksTruncated('Liều dùng paracetamol cho trẻ em'));
        $this->assertFalse(ReferenceListRenderer::looksTruncated('Dissolution testing'));
    }
}
