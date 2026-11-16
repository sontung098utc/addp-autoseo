<?php

namespace Tests\Feature\Agent;

use App\Filament\Clusters\AgentTeam\Pages\AgentSkills;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\User;
use App\Services\Ai\Playbook\PlaybookLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** AG-S13: tab Kỹ năng đọc playbook thật, trung thực về trạng thái nháp, an toàn với file hỏng và XSS. */
class AgentSkillsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function tmpDir(array $files): string
    {
        $dir = sys_get_temp_dir().'/pb_skills_'.uniqid();
        foreach ($files as $rel => $content) {
            @mkdir(dirname("$dir/$rel"), 0777, true);
            file_put_contents("$dir/$rel", $content);
        }

        return $dir;
    }

    private function pb(string $role, string $key, string $body = 'Nội dung.', string $status = 'draft'): string
    {
        return "---\nkey: $key\nversion: 1\nrole: $role\ntitle: Tiêu đề $key\nstatus: $status\nmodel_tier: standard\nrubric_refs:\n  - LEN01\nlineage:\n  source: claude-seo/x\n  source_version: 1.0.0\n  license: MIT\n  adaptations: đã chỉnh\nreviewed_at: 2026-11-07\n---\n$body\n";
    }

    public function test_danh_sach_khop_so_file_playbook_that_va_draft_hien_nhap(): void
    {
        $this->actingAs($this->user('admin'));
        $n = count(glob(resource_path('playbooks').'/*/*.md'));
        $this->assertGreaterThan(0, $n);

        $t = Livewire::test(AgentSkills::class);
        $t->assertSee("{$n} kỹ năng học từ claude-seo (MIT)")->assertSee('Nháp, chưa qua bộ mẫu')->assertSee('Xem ghi công (NOTICE)');
        foreach ((new PlaybookLoader())->all() as $p) {
            $t->assertSee($p->ref());
        }
    }

    public function test_playbook_hong_khong_lam_vo_trang(): void
    {
        $dir = $this->tmpDir(['writer/article-draft.v1.md' => $this->pb('writer', 'article-draft'), 'writer/hong.v1.md' => "khong co frontmatter\n"]);
        $this->app->bind(PlaybookLoader::class, fn () => new PlaybookLoader($dir));
        $this->actingAs($this->user('admin'));

        Livewire::test(AgentSkills::class)->assertOk()->assertSee('writer/article-draft@1')->assertSee('Lỗi định dạng')->assertSee('1 file lỗi');
    }

    public function test_markdown_escape_xss_va_link_nguy_hiem(): void
    {
        $dir = $this->tmpDir(['writer/article-draft.v1.md' => $this->pb('writer', 'article-draft', "<script>alert(1)</script>\n\n[bấm](javascript:alert(2))")]);
        $this->app->bind(PlaybookLoader::class, fn () => new PlaybookLoader($dir));
        $this->actingAs($this->user('admin'));

        $t = Livewire::test(AgentSkills::class)->mountAction('detail', ['ref' => 'writer/article-draft@1']);
        $t->assertDontSeeHtml('<script>alert(1)</script>')->assertSeeHtml('&lt;script&gt;')->assertDontSeeHtml('href="javascript:');
    }

    public function test_quyen_bien_tap_vien_bi_chan(): void
    {
        $this->actingAs($this->user('editor'));
        $this->assertFalse(AgentSkills::canAccess());
        $this->actingAs($this->user('admin'));
        $this->assertTrue(AgentSkills::canAccess());
    }

    public function test_dem_da_dung_tu_agent_run_steps(): void
    {
        $this->actingAs($this->user('admin'));
        $site = \App\Models\Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $art = \App\Models\Article::create(['website_id' => $site->id, 'primary_keyword' => 'a']);
        $run = AgentRun::create(['article_id' => $art->id, 'website_id' => $site->id, 'status' => 'held', 'ai_mode' => 'mock']);
        foreach ([['writer/article-draft@1', '2026-11-01 10:00:00'], ['writer/article-draft@1', '2026-11-05 09:30:00']] as $i => [$ref, $at]) {
            AgentRunStep::create(['agent_run_id' => $run->id, 'seq' => $i + 1, 'stage' => 'write', 'role' => 'writer', 'status' => 'done', 'title' => 't', 'started_at' => $at, 'data' => ['playbook' => ['ref' => $ref]]]);
        }

        Livewire::test(AgentSkills::class)->mountAction('detail', ['ref' => 'writer/article-draft@1'])->assertSee('Đã dùng trong 2 bước')->assertSee('05/11/2026 09:30');
        Livewire::test(AgentSkills::class)->mountAction('detail', ['ref' => 'editor/qa-review@1'])->assertSee('Chưa được phiên nào dùng');
    }

    public function test_bo_loc_va_modal_notice(): void
    {
        $this->actingAs($this->user('admin'));
        Livewire::test(AgentSkills::class)->set('statusFilter', 'active')->assertSee('Không có kỹ năng nào khớp bộ lọc.')
            ->set('statusFilter', '')->set('roleFilter', 'editor')->assertSee('editor/qa-review@1')->assertDontSee('writer/article-draft@1');
        Livewire::test(AgentSkills::class)->mountAction('notice')->assertSee('claude-seo');
    }
}
