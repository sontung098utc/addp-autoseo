<?php

namespace Tests\Feature\Filament;

use App\Providers\UiDefaultsServiceProvider;
use Filament\Notifications\Notification;
use Tests\TestCase;

class NotificationDurationTest extends TestCase
{
    public function test_thong_bao_hien_lau_hon_mac_dinh_6_giay(): void
    {
        $this->assertSame(UiDefaultsServiceProvider::SECONDS * 1000, Notification::make()->title('x')->getDuration());
        $this->assertGreaterThan(6000, Notification::make()->getDuration());
    }
}
