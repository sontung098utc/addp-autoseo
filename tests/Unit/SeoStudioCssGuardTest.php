<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Chốt chặn cho lỗi "ô nhập mất viền": Filament vẽ vòng viền ô nhập bằng box-shadow, nên CSS nhận diện
 * không được đặt box-shadow lên .fi-input-wrp; viền điều khiển phải dùng border-strong.
 */
class SeoStudioCssGuardTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/public/css/seo-studio.css');
    }

    public function test_khong_ghi_de_box_shadow_len_o_nhap(): void
    {
        $css = $this->css();
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
        foreach ($rules as [, $selector, $body]) {
            if (str_contains($selector, '.fi-input-wrp') && str_contains($body, 'box-shadow')) {
                $this->fail("Quy tắc '{$selector}' đặt box-shadow lên .fi-input-wrp, sẽ xóa viền ô nhập.");
            }
        }
        $this->assertTrue(true);
    }

    public function test_vien_o_nhap_dung_border_strong(): void
    {
        $this->assertMatchesRegularExpression('/\.fi-input-wrp\s*\{[^}]*--tw-ring-color:\s*var\(--ss-border-strong\)/', $this->css());
    }
}
