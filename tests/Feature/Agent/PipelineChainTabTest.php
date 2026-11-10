<?php

namespace Tests\Feature\Agent;

use App\Filament\Clusters\AgentTeam\Pages\PipelineMap;
use App\Models\User;
use App\Services\Agent\Pipeline\JobTypeRegistry;
use App\Services\Agent\Pipeline\PipelineBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** G1: tab Pipeline hiện chuỗi 7 mắt xích với thẻ loại việc, giữ bản đồ 9 giai đoạn. */
class PipelineChainTabTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    public function test_hien_du_7_mat_xich_theo_thu_tu_va_the_viet_bai(): void
    {
        $this->actingAs($this->user('approver'));
        $labels = array_values(JobTypeRegistry::CHAIN);
        $this->assertCount(7, $labels);
        $res = $this->get('/admin/doi-ai/pipeline')->assertOk()->assertSeeInOrder($labels)
            ->assertSee('Viết bài')->assertSee('Ghi:')->assertSee('Hiện:')->assertSee('Đã có mã chạy')
            ->assertSee('Bản đồ 9 giai đoạn (trạng thái bằng chứng)')
            ->assertDontSee('Chạy toàn bộ');
        $this->assertFalse(PipelineBlueprint::controlsWired());
        $this->assertCount(9, PipelineBlueprint::stages());
        $res->assertSee('data-stage="quality"', false);
    }

    public function test_cua_so_truot_loai_viec(): void
    {
        $this->actingAs($this->user('approver'));
        Livewire::test(PipelineMap::class)
            ->call('mountAction', 'job', ['job' => 'article_write'])
            ->assertSee('Lập dàn ý')->assertSee('Chưa chạy')->assertSee('Điểm người can thiệp')->assertSee('content.generate');
    }

    public function test_quyen_truy_cap_nhu_cu(): void
    {
        $this->actingAs($this->user('writer'));
        $this->assertFalse(PipelineMap::canAccess());
        $this->get('/admin/doi-ai/pipeline')->assertForbidden();
        $this->actingAs($this->user('approver'));
        $this->assertTrue(PipelineMap::canAccess());
    }
}
