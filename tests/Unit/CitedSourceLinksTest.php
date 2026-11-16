<?php

namespace Tests\Unit;

use App\Services\Content\CitedSourceLinks;
use PHPUnit\Framework\TestCase;

class CitedSourceLinksTest extends TestCase
{
    public function test_gan_lien_ket_that_khi_khop_va_de_trong_khi_khong_khop(): void
    {
        $cands = [
            ['title' => 'Low blood pressure (hypotension) - Mayo Clinic', 'url' => 'https://www.mayoclinic.org/diseases-conditions/low-blood-pressure/symptoms-causes/syc-20355465', 'domain' => 'mayoclinic.org'],
            ['title' => 'Huyết áp thấp - Bộ Y tế', 'url' => 'https://moh.gov.vn/hap', 'domain' => 'moh.gov.vn'],
            ['title' => 'Bad', 'url' => 'javascript:alert(1)'],
        ];
        $r = CitedSourceLinks::resolve(['Mayo Clinic', 'Hướng dẫn huyết áp thấp của Bộ Y tế', 'Hiệp hội ABC không có thật', ''], $cands);
        $this->assertCount(3, $r);
        $this->assertSame('https://www.mayoclinic.org/diseases-conditions/low-blood-pressure/symptoms-causes/syc-20355465', $r[0]['url']);
        $this->assertSame('https://moh.gov.vn/hap', $r[1]['url']);
        $this->assertNull($r[2]['url'], 'Không khớp thì không bịa liên kết.');
    }
}
