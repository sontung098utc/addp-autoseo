<?php

namespace Tests\Feature\Agent;

use App\Filament\Clusters\AgentTeam\Pages\AgentRoles;
use App\Filament\Clusters\AgentTeam\Pages\AgentSkills;
use App\Models\AgentDocument;
use App\Models\AiProvider;
use App\Models\User;
use App\Services\Agent\Roles\AgentProfiles;
use App\Services\Agent\Roles\RoleAssignments;
use App\Services\Agent\Roles\RoleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Tests\TestCase;

/** Vòng tinh chỉnh hồ sơ nhân sự: nút X, 5 mục, Huấn luyện vs Kỹ thuật, thêm/sửa/xóa kỹ năng, upload-zone, bỏ rác. */
class AgentProfileRefineTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $role = 'admin'): User
    {
        $u = User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
        $this->actingAs($u);

        return $u;
    }

    public function test_nut_dong_la_x_co_aria_label_va_tong_quan_khong_con_nut_ky_thuat(): void
    {
        $this->login();
        $html = Livewire::test(AgentRoles::class)->call('openStudio', 'writer')->html();
        $this->assertMatchesRegularExpression('/<button[^>]*data-testid="studio-close"[^>]*aria-label="Đóng"[^>]*title="Đóng"/s', $html);
        $this->assertStringNotContainsString('>Đóng</button>', $html);
        $this->assertStringNotContainsString('studio-open-tech', $html);
        $this->assertStringContainsString('wire:keydown.escape.window="closeStudio"', $html);
    }

    public function test_5_muc_va_lien_ket_cu_van_mo_dung_cho(): void
    {
        $this->login();
        $this->assertSame(['Tổng quan', 'Huấn luyện', 'Mô hình & kỹ thuật', 'Vị trí trong luồng', 'Hoạt động'], array_column(AgentRoles::STUDIO_TABS, 0));
        $t = Livewire::test(AgentRoles::class)->call('openStudio', 'writer', 'persona');
        $t->assertSet('studioTab', 'training')->assertSet('trainSec', 'instructions')->assertSeeHtml('studio-f-persona');
        $t->call('openStudio', 'writer', 'model')->assertSet('studioTab', 'tech')->assertSeeHtml('data-testid="studio-model"');
        $t->call('setStudioTab', 'training', 'skills')->assertSet('trainSec', 'skills');
        $t->call('openStudio', 'writer', 'training')->assertSeeHtml('Huấn luyện: nhân sự học và làm gì. Mô hình & kỹ thuật: nhân sự chạy thế nào.');
    }

    public function test_them_sua_xoa_bat_tat_ky_nang_tu_tao_co_audit_va_prompt(): void
    {
        $admin = $this->login();
        $t = Livewire::test(AgentRoles::class)->call('openStudio', 'writer', 'training')->call('setTrainSec', 'skills');
        $t->mountAction('createSkill', ['role' => 'writer'])
            ->setActionData(['title' => 'Viết tiêu đề', 'description' => 'Tiêu đề dưới 60 ký tự', 'body' => 'Luôn viết tiêu đề ngắn.', 'roles' => ['writer', 'editor'], 'enabled' => true])
            ->callMountedAction()->assertHasNoActionErrors();
        $this->assertCount(1, AgentProfiles::documents('writer', 'skill'));
        $this->assertCount(1, AgentProfiles::documents('editor', 'skill'));
        $doc = AgentProfiles::documents('writer', 'skill')[0];
        $this->assertSame('Tiêu đề dưới 60 ký tự', $doc->description);
        $this->assertStringContainsString('Viết tiêu đề', (string) AgentProfiles::promptBlock('writer'));

        $t->mountAction('editSkill', ['id' => $doc->id])
            ->setActionData(['title' => 'Viết tiêu đề hay', 'description' => '', 'body' => 'Nội dung mới.', 'role' => 'writer', 'enabled' => true])
            ->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame('Viết tiêu đề hay', $doc->fresh()->title);
        $this->assertSame($admin->id, (int) $doc->fresh()->updated_by);

        $t->call('toggleSkill', 'doc:'.$doc->id, false);
        $this->assertFalse($doc->fresh()->enabled);
        $this->assertStringNotContainsString('Viết tiêu đề hay', (string) AgentProfiles::promptBlock('writer'), 'kỹ năng tắt không đi vào prompt');

        $t->mountAction('deleteSkill', ['ref' => 'doc:'.$doc->id])->callMountedAction();
        $this->assertNull(AgentDocument::find($doc->id));
    }

    public function test_ky_nang_mac_dinh_khong_xoa_cung_chi_tat_duoc(): void
    {
        $this->login();
        $t = Livewire::test(AgentRoles::class)->call('openStudio', 'writer', 'training', 'skills');
        $t->mountAction('deleteSkill', ['ref' => 'writer/article-draft'])->callMountedAction();
        $this->assertSame([], AgentDocument::all()->all());
        $t->call('toggleSkill', 'writer/article-draft', false, 'writer');
        $this->assertFalse((new RoleAssignments)->skillState('writer/article-draft')['enabled']);
    }

    public function test_nguoi_duyet_khong_them_sua_xoa_ky_nang(): void
    {
        $this->login('approver');
        $t = Livewire::test(AgentRoles::class)->call('openStudio', 'writer', 'training')->call('setTrainSec', 'skills');
        $t->assertDontSeeHtml('data-testid="studio-skill-add"')->assertActionHidden('createSkill');
        $t->call('toggleSkill', 'writer/article-draft', false, 'writer')->assertForbidden();
    }

    public function test_trang_ky_nang_co_them_sua_xoa_va_loc_tu_tao(): void
    {
        $admin = $this->login();
        AgentProfiles::addDocument('writer', 'skill', 'Kỹ năng tự tạo A', 'Nội dung A', $admin, 'Mô tả A');
        $t = Livewire::test(AgentSkills::class);
        $t->assertSee('Kỹ năng tự tạo A')->assertSee('Mô tả A')->assertSeeHtml('data-testid="skills-add"')->assertSeeHtml('data-testid="skill-switch"');
        $t->set('statusFilter', 'custom')->assertSee('Kỹ năng tự tạo A');
        $t->set('statusFilter', 'draft')->assertDontSee('Kỹ năng tự tạo A');
    }

    public function test_chon_model_duoc_cho_vai_tro_truoc_day_bi_chan(): void
    {
        $admin = $this->login();
        $p = AiProvider::create(['name' => 'Claude', 'kind' => 'anthropic', 'api_key' => 'sk-ant-LONGKEY-0000', 'enabled' => true, 'models' => ['claude-sonnet-5-5']]);
        foreach (['auditor', 'manager', 'publisher'] as $role) {
            if ((new RoleRegistry)->find($role) === null) {
                continue;
            }
            Livewire::test(AgentRoles::class)->call('openStudio', $role, 'tech')->call('pickModel', 'primary', $p->id, 'claude-sonnet-5-5');
            $this->assertSame('claude-sonnet-5-5', (new RoleAssignments)->find($role)?->model, $role);
        }
    }

    public function test_upload_zone_render_day_du(): void
    {
        $html = Blade::render('<x-upload-zone name="doc" model="docFile" accept=".md,.txt" :max-mb="1" :multiple="true" :files="[[\'name\' => \'a.md\', \'size\' => 2048, \'remove\' => \'removeFile(0)\']]" />');
        foreach (['data-testid="doc-zone"', 'Kéo thả tệp vào đây hoặc bấm để chọn', 'wire:model="docFile"', 'accept=".md,.txt"', 'multiple', 'MD, TXT', 'tối đa 1 MB', 'a.md', '2 KB', 'aria-label="Bỏ tệp"', 'livewire-upload-progress', 'dragover'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
    }

    public function test_tab_huan_luyen_dung_upload_zone(): void
    {
        $this->login();
        Livewire::test(AgentRoles::class)->call('openStudio', 'writer', 'training', 'docs')->assertSeeHtml('data-testid="studio-doc-file-zone"')->assertSee('Kéo thả tệp vào đây');
    }

    public function test_mo_pipeline_la_nut_co_icon_va_khong_con_ky_tu_tho(): void
    {
        $this->login();
        $html = Livewire::test(AgentRoles::class)->call('openStudio', 'writer', 'position')->html();
        $this->assertSame(1, preg_match('#<a[^>]*data-testid="studio-open-pipeline"[^>]*>(.*?)</a>#s', $html, $m));
        $this->assertStringContainsString('<svg', $m[1]);
        $this->assertStringContainsString('Mở Pipeline', $m[1]);
        $this->assertStringNotContainsString('↗', $html);
    }
}
