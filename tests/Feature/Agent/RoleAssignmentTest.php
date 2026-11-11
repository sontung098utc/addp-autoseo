<?php

namespace Tests\Feature\Agent;

use App\Filament\Clusters\AgentTeam\Pages\AgentRoles;
use App\Filament\Pages\AiUsagePage;
use App\Filament\Pages\TrustPolicy;
use App\Models\AgentRoleAssignment;
use App\Models\AiModelRoute;
use App\Models\AiProvider;
use App\Models\Article;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Ai\AiModelRouter;
use App\Services\Agent\Roles\RoleAssignments;
use App\Services\Agent\Roles\RoleRegistry;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Services\Ai\DemoScriptedAiProvider;
use App\Services\Ai\Playbook\PlaybookLoader;
use App\Services\Publishing\EmergencyStopService;
use App\Services\Security\UrlSafetyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\TestCase;

/** AG-S17: Tích hợp AI/Chính sách nằm ở Cài đặt; gán model + kỹ năng theo vai trò (mức bằng chứng: test giả lập, chưa thử trình duyệt/model thật). */
class RoleAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'sk-test-SECRETKEY-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        app(EmergencyStopService::class)->resetForTesting();
        $this->app->instance(UrlSafetyService::class, new class extends UrlSafetyService
        {
            protected function resolveIps(string $host): array
            {
                return filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ['93.184.216.34'];
            }
        });
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function provider(string $name = 'Anthropic', string $kind = 'anthropic', bool $key = true): AiProvider
    {
        return AiProvider::create(['name' => $name, 'kind' => $kind, 'api_key' => $key ? self::KEY : null, 'enabled' => true]);
    }

    public function test_url_cu_chuyen_huong_sang_cai_dat(): void
    {
        $this->get('/admin/tich-hop-ai')->assertStatus(301)->assertRedirect('/admin/cau-hinh-ai#tab-tich-hop-ai');
        $this->get('/admin/doi-ai/ket-noi-ai')->assertStatus(301)->assertRedirect('/admin/cau-hinh-ai#tab-tich-hop-ai');
        $this->get('/admin/doi-ai/chinh-sach')->assertStatus(301)->assertRedirect('/admin/chinh-sach-tin-cay');
    }

    public function test_hai_trang_nam_o_nhom_cai_dat_ngoai_cum(): void
    {
        foreach ([AiUsagePage::class, TrustPolicy::class] as $page) {
            $this->assertSame('Cài đặt', $page::getNavigationGroup());
            $this->assertNull($page::getCluster());
        }
        $this->actingAs($this->user('approver'));
        $this->assertStringEndsWith('/admin/cau-hinh-ai#tab-tich-hop-ai', AiUsagePage::configUrl());
        $this->assertStringEndsWith('/admin/chinh-sach-tin-cay', TrustPolicy::getUrl());
        $this->get('/admin/cau-hinh-ai')->assertOk();
        $this->get('/admin/chinh-sach-tin-cay')->assertOk();
        $this->get('/admin/doi-ai/vai-tro')->assertOk()->assertDontSee('/admin/doi-ai/ket-noi-ai', false);
    }

    public function test_bien_tap_vien_bi_chan_o_trang_moi(): void
    {
        $this->actingAs($this->user('writer'));
        $this->get('/admin/cau-hinh-ai')->assertForbidden();
        $this->get('/admin/chinh-sach-tin-cay')->assertForbidden();
        $this->get('/admin/doi-ai/vai-tro')->assertForbidden();
    }

    public function test_admin_gan_model_cho_vai_tro_qua_giao_dien_va_the_hien_trang_thai(): void
    {
        $a = $this->provider('Anthropic');
        $g = $this->provider('Gemini', 'gemini');
        $this->actingAs($this->user('admin'));
        Livewire::test(AgentRoles::class)
            ->call('openStudio', 'writer', 'model')
            ->assertSee('Model khả dụng')->assertSee('claude-sonnet-5-5')->assertSee('gemini-1.5-flash')
            ->call('pickModel', 'primary', $a->id, 'claude-sonnet-5-5')
            ->call('pickModel', 'fallback', $g->id, 'gemini-1.5-flash')
            ->assertSee('Anthropic')->assertSee('Gemini');
        $this->assertSame('Anthropic · claude-sonnet-5-5, dự phòng Gemini · gemini-1.5-flash', (new RoleAssignments)->summary('writer'));
        $row = AgentRoleAssignment::where('role_key', 'writer')->first();
        $this->assertSame($a->id, $row->provider_id);
        $this->assertSame('claude-sonnet-5-5', $row->model);
        $this->assertNotNull($row->updated_by);
    }

    public function test_approver_chi_xem_khong_gan_duoc(): void
    {
        $a = $this->provider();
        (new RoleAssignments)->saveModel('writer', $a->id, 'claude-sonnet-5-5', null, null, true, $this->user('admin'));
        $this->actingAs($this->user('approver'));
        $this->get('/admin/doi-ai/vai-tro')->assertOk()->assertSee('Anthropic · claude-sonnet-5-5')->assertDontSee('role-assign-btn-writer', false)
            ->assertDontSee('wire:click="mountAction(&#039;assign&#039;', false);
        Livewire::test(AgentRoles::class)->assertActionHidden('assign', ['role' => 'writer'])
            ->call('openStudio', 'writer', 'model')->assertDontSee('Chọn chính')->call('pickModel', 'primary', $a->id, 'claude-haiku-5-5');
        $this->assertSame('claude-sonnet-5-5', AgentRoleAssignment::where('role_key', 'writer')->value('model'), 'người duyệt không đổi được model');
        $this->expectException(\InvalidArgumentException::class);
        (new RoleAssignments)->saveModel('writer', $a->id, 'x', null, null, true, $this->user('approver'));
    }

    public function test_gan_provider_khong_ton_tai_bi_tu_choi(): void
    {
        $svc = new RoleAssignments;
        try {
            $svc->saveModel('writer', 9999, 'm', null, null, true, $this->user('admin'));
            $this->fail('phải từ chối');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('không tồn tại', $e->getMessage());
        }
        $this->assertSame(0, AgentRoleAssignment::count());
        // Qua giao diện: không lưu, báo lỗi.
        $this->actingAs($this->user('admin'));
        Livewire::test(AgentRoles::class)->call('openStudio', 'writer', 'model')->call('pickModel', 'primary', 9999, 'm');
        $this->assertSame(0, AgentRoleAssignment::count());
        // Model chưa bật ở Cấu hình AI cũng bị từ chối.
        $a = $this->provider();
        $a->update(['models' => ['claude-sonnet-5-5']]);
        Livewire::test(AgentRoles::class)->call('openStudio', 'writer', 'model')->call('pickModel', 'primary', $a->id, 'claude-opus-5-5');
        $this->assertSame(0, AgentRoleAssignment::count());
    }

    public function test_vai_tro_chua_noi_khong_gan_model_duoc(): void
    {
        $a = $this->provider();
        $this->expectException(\InvalidArgumentException::class);
        (new RoleAssignments)->saveModel('auditor', $a->id, 'm', null, null, true, $this->user('admin'));
    }

    public function test_router_uu_tien_ban_gan_theo_vai_tro_va_van_chay_khi_khong_co(): void
    {
        $gem = $this->provider('Gemini', 'gemini');
        $ant = $this->provider('Anthropic');
        foreach (['outline', 'write', 'repair'] as $t) {
            AiModelRoute::create(['task' => $t, 'tier' => 'standard', 'primary_provider_id' => $gem->id, 'primary_model' => 'gemini-1.5-flash', 'cost_cap_vnd_per_call' => 500]);
        }
        $r = new AiModelRouter;
        $this->assertSame('gemini-1.5-flash', $r->target('write')->model); // chưa gán: như cũ

        (new RoleAssignments)->saveModel('writer', $ant->id, 'claude-sonnet-5-5', $gem->id, 'gemini-1.5-pro', true, $this->user('admin'));
        $this->assertSame('Anthropic', $r->target('write')->providerName);
        $this->assertSame('claude-sonnet-5-5', $r->target('repair')->model);
        $this->assertSame(500.0, $r->target('write')->costCapVnd);
        $this->assertSame('gemini-1.5-pro', $r->fallback('write')->model);
        $this->assertSame('gemini-1.5-flash', $r->target('outline')->model); // vai trò khác không bị ảnh hưởng

        AgentRoleAssignment::where('role_key', 'writer')->update(['enabled' => false]);
        $this->assertSame('gemini-1.5-flash', $r->target('write')->model);
    }

    public function test_router_chi_co_ban_gan_van_cau_hinh_duoc_va_thieu_khoa_thi_bao_ly_do(): void
    {
        $nokey = $this->provider('NoKey', 'anthropic', false);
        $this->assertFalse(AiModelRouter::isConfigured());
        (new RoleAssignments)->saveModel('strategist', $nokey->id, 'claude-haiku-5-5', null, null, true, $this->user('admin'));
        $this->assertTrue(AiModelRouter::isConfigured());
        $this->assertNull((new AiModelRouter)->target('outline'));
        $this->assertStringContainsString('chưa có khóa API', (string) (new AiModelRouter)->why('outline'));
    }

    private function playbooks(): string
    {
        $dir = sys_get_temp_dir().'/pb17_'.uniqid();
        mkdir($dir.'/writer', 0777, true);
        foreach ([1, 2] as $v) {
            file_put_contents("{$dir}/writer/article-draft.v{$v}.md", "---\nkey: article-draft\nversion: {$v}\nrole: writer\ntitle: T{$v}\nstatus: active\nmodel_tier: standard\nreviewed_at: 2026-11-01\nlineage:\n  source: s\n  source_version: 1\n  license: MIT\n  adaptations: a\n---\nNoi dung v{$v}");
        }
        file_put_contents("{$dir}/writer/article-draft.v3.md", "---\nkey: article-draft\nversion: 3\nrole: writer\ntitle: T3\nstatus: retired\nmodel_tier: standard\nreviewed_at: 2026-11-01\nlineage:\n  source: s\n  source_version: 1\n  license: MIT\n  adaptations: a\n---\nNoi dung v3");

        return $dir;
    }

    public function test_ghim_chi_nhan_playbook_hop_le(): void
    {
        $dir = $this->playbooks();
        $loader = new PlaybookLoader($dir);
        $svc = new RoleAssignments(null, $loader);
        $admin = $this->user('admin');
        foreach ([9 => 'chưa có phiên bản', 3 => 'ngừng dùng'] as $pin => $msg) {
            try {
                $svc->saveSkills('writer', ['writer/article-draft' => ['enabled' => true, 'pin' => $pin]], $admin);
                $this->fail("pin {$pin} phải bị từ chối");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($msg, $e->getMessage());
            }
        }
        try {
            $svc->saveSkills('writer', ['strategist/content-plan' => ['enabled' => false]], $admin);
            $this->fail('kỹ năng ngoài vai trò phải bị từ chối');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $svc->saveSkills('writer', ['writer/article-draft' => ['enabled' => true, 'pin' => 1]], $admin);
        $this->assertSame(1, $loader->find('writer', 'article-draft')->version);
        $this->assertSame(3, $loader->find('writer', 'article-draft', 3)->version); // chỉ định rõ version thì không áp gán

        $svc->saveSkills('writer', ['writer/article-draft' => ['enabled' => false, 'pin' => 1]], $admin);
        $this->assertNull($loader->find('writer', 'article-draft'));
        $row = collect((new RoleRegistry(null, $loader))->skills((new RoleRegistry)->find('writer')))->firstWhere('ref', 'writer/article-draft');
        $this->assertFalse($row['enabled']);
        $this->assertTrue($row['exists']);
    }

    public function test_orchestrator_dung_ban_ghim_va_bo_ky_nang_da_tat(): void
    {
        $dir = $this->playbooks();
        $loader = new PlaybookLoader($dir);
        $admin = $this->user('admin');
        $w = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $art = fn () => Article::create(['website_id' => $w->id, 'primary_keyword' => 'dịch vụ thiết kế website', 'workflow_status' => 'planned']);

        (new RoleAssignments(null, $loader))->saveSkills('writer', ['writer/article-draft' => ['enabled' => true, 'pin' => 1]], $admin);
        $run = (new AgentRunOrchestrator(new DemoScriptedAiProvider, playbooks: $loader))->run($art(), null, 'manual');
        $this->assertSame('writer/article-draft@1', $run->steps->firstWhere('stage', 'write')->data['skill']['ref']);

        (new RoleAssignments(null, $loader))->saveSkills('writer', ['writer/article-draft' => ['enabled' => false, 'pin' => null]], $admin);
        $run = (new AgentRunOrchestrator(new DemoScriptedAiProvider, playbooks: $loader))->run($art(), null, 'manual');
        $this->assertNull($run->steps->firstWhere('stage', 'write')->data['skill']);
    }

    public function test_nhat_ky_ghi_ai_doi_gi(): void
    {
        $a = $this->provider();
        $logged = [];
        Log::shouldReceive('info')->withArgs(function ($m, $ctx = []) use (&$logged) {
            $logged[] = [$m, $ctx];

            return true;
        })->atLeast()->once();
        $admin = $this->user('admin');
        (new RoleAssignments)->saveModel('writer', $a->id, 'claude-sonnet-5-5', null, null, true, $admin);
        $e = collect($logged)->first(fn ($l) => $l[0] === 'audit' && ($l[1]['action'] ?? '') === 'role_assignment')[1];
        $this->assertSame($admin->id, $e['actor_id']);
        $this->assertSame('writer', $e['role']);
        $this->assertSame('model', $e['what']);
        $this->assertArrayHasKey('at_utc', $e);
        $this->assertStringNotContainsString(self::KEY, json_encode($e));
    }

    public function test_khong_co_provider_the_hien_canh_bao_va_khoa_khong_lo(): void
    {
        $this->actingAs($this->user('admin'));
        $this->get('/admin/doi-ai/vai-tro')->assertOk()->assertSee('Chưa có tài khoản AI', false)->assertSee('/admin/cau-hinh-ai', false);
        Livewire::test(AgentRoles::class)->call('openStudio', 'writer', 'model')->assertSee('Chưa có tài khoản AI nào đang bật')->assertSee('Mở Cấu hình AI');
        $this->provider();
        $this->get('/admin/doi-ai/vai-tro')->assertOk()->assertDontSee(self::KEY)->assertDontSee('Chưa có tài khoản AI');
        Livewire::test(AgentRoles::class)->call('openStudio', 'writer', 'model')->assertDontSee(self::KEY)->assertSee('claude-sonnet-5-5');
        Livewire::test(AgentRoles::class)->mountAction('assign', ['role' => 'writer'])->assertDontSee(self::KEY);
    }

    public function test_vai_tro_chua_noi_hien_ghi_chu_chua_noi_vao_luong(): void
    {
        $this->actingAs($this->user('admin'));
        Livewire::test(AgentRoles::class)->call('openStudio', 'auditor', 'model')->assertSee('Chưa nối vào luồng gọi AI');
    }
}
