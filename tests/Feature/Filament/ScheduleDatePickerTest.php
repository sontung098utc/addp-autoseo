<?php

namespace Tests\Feature\Filament;

use App\Filament\Support\Review\ReviewActions;
use Filament\Forms\Components\DateTimePicker;
use Tests\TestCase;

/** Bộ chọn ngày giờ xếp lịch: định dạng Việt, mặc định 9 giờ sáng mai, bước 5 phút, tuần bắt đầu thứ Hai. */
class ScheduleDatePickerTest extends TestCase
{
    private function picker(): DateTimePicker
    {
        foreach (ReviewActions::scheduleForm() as $c) {
            if ($c instanceof DateTimePicker) {
                return $c;
            }
        }
        $this->fail('Không thấy ô chọn ngày giờ');
    }

    public function test_dinh_dang_viet_va_mac_dinh_9_gio_sang_mai(): void
    {
        $p = $this->picker();

        $this->assertSame('d/m/Y H:i', $p->getDisplayFormat());
        $this->assertSame(1, $p->getFirstDayOfWeek());
        $this->assertSame(5, $p->getMinutesStep());
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} 09:00:00$/', (string) $p->getDefaultState());
        $this->assertSame(now('Asia/Ho_Chi_Minh')->addDay()->toDateString(), substr((string) $p->getDefaultState(), 0, 10));
        $this->assertSame(now('Asia/Ho_Chi_Minh')->startOfDay()->toDateString(), substr((string) $p->getMinDate(), 0, 10));
    }
}
