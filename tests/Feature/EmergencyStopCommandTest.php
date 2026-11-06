<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Website;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmergencyStopCommandTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Website $site;
    protected EmergencyStopService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(EmergencyStopService::class);
        $this->service->resetForTesting();

        $this->admin = User::create([
            'name' => 'Admin Command',
            'email' => 'admin_cmd@test.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->site = Website::create([
            'name' => 'Website Test Cmd',
            'cms_type' => 'wordpress',
            'base_url' => 'https://cmd-test.com',
            'encrypted_credentials' => ['username' => 'a', 'application_password' => 'b'],
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        $this->service->resetForTesting();
        parent::tearDown();
    }

    public function test_command_status_when_normal(): void
    {
        $this->artisan('autoseo:emergency-stop', ['--status' => true])
            ->expectsOutputToContain('TRẠNG THÁI: BÌNH THƯỜNG')
            ->assertSuccessful();
    }

    public function test_command_stops_and_resumes_globally(): void
    {
        $this->artisan('autoseo:emergency-stop', [
            '--reason' => 'Sự cố bảo trì định kỳ',
            '--user' => $this->admin->email,
        ])
            ->expectsOutputToContain('Đã KÍCH HOẠT DỪNG KHẨN CẤP')
            ->assertSuccessful();

        $this->assertTrue($this->service->isStopped());

        $this->artisan('autoseo:emergency-stop', ['--status' => true])
            ->expectsOutputToContain('TRẠNG THÁI: ĐANG DỪNG KHẨN CẤP (TOÀN HỆ THỐNG)')
            ->expectsOutputToContain('Sự cố bảo trì định kỳ')
            ->assertSuccessful();

        $this->artisan('autoseo:emergency-stop', [
            '--resume' => true,
            '--user' => $this->admin->email,
        ])
            ->expectsOutputToContain('Đã KHÔI PHỤC thành công')
            ->assertSuccessful();

        $this->assertFalse($this->service->isStopped());
    }

    public function test_command_stops_specific_site(): void
    {
        $this->artisan('autoseo:emergency-stop', [
            '--site' => $this->site->id,
            '--reason' => 'Website bảo trì',
            '--user' => $this->admin->email,
        ])
            ->expectsOutputToContain("Website #{$this->site->id}")
            ->assertSuccessful();

        $this->assertFalse($this->service->isStopped());
        $this->assertTrue($this->service->isStopped($this->site->id));

        $this->artisan('autoseo:emergency-stop', [
            '--site' => $this->site->id,
            '--status' => true,
        ])
            ->expectsOutputToContain("Website #{$this->site->id}")
            ->assertSuccessful();

        $this->artisan('autoseo:emergency-stop', [
            '--site' => $this->site->id,
            '--resume' => true,
            '--user' => $this->admin->email,
        ])
            ->expectsOutputToContain("Website #{$this->site->id}")
            ->assertSuccessful();

        $this->assertFalse($this->service->isStopped($this->site->id));
    }
}
