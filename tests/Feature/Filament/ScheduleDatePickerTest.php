<?php

namespace Tests\Feature\Filament;

use App\Filament\Support\Review\ReviewActions;
use Filament\Forms\Components\DateTimePicker;
use Tests\TestCase;

/** Bộ chọn ngày giờ xếp lịch: định dạng Việt, mặc định 9 giờ sáng mai, phút bất kỳ, tuần bắt đầu thứ Hai. */
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
        $this->assertSame(1, $p->getMinutesStep());
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} 09:00:00$/', (string) $p->getDefaultState());
        $this->assertSame(now('Asia/Ho_Chi_Minh')->addDay()->toDateString(), substr((string) $p->getDefaultState(), 0, 10));
        $this->assertSame(now('Asia/Ho_Chi_Minh')->startOfDay()->toDateString(), substr((string) $p->getMinDate(), 0, 10));
    }

    /** Lỗi gốc: step=5 khiến phút lẻ (14:28) bị trình duyệt coi là stepMismatch và chặn gửi. Không kiểm được hành vi trình duyệt ở đây, chỉ kiểm thuộc tính. */
    public function test_phut_le_khong_bi_rang_buoc_buoc_nhay_o_ca_xep_lich_va_doi_lich(): void
    {
        $this->assertSame(1, $this->picker()->getMinutesStep());

        $re = null;
        foreach (\App\Filament\Support\Schedule\RescheduleAction::form() as $c) {
            if ($c instanceof DateTimePicker) {
                $re = $c;
            }
        }
        $this->assertNotNull($re);
        $this->assertSame(1, $re->getMinutesStep());

        // 14:28 chia 5 dư 3; với bước 1 mọi phút 0-59 đều hợp lệ.
        $this->assertNotSame(0, 28 % 5);
        $this->assertSame(0, 28 % $this->picker()->getMinutesStep());
    }
}
