<?php

namespace Tests\Feature\Agent;

use App\Services\Agent\Run\BackgroundRun;
use Tests\TestCase;

class BackgroundRunTest extends TestCase
{
    public function test_chay_nen_chi_khi_bat_cau_hinh_va_csdl_dung_chung_duoc(): void
    {
        // Test dùng cấu hình tắt chạy nền (TestCase) để nút Giao đội AI chạy đồng bộ.
        $this->assertFalse(BackgroundRun::available());

        // Bật cấu hình: khả dụng đúng khi CSDL chia sẻ được với tiến trình con (SQLite :memory: thì không, MariaDB thì có).
        config(['agent.background_runs' => true]);
        $this->assertSame(\App\Services\Images\ImageJob::dbShareable(), BackgroundRun::available());

        config(['agent.background_runs' => false]);
        $this->assertFalse(BackgroundRun::available());
    }
}
