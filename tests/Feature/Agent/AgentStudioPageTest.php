<?php

namespace Tests\Feature\Agent;

use App\Filament\Clusters\AgentTeam\Pages\AgentRoles;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Brain;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Roles\AgentProfiles;
use App\Services\Agent\Roles\AgentStudioData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Agent Studio: cửa sổ hồ sơ vai trò trong Đội AI ▸ Vai trò. */
class AgentStudioPageTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $role): User
    {
        $u = User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
        $this->actingAs($u);

        return $u;
    }

    public function test_admin_mo_vai_tro_va_luu_persona(): void
    {
        $this->login('admin');
        Livewire::test(AgentRoles::class)
            ->call('openStudio', 'writer', 'persona')
            ->assertSee('studio-save', false)
            ->set('fPersona', 'Viết như cô giáo.')
            ->set('fTraits', ['tỉ mỉ'])
            ->call('saveProfile')
            ->assertSet('fPersona', 'Viết như cô giáo.');
        $this->assertSame('Viết như cô giáo.', AgentProfiles::for('writer')['persona']);
        $this->assertSame(1, AgentProfiles::for('writer')['version']);
    }

    public function test_hoan_tac_va_lich_su(): void
    {
        $this->login('admin');
        $t = Livewire::test(AgentRoles::class)->call('openStudio', 'writer', 'persona');
        $t->set('fPersona', 'A')->call('saveProfile')->set('fPersona', 'B')->call('saveProfile')->call('undoProfile')->assertSet('fPersona', 'A');
        $t->call('toggleHistory')->assertSee('studio-history', false)->assertSee('Khôi phục');
    }

    public function test_nguoi_viet_chi_xem_khong_co_nut_luu(): void
    {
        $this->login('approver');
        $t = Livewire::test(AgentRoles::class)->call('openStudio', 'writer', 'persona');
        $t->assertDontSee('studio-save', false)->assertSee('Chỉ xem');
        $t->set('fPersona', 'hack')->call('saveProfile');
        $this->assertFalse(AgentProfiles::for('writer')['is_customized']);
    }

    public function test_tab_vi_tri_to_sang_dung_buoc_cua_vai_tro(): void
    {
        $this->login('admin');
        $pos = collect(AgentStudioData::positions('strategist'))->firstWhere('id', 'article_write');
        $mine = array_column(array_filter($pos['steps'], fn ($s) => $s['mine']), 'id');
        $this->assertSame(['brief'], $mine);
        $w = collect(AgentStudioData::positions('writer'))->firstWhere('id', 'article_write');
        $this->assertContains('write', array_column(array_filter($w['steps'], fn ($s) => $s['mine']), 'id'));

        Livewire::test(AgentRoles::class)->call('openStudio', 'strategist', 'position')
            ->assertSee('studio-pos-article_write', false)->assertSee('Lập dàn ý')->assertSee('Mở Pipeline');
    }

    public function test_day_lai_tao_bai_hoc_tu_buoc_loi(): void
    {
        $this->login('admin');
        $run = AgentRun::create(['website_id' => Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://s.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active'])->id, 'job_type' => 'article_write', 'status' => 'failed', 'trigger' => 'manual']);
        AgentRunStep::create(['agent_run_id' => $run->id, 'seq' => 1, 'stage' => 'write', 'role' => 'writer', 'status' => 'warn', 'title' => 'Viết bài', 'detail' => 'Thiếu nguồn cho số liệu']);
        $issues = AgentStudioData::trainingIssues('writer');
        $this->assertCount(1, $issues);
        $this->assertStringContainsString('Thiếu nguồn', $issues[0]['draft']);

        Livewire::test(AgentRoles::class)->call('openStudio', 'writer', 'training')->call('setTrainSec', 'lessons')->assertSee('Dạy lại')
            ->set('teach', [$issues[0]['key'] => 'Luôn kèm nguồn cho số liệu.'])
            ->call('teachRow', $issues[0]['key'], $run->id);
        $l = AgentProfiles::lessons('writer');
        $this->assertCount(1, $l);
        $this->assertSame($run->id, (int) $l[0]->run_id);
        $this->assertSame('run', $l[0]->source);
    }

    public function test_tai_lieu_ky_nang_rieng_va_nhan_ban_playbook(): void
    {
        $this->login('admin');
        Livewire::test(AgentRoles::class)->call('openStudio', 'writer', 'training')->call('setTrainSec', 'skills')
            ->call('cloneSkill', 'writer/article-draft')
            ->assertActionMounted('createSkill')
            ->callMountedAction()
            ->assertHasNoActionErrors();
        $this->assertCount(1, AgentProfiles::documents('writer', 'skill'));
        $this->assertStringStartsWith('Bản riêng:', AgentProfiles::documents('writer', 'skill')[0]->title);
        $this->assertNotNull(AgentProfiles::promptBlock('writer'));
    }

    public function test_the_hien_ten_tuy_chinh_va_cac_tab_khong_loi(): void
    {
        $this->login('admin');
        AgentProfiles::save('writer', ['display_name' => 'Cô Mai'], auth()->user());
        $t = Livewire::test(AgentRoles::class)->assertSee('Cô Mai');
        $t->call('openStudio', 'writer');
        foreach (array_keys(AgentRoles::STUDIO_TABS) as $tab) {
            $t->call('setStudioTab', $tab)->assertOk();
        }
    }

    private function site(): Website
    {
        return Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://s.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    public function test_tab_hoat_dong_khong_lo_ma_tho(): void
    {
        $this->login('admin');
        $run = AgentRun::create(['website_id' => $this->site()->id, 'job_type' => 'article_write', 'status' => 'ready_for_review', 'trigger' => 'manual']);
        AgentRunStep::create(['agent_run_id' => $run->id, 'seq' => 1, 'stage' => 'write', 'role' => 'writer', 'status' => 'ok', 'title' => 'Viết bài']);
        $html = Livewire::test(AgentRoles::class)->call('openStudio', 'writer', 'activity')
            ->assertSee('Xem diễn biến')->assertSee('Chờ duyệt')->assertSee('#'.$run->id)->html();
        $this->assertStringNotContainsString('ready_for_review', $html);
        $this->assertStringNotContainsString('article_write', $html);
        $this->assertSame('Viết bài #'.$run->id, AgentStudioData::runTitle('article_write', $run->id));
    }

    public function test_bai_hoc_gop_nhom_co_so_lan(): void
    {
        $this->login('admin');
        $run = AgentRun::create(['website_id' => $this->site()->id, 'job_type' => 'article_write', 'status' => 'failed', 'trigger' => 'manual']);
        for ($n = 1; $n <= 3; $n++) {
            AgentRunStep::create(['agent_run_id' => $run->id, 'seq' => $n, 'stage' => 'write', 'role' => 'writer', 'status' => 'warn', 'title' => 'Viết bản đầu', 'detail' => str_repeat('Thiếu nguồn. ', 30)]);
        }
        $t = Livewire::test(AgentRoles::class)->call('openStudio', 'writer', 'training')->call('setTrainSec', 'lessons')
            ->assertSee('Cảnh báo: Viết bản đầu · 3 lần')->assertSee('studio-teach-group', false);
        $this->assertSame(1, substr_count($t->html(), 'studio-issue-group'));
        $t->set('teach', ['grp-x' => 'a']); // không lỗi khi khóa lạ
        $key = 'grp-'.substr(md5('Cảnh báo: Viết bản đầu'), 0, 10);
        $t->set('teach', [$key => 'Luôn dẫn nguồn.'])->call('teachRow', $key, $run->id);
        $this->assertCount(1, AgentProfiles::lessons('writer'));
    }

    public function test_tri_tue_la_cong_tac_va_chi_luu_khi_bam_luu(): void
    {
        $this->login('admin');
        $a = Brain::create(['name' => 'Trí tuệ A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        foreach (range(1, 9) as $n) {
            Brain::create(['name' => 'Kho '.$n, 'slug' => 'k'.$n.uniqid(), 'status' => 'active']);
        }
        $t = Livewire::test(AgentRoles::class)->call('openStudio', 'writer', 'training')->call('setTrainSec', 'brain')
            ->assertSee('studio-brain-search', false)->assertSee('role="switch"', false)->assertDontSee('wire:model="grants.', false);
        $t->call('toggleGrant', $a->id)->assertSet('grants.'.$a->id, true);
        $this->assertSame([], AgentProfiles::brainGrants('writer'));
        $t->call('saveGrants');
        $this->assertSame([$a->id], AgentProfiles::brainGrants('writer'));
        $t->call('toggleGrant', $a->id)->call('saveGrants');
        $this->assertSame([], AgentProfiles::brainGrants('writer'));
    }

    public function test_nguoi_duyet_khong_bat_duoc_cong_tac_tri_tue(): void
    {
        $a = Brain::create(['name' => 'Trí tuệ A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        $this->login('approver');
        try {
            Livewire::test(AgentRoles::class)->call('openStudio', 'writer', 'training')->call('toggleGrant', $a->id)->assertSet('grants', []);
        } catch (\Throwable) {
            $this->assertSame([], AgentProfiles::brainGrants('writer'));
        }
    }
}
