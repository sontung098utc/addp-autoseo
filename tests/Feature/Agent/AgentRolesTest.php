<?php

namespace Tests\Feature\Agent;

use App\Filament\Clusters\AgentTeam\Pages\AgentRoles;
use App\Filament\Resources\AgentRunResource;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Roles\RoleAssignments;
use App\Services\Agent\Roles\RoleRegistry;
use App\Services\Ai\Playbook\PlaybookLoader;
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
        $keys = array_column((new RoleRegistry)->all(), 'key');
        sort($keys);
        $this->assertSame(['auditor', 'competitor', 'designer', 'editor', 'keyword', 'manager', 'monitor', 'strategist', 'writer'], $keys);
        foreach ((new RoleRegistry)->all() as $r) {
            $this->assertNotSame([], $r['tools_denied']);
            $this->assertContains('Shell / chạy lệnh hệ thống', $r['tools_denied']);
            $this->assertNotSame([], $r['skills']);
        }
    }

    public function test_playbook_thieu_hien_chua_co_va_playbook_co_that_thi_hien_dung(): void
    {
        // Playbook CHƯA tồn tại (thư mục rỗng) thì hiện 'chưa có', không ném lỗi.
        $empty = sys_get_temp_dir().'/pb_empty_'.uniqid();
        mkdir($empty);
        $missing = collect((new RoleRegistry(null, new PlaybookLoader($empty)))->skills((new RoleRegistry)->find('manager')))->firstWhere('ref', 'manager/seo-orchestrator');
        rmdir($empty);
        $this->assertFalse($missing['exists']);
        $this->assertNull($missing['status']);

        // Sau khi AG-S10 thêm playbook thật: có, vẫn là nháp, xuất xứ claude-seo.
        $reg = new RoleRegistry;
        $manager = collect($reg->skills($reg->find('manager')))->firstWhere('ref', 'manager/seo-orchestrator');
        $this->assertTrue($manager['exists']);
        $this->assertSame('draft', $manager['status']);
        $this->assertStringStartsWith('claude-seo/', $manager['lineage']['source']);

        $editor = collect($reg->skills($reg->find('editor')))->keyBy('ref');
        $this->assertTrue($editor['editor/qa-review']['exists']);
        $this->assertSame('claude-seo/seo-content', $editor['editor/qa-review']['lineage']['source']);

        $this->actingAs($this->user('admin'));
        Livewire::test(AgentRoles::class)->call('openStudio', 'manager', 'training')->call('setTrainSec', 'skills')->assertSee('manager/seo-orchestrator');
    }

    public function test_rubric_refs_deu_la_ma_luat_that(): void
    {
        $codes = array_keys(QualityRubric::all());
        foreach ((new RoleRegistry)->all() as $r) {
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
        $reg = new RoleRegistry;
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
        foreach ((new RoleRegistry)->all() as $r) {
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
        $this->get('/admin/doi-ai/vai-tro')->assertOk()->assertSee('Chuyên viên Audit')->assertSee('Biên tập / QA nội dung')->assertDontSee('Đang hoạt động');
        Livewire::test(AgentRoles::class)->call('openStudio', 'editor', 'tech')
            ->assertSee('Đang phát triển')->assertSee('Rubric')->assertSee('LEN01')->assertSee('Rào chắn')->assertSee('Điều kiện đạt')->assertSee('Xuất xứ')
            ->assertSeeHtml('data-testid="studio-model"')
            ->call('setStudioTab', 'training', 'skills')->assertSee('editor/eeat-check');
    }

    public function test_bien_tap_vien_bi_chan(): void
    {
        $this->actingAs($this->user('writer'));
        $this->get('/admin/doi-ai/vai-tro')->assertForbidden();
    }

    /** Một thẻ duy nhất cho mỗi vai trò: avatar, chip model, công tắc và trạng thái cùng nằm trong thẻ; không còn khung "Gán" tách rời. */
    public function test_moi_vai_tro_la_mot_the_co_chip_model_va_cong_tac(): void
    {
        $this->actingAs($this->user('admin'));
        $html = $this->get('/admin/doi-ai/vai-tro')->assertOk()->getContent();
        foreach ((new RoleRegistry)->all() as $r) {
            $k = $r['key'];
            $this->assertSame(1, substr_count($html, 'data-testid="role-card-'.$k.'"'), "{$k} phải có đúng một thẻ");
            $this->assertSame(1, preg_match('#<article[^>]*data-testid="role-card-'.$k.'".*?</article>#s', $html, $m), $k);
            $card = $m[0];
            $this->assertStringContainsString('data-testid="role-status-'.$k.'"', $card);
            $this->assertStringContainsString('data-testid="role-switch-'.$k.'"', $card);
            if (isset(RoleAssignments::ROLE_TASKS[$k])) {
                $this->assertStringContainsString('data-testid="role-model-chip-'.$k.'"', $card);
                $this->assertStringContainsString('Chưa gán model', $card);
            }
            $this->assertStringNotContainsString('data-testid="role-assign-', $html);
        }
        $this->assertDoesNotMatchRegularExpression('#>\s*Gán\s*<#u', $html);
    }

    public function test_huong_dan_trang_vai_tro_nam_trong_x_help(): void
    {
        $this->actingAs($this->user('approver'));
        $html = $this->get('/admin/doi-ai/vai-tro')->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="help"', $html);
        $this->assertSame(1, preg_match('#data-testid="help".*?Bấm tên để mở hồ sơ#s', $html));
        $this->assertStringNotContainsString('Admin bấm "Gán"', $html);
        $this->assertStringNotContainsString('Sửa rubric chưa làm', $html);
    }

    public function test_the_hien_dang_lam_khi_co_phien_running_va_ranh_khi_khong(): void
    {
        $this->actingAs($this->user('admin'));
        $w = Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $run = AgentRun::create(['website_id' => $w->id, 'job_type' => 'site_audit', 'status' => 'running', 'trigger' => 'manual', 'ai_mode' => 'real', 'started_at' => now()]);
        AgentRunStep::create(['agent_run_id' => $run->id, 'seq' => 1, 'stage' => 'crawl', 'role' => 'auditor', 'status' => 'ok', 'title' => 'Quét']);
        $html = $this->get('/admin/doi-ai/vai-tro')->assertOk()->getContent();
        $this->assertSame(1, preg_match('#<article[^>]*data-testid="role-card-auditor".*?</article>#s', $html, $m));
        $this->assertStringContainsString('data-status="working"', $m[0]);
        $this->assertStringContainsString('Đang làm:', $m[0]);
        $this->assertStringContainsString(AgentRunResource::getUrl('view', ['record' => $run]), $m[0]);
        $this->assertSame(1, preg_match('#<article[^>]*data-testid="role-card-editor".*?</article>#s', $html, $e));
        $this->assertStringNotContainsString('data-status="working"', $e[0]);
    }

    public function test_tong_quan_nhan_su_gon_va_so_that(): void
    {
        $this->actingAs($this->user('admin'));
        $t = Livewire::test(AgentRoles::class)->call('openStudio', 'writer');
        $t->assertSee('Đang rảnh')->assertSeeHtml('data-testid="studio-model-line"')->assertSee('Chọn model')
            ->assertDontSee('Đã sẵn sàng chưa')->assertDontSee('Có bài học')->assertDontSee('Chưa có phiên nào trong 7 ngày qua.')->assertDontSee('Chi tiết kỹ thuật')
            ->assertDontSeeHtml('data-testid="studio-week"');
        $w = Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $run = AgentRun::create(['website_id' => $w->id, 'job_type' => 'article_write', 'status' => 'ready_for_review', 'trigger' => 'manual', 'ai_mode' => 'real', 'started_at' => now()]);
        AgentRunStep::create(['agent_run_id' => $run->id, 'seq' => 1, 'stage' => 'write', 'role' => 'writer', 'status' => 'ok', 'title' => 'Viết', 'cost_vnd' => 1234]);
        Livewire::test(AgentRoles::class)->call('openStudio', 'writer')->assertSeeHtml('data-testid="studio-week"')->assertSee('1.234 ₫')->assertSee('100%')->assertSee('Tỉ lệ thành công');
    }
}
