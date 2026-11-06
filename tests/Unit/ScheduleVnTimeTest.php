<?php

namespace Tests\Unit;

use App\Filament\Support\Schedule\ErrorMasker;
use App\Filament\Support\Schedule\VnTime;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ScheduleVnTimeTest extends TestCase
{
    public function test_gio_vn_sang_utc_ca_bien(): void
    {
        $this->assertSame('2026-11-05 16:30:00', VnTime::fromVn('2026-11-05 23:30:00')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-05 17:30:00', VnTime::fromVn('2026-11-06 00:30:00')->format('Y-m-d H:i:s'));
    }

    public function test_hom_nay_theo_gio_vn(): void
    {
        $now = CarbonImmutable::parse('2026-11-05 17:30:00', 'UTC'); // = 00:30 ngày 6 giờ VN
        [$s, $e] = VnTime::todayRangeUtc($now);
        $this->assertSame('2026-11-05 17:00:00', $s);
        $this->assertSame('2026-11-06 17:00:00', $e);
        // 23:30 VN ngày 5 (16:30 UTC) KHÔNG thuộc hôm nay (ngày 6) ; 00:30 VN ngày 6 (17:30 UTC) thuộc.
        $this->assertTrue('2026-11-05 16:30:00' < $s);
        $this->assertTrue('2026-11-05 17:30:00' >= $s && '2026-11-05 17:30:00' < $e);
    }

    public function test_dinh_dang_va_tuan(): void
    {
        $this->assertSame('06/11/2026 00:30', VnTime::format('2026-11-05 17:30:00'));
        $this->assertSame('2026-11-02', VnTime::weekStart(CarbonImmutable::parse('2026-11-08 12:00', 'Asia/Ho_Chi_Minh'))->format('Y-m-d'));
        $this->assertSame('—', VnTime::format(null));
    }

    public function test_che_chuoi_giong_token(): void
    {
        $m = ErrorMasker::mask('401 https://x.vn/api?access_token=ABC123 Authorization: Bearer abcDEF123456 password=hunter2 key abcdefghij0123456789abcdefgh');
        foreach (['ABC123', 'abcDEF123456', 'hunter2', 'abcdefghij0123456789abcdefgh'] as $leak) {
            $this->assertStringNotContainsString($leak, $m);
        }
        $this->assertSame('Timeout 28', ErrorMasker::mask('Timeout 28'));
    }
}
