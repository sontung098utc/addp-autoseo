<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/** Khối tab được dựng lại khi tab mặc định/khóa nhớ đổi (phiên chạy xong), để các khung nội dung không kẹt ở phạm vi Alpine cũ. */
class StudioTabsRebuildTest extends TestCase
{
    private function render(string $default, ?string $persist): string
    {
        return Blade::render(
            '<x-studio.tabs :default="$d" :persist="$p" :tabs="$t"><x-studio.panel key="a">A</x-studio.panel><x-studio.panel key="b">B</x-studio.panel></x-studio.tabs>',
            ['d' => $default, 'p' => $persist, 't' => [['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B']]],
        );
    }

    public function test_doi_tab_mac_dinh_thi_khoa_dung_lai_doi_theo(): void
    {
        $before = $this->render('overview', null);
        $after = $this->render('content', 'bai-viet-v4-1');
        preg_match('/wire:key="([^"]+)"/', $before, $k1);
        preg_match('/wire:key="([^"]+)"/', $after, $k2);
        $this->assertNotEmpty($k1[1] ?? null);
        $this->assertNotSame($k1[1], $k2[1], 'Đổi tab mặc định/khóa nhớ phải đổi wire:key để Livewire dựng lại khối tab.');
        $this->assertSame($k1[1], preg_replace('/x/', 'x', $k1[1]));
        preg_match('/wire:key="([^"]+)"/', $this->render('overview', null), $k3);
        $this->assertSame($k1[1], $k3[1], 'Cùng trạng thái thì khóa ổn định (không dựng lại mỗi lần poll).');
        $this->assertStringContainsString("studioTabs('content', 'bai-viet-v4-1')", $after);
    }
}
