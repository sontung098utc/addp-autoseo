<?php

namespace Tests\Feature\Agent;

use App\Filament\Clusters\AgentTeam\Pages\AgentRoles;
use App\Models\User;
use App\Services\Agent\Roles\RoleRegistry;
use App\Services\Content\Quality\QualityRubric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** AG-S09: sổ vai trò là dữ liệu; mức sẵn sàng suy từ sự thật; chỉ admin/approver xem. */
class AgentRolesTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    public function test_doc_du_tam_vai_tro(): void
    {
        $keys = array_column((new RoleRegistry())->all(), 'key');
        sort($keys);
        $this->assertSame(['auditor', 'competitor', 'editor', 'keyword', 'manager', 'monitor', 'strategist', 'writer'], $keys);
        foreach ((new RoleRegistry())->all() as $r) {
            $this->assertNotSame([], $r['tools_denied']);
            $this->assertContains('Shell / chạy lệnh hệ thống', $r['tools_denied']);
            $this->assertNotSame([], $r['skills']);
        }
    }

    public function test_playbook_thieu_hien_chua_co_va_playbook_co_that_thi_hien_dung(): void
    {
        $reg = new RoleRegistry();
        $manager = collect($reg->skills($reg->find('manager')))->firstWhere('ref', 'manager/seo-orchestrator');
        $this->assertFalse($manager['exists']);
        $this->assertNull($manager['status']);

        $editor = collect($reg->skills($reg->find('editor')))->keyBy('ref');
        $this->assertTrue($editor['editor/qa-review']['exists']);
        $this->assertSame('claude-seo/seo-content', $editor['editor/qa-review']['lineage']['source']);

        $this->actingAs($this->user('admin'));
        Livewire::test(AgentRoles::class)->mountAction('detail', ['role' => 'manager'])->assertSee('Chưa có')->assertSee('manager/seo-orchestrator');
    }

    public function test_rubric_refs_deu_la_ma_luat_that(): void
    {
        $codes = array_keys(QualityRubric::all());
        foreach ((new RoleRegistry())->all() as $r) {
            foreach ($r['rubric_refs'] as $c) {
                $this->assertContains($c, $codes, "{$r['key']} tham chiếu mã không có: {$c}");
            }
        }
        $dir = sys_get_temp_dir().'/roles_'.uniqid();
        mkdir($dir);
        $bad = (array) require resource_path('agents/roles/editor.php');
        $bad['rubric_refs'] = ['NOPE99'];
        file_put_contents($dir.'/editor.php', '<?php return '.var_export($bad, true).';');
        $this->expectException(\InvalidArgumentException::class);
        (new RoleRegistry($dir))->all();
    }

    public function test_readiness_trung_thuc_khong_co_active(): void
    {
        $reg = new RoleRegistry();
        foreach ($reg->all() as $r) {
            $this->assertNotSame('active', $r['readiness'], "{$r['key']} không được tô 'active' khi chưa có bằng chứng staging và người thật nghiệm thu");
        }
        foreach (['writer', 'strategist', 'editor'] as $k) {
            $this->assertSame('in_development', $reg->find($k)['readiness']);
        }
        $this->assertSame('idea', $reg->find('manager')['readiness']);
        foreach (['auditor', 'keyword', 'competitor', 'monitor'] as $k) {
            $this->assertSame('not_connected', $reg->find($k)['readiness']);
        }
    }

    public function test_khai_active_bi_tu_choi(): void
    {
        $dir = sys_get_temp_dir().'/roles_'.uniqid();
        mkdir($dir);
        $r = (array) require resource_path('agents/roles/monitor.php');
        $r['readiness'] = 'active';
        file_put_contents($dir.'/monitor.php', '<?php return '.var_export($r, true).';');
        $this->expectException(\InvalidArgumentException::class);
        (new RoleRegistry($dir))->all();
    }

    public function test_vai_tro_noi_phai_co_that_trong_orchestrator_va_vai_tro_chua_noi_thi_khong(): void
    {
        $src = file_get_contents(app_path('Services/Agent/Run/AgentRunOrchestrator.php'));
        foreach ((new RoleRegistry())->all() as $r) {
            $actors = array_values(array_unique($r['orchestrator_steps']));
            if ($r['orchestrator_steps'] === []) {
                $this->assertDoesNotMatchRegularExpression("/step\\(\\\$run, [^,]+, '{$r['key']}'/", $src, "{$r['key']} chưa nối nhưng orchestrator đã gọi");
            }
            foreach ($actors as $a) {
                $this->assertSame($r['key'], $a);
                $this->assertMatchesRegularExpression("/step\\(\\\$run, [^,]+, '{$a}'/", $src, "{$r['key']} khai báo đã nối nhưng orchestrator không gọi");
            }
        }
    }

    public function test_trang_render_va_chi_tiet_co_du_muc(): void
    {
        $this->actingAs($this->user('approver'));
        $this->get('/admin/doi-ai/vai-tro')->assertOk()->assertSee('Chuyên viên Audit')->assertSee('Biên tập / QA nội dung')->assertSee('Đang phát triển')->assertDontSee('Đang hoạt động');
        Livewire::test(AgentRoles::class)->mountAction('detail', ['role' => 'editor'])
            ->assertSee('Mục tiêu')->assertSee('Rubric')->assertSee('LEN01')->assertSee('Rào chắn')->assertSee('Điều kiện đạt')->assertSee('Xuất xứ')
            ->assertSee('editor/eeat-check')->assertSee('Chưa có');
    }

    public function test_bien_tap_vien_bi_chan(): void
    {
        $this->actingAs($this->user('writer'));
        $this->get('/admin/doi-ai/vai-tro')->assertForbidden();
    }
}
