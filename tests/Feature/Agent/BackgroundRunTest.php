<?php

namespace Tests\Feature\Agent;

use App\Services\Agent\Run\BackgroundRun;
use Tests\TestCase;

class BackgroundRunTest extends TestCase
{
    public function test_khong_chay_nen_khi_csdl_khong_dung_chung_duoc_va_co_the_tat_bang_cau_hinh(): void
    {
        // Test dùng SQLite :memory: (không chia sẻ được với tiến trình con): nút Giao đội AI vẫn chạy đồng bộ, không chuyển hướng.
        $this->assertFalse(BackgroundRun::available());
        config(['agent.background_runs' => false]);
        $this->assertFalse(BackgroundRun::available());
    }
}
