<?php

namespace Tests\Feature\Agent;

use App\Filament\Clusters\AgentTeam\Pages\AgentRoles;
use App\Models\User;
use App\Services\Agent\Roles\AgentProfiles;
use App\Services\Agent\Roles\ExpertTemplates;
use App\Services\Agent\Roles\RoleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Mẫu hồ sơ chuyên gia cho từng vai trò: vừa giới hạn độ dài, thuộc vai trò có thật, và chỉ điền vào ô (không tự lưu). */
class ExpertTemplatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_moi_mau_vua_gioi_han_va_thuoc_vai_tro_co_that(): void
    {
        $keys = array_column((new RoleRegistry)->all(), 'key');
        foreach (ExpertTemplates::DATA as $role => $t) {
            $this->assertContains($role, $keys, "mẫu cho vai trò không tồn tại: {$role}");
            $this->assertLessThanOrEqual(AgentProfiles::PERSONA_MAX, mb_strlen($t['persona']), "{$role} persona");
            $this->assertLessThanOrEqual(AgentProfiles::INSTRUCTIONS_MAX, mb_strlen($t['instructions']), "{$role} quy tắc");
            $this->assertNotSame('', $t['label']);
        }
        // Mẫu không chứa số liệu y khoa cụ thể kiểu ngưỡng/liều (chỉ nguyên tắc).
        foreach (ExpertTemplates::DATA as $t) {
            $this->assertDoesNotMatchRegularExpression('/\d+\s*(mg|mmol|%|kcal|g\/dl)/iu', str_replace('100%', '', $t['persona'].$t['instructions'])); // "100%" chỉ xuất hiện trong danh sách từ bị cấm
        }
    }

    public function test_nap_mau_chi_dien_vao_o_va_chi_co_hieu_luc_khi_luu(): void
    {
        $admin = User::create(['name' => 'a', 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin);
        $c = Livewire::test(AgentRoles::class)->call('openStudio', 'writer', 'training')->call('applyExpertTemplate');
        $this->assertStringContainsString('biên tập viên nội dung sức khỏe', $c->get('fPersona'));
        $this->assertSame([], AgentProfiles::custom('writer'), 'chưa bấm Lưu thì chưa có gì thay đổi');
        $c->call('saveProfile');
        $this->assertStringContainsString('sức khỏe', (string) (AgentProfiles::custom('writer')['persona'] ?? ''));
    }

    public function test_hoan_tac_sau_khi_dien_mau_chua_luu_chi_bo_phan_dien_va_khong_doi_ban_da_luu(): void
    {
        $admin = User::create(['name' => 'a', 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin);
        $c = Livewire::test(AgentRoles::class)->call('openStudio', 'writer', 'training')
            ->set('fPersona', 'Bản đã lưu A')->call('saveProfile')
            ->set('fPersona', 'Bản đã lưu B')->call('saveProfile')
            ->call('applyExpertTemplate')->call('undoProfile');
        $this->assertSame('Bản đã lưu B', $c->get('fPersona'), 'chỉ bỏ phần điền mẫu, không lùi về bản lưu trước');
        $this->assertSame('Bản đã lưu B', AgentProfiles::custom('writer')['persona']);
        $c->call('undoProfile');
        $this->assertSame('Bản đã lưu A', $c->get('fPersona'), 'lần Hoàn tác sau mới lùi bản đã lưu');
    }

    public function test_moi_vai_tro_deu_co_mau_va_nap_chi_dien_vao_o(): void
    {
        $admin = User::create(['name' => 'a', 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin);
        foreach (array_column((new RoleRegistry)->all(), 'key') as $key) {
            $this->assertTrue(ExpertTemplates::has($key), "thiếu mẫu cho {$key}");
        }
        $c = Livewire::test(AgentRoles::class)->call('openStudio', 'monitor', 'training')->call('applyExpertTemplate');
        $this->assertStringContainsString('rà soát', $c->get('fPersona'));
        $this->assertSame([], AgentProfiles::custom('monitor'), 'chưa bấm Lưu thì chưa có gì thay đổi');
    }

    public function test_vai_tro_khong_ton_tai_thi_khong_co_mau(): void
    {
        $this->assertNull(ExpertTemplates::for('khong-co-vai-tro-nay'));
    }
}
