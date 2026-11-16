<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_403_bang_tieng_viet_co_duong_ve(): void
    {
        $this->actingAs(User::create(['name' => 'a', 'email' => 'a@x.vn', 'password' => 'secret123', 'role' => 'approver', 'is_active' => true]));

        $this->get('/admin/websites')->assertForbidden()->assertSee('Bạn không có quyền vào trang này')->assertSee('Về trang tổng quan')->assertDontSee('FORBIDDEN');
    }

    public function test_404_bang_tieng_viet(): void
    {
        $this->actingAs(User::create(['name' => 'a', 'email' => 'a@x.vn', 'password' => 'secret123', 'role' => 'approver', 'is_active' => true]));

        $this->get('/admin/khong-co-trang-nay')->assertNotFound()->assertSee('Không tìm thấy trang');
    }
}
