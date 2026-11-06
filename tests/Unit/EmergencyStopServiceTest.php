<?php

namespace Tests\Unit;

use App\Filament\Support\Audit\AuditAction;
use App\Filament\Support\Audit\AuditRecorder;
use App\Models\User;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Tests\TestCase;

class EmergencyStopServiceTest extends TestCase
{
    use RefreshDatabase;

    protected EmergencyStopService $service;
    protected User $admin;
    protected User $approver;
    protected User $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(EmergencyStopService::class);
        $this->service->resetForTesting();

        $this->admin = User::create([
            'name' => 'Admin Test',
            'email' => 'admin_stop@test.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->approver = User::create([
            'name' => 'Approver Test',
            'email' => 'approver_stop@test.com',
            'password' => bcrypt('password'),
            'role' => 'approver',
            'is_active' => true,
        ]);

        $this->writer = User::create([
            'name' => 'Writer Test',
            'email' => 'writer_stop@test.com',
            'password' => bcrypt('password'),
            'role' => 'writer',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        $this->service->resetForTesting();
        parent::tearDown();
    }

    public function test_global_emergency_stop_and_resume(): void
    {
        $this->assertFalse($this->service->isStopped());
        $this->assertNull($this->service->getStopInfo());

        // Kích hoạt dừng khẩn cấp
        $info = $this->service->stop($this->approver, 'Sự cố bảo trì hệ thống CMS');

        $this->assertTrue($this->service->isStopped());
        $this->assertTrue($this->service->isStopped(1)); // Dừng toàn hệ thống thì mọi website đều dừng
        $this->assertEquals('global', $info['scope']);
        $this->assertEquals('Sự cố bảo trì hệ thống CMS', $info['reason']);
        $this->assertEquals($this->approver->id, $info['stopped_by_id']);

        // Khôi phục
        $this->service->resume($this->approver, 'Đã sửa xong lỗi CMS');
        $this->assertFalse($this->service->isStopped());
        $this->assertNull($this->service->getStopInfo());
    }

    public function test_website_specific_emergency_stop_and_resume(): void
    {
        $site1Id = 10;
        $site2Id = 20;

        // Dừng site 10
        $this->service->stop($this->admin, 'Website 10 bị nghẽn mạng', $site1Id);

        $this->assertFalse($this->service->isStopped()); // Không dừng toàn hệ thống
        $this->assertTrue($this->service->isStopped($site1Id));
        $this->assertFalse($this->service->isStopped($site2Id));

        $info = $this->service->getStopInfo($site1Id);
        $this->assertNotNull($info);
        $this->assertEquals('site', $info['scope']);
        $this->assertEquals($site1Id, $info['website_id']);

        // Khôi phục site 10
        $this->service->resume($this->admin, 'Đã khôi phục site 10', $site1Id);
        $this->assertFalse($this->service->isStopped($site1Id));
    }

    public function test_writer_cannot_trigger_or_resume_emergency_stop(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Chỉ Trưởng nhóm duyệt bài hoặc Quản trị viên');

        $this->service->stop($this->writer, 'Writer thử dừng khẩn cấp');
    }

    public function test_writer_cannot_resume_emergency_stop(): void
    {
        $this->service->stop($this->approver, 'Dừng hợp lệ');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Chỉ Trưởng nhóm duyệt bài hoặc Quản trị viên');

        $this->service->resume($this->writer);
    }

    public function test_inactive_user_cannot_stop(): void
    {
        $this->approver->update(['is_active' => false]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tài khoản đã bị vô hiệu hóa');

        $this->service->stop($this->approver, 'Dừng khẩn cấp');
    }

    public function test_empty_reason_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Bắt buộc phải nhập lý do dừng khẩn cấp');

        $this->service->stop($this->approver, '   ');
    }
}
