<?php

namespace Tests\Feature\Agent;

use App\Filament\Clusters\AgentTeam\Pages\PipelineMap;
use App\Filament\Pages\AuditPage;
use App\Filament\Pages\BrainBuildPage;
use App\Filament\Pages\KeywordsPage;
use App\Filament\Resources\AgentRunResource;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Pipeline\JobTypeRegistry;
use App\Services\Agent\Pipeline\PipelineVisual;
use App\Services\Agent\Roles\RoleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Tab Pipeline dạng sơ đồ: Các luồng, Đang chạy, Văn phòng AI. */
class PipelineVisualTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function makeRun(string $jobType, string $status = 'running'): AgentRun
    {
        $run = AgentRun::create(['website_id' => Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://'.uniqid().'.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active'])->id, 'job_type' => $jobType, 'status' => $status, 'trigger' => 'manual', 'started_at' => now()]);
        AgentRunStep::create(['agent_run_id' => $run->id, 'seq' => 1, 'stage' => 'guard', 'role' => 'system', 'status' => 'ok', 'title' => 'Kiểm', 'cost_vnd' => 10]);

        return $run;
    }

    public function test_moi_buoc_deu_co_role_hop_le(): void
    {
        $valid = ['system', ...array_column((new RoleRegistry)->all(), 'key')];
        foreach (JobTypeRegistry::all() as $j) {
            foreach ($j['steps'] as $s) {
                $this->assertContains($s['role'] ?? null, $valid, "{$j['id']}/{$s['id']}");
            }
        }
        $this->assertSame([], JobTypeRegistry::problems());
    }

    public function test_moi_loai_viec_co_so_do_du_nut_buoc_va_chip_nhan_su(): void
    {
        $this->actingAs($this->user('approver'));
        $html = $this->get('/admin/doi-ai/pipeline')->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="pipeline-flows"', $html);
        foreach (JobTypeRegistry::all() as $j) {
            $this->assertStringContainsString('data-flow="'.$j['id'].'"', $html);
            $this->assertSame(count($j['steps']), substr_count($this->section($html, $j['id']), 'data-step="'), $j['id']);
            $this->assertSame(count($j['steps']), substr_count($this->section($html, $j['id']), 'data-avatar'), $j['id']);
            $this->assertStringContainsString('ĐẦU VÀO', $this->section($html, $j['id']));
            $this->assertStringContainsString('KẾT QUẢ', $this->section($html, $j['id']));
        }
    }

    private function section(string $html, string $id): string
    {
        $a = strpos($html, 'data-flow="'.$id.'"');
        $b = strpos($html, '</section>', $a);

        return substr($html, $a, $b - $a);
    }

    public function test_buoc_planned_co_nhan_chua_chay_va_buoc_nguoi_co_vien_rieng(): void
    {
        $this->actingAs($this->user('approver'));
        $html = $this->get('/admin/doi-ai/pipeline')->getContent();
        $mr = $this->section($html, 'market_research');
        $this->assertStringContainsString('pv-node--planned', $mr);
        $this->assertStringContainsString('Chưa chạy', $mr);
        $this->assertStringContainsString('pv-node--human', $this->section($html, 'article_write'));
        $this->assertStringContainsString('tùy điều kiện', $this->section($html, 'article_write'));
    }

    public function test_dau_vao_va_ket_qua_co_lien_ket_dung_url(): void
    {
        $this->actingAs($this->user('approver'));
        $html = $this->get('/admin/doi-ai/pipeline')->getContent();
        $this->assertStringContainsString('href="'.KeywordsPage::getUrl().'"', $this->section($html, 'market_research'));
        $this->assertStringContainsString('href="'.AuditPage::getUrl().'"', $this->section($html, 'site_audit'));
        $this->assertStringContainsString('href="'.BrainBuildPage::getUrl().'"', $this->section($html, 'brain_build'));
        $this->assertStringContainsString('Chi phí AI', $this->section($html, 'article_write'));
    }

    public function test_an_lien_ket_khi_khong_du_quyen(): void
    {
        $this->actingAs($this->user('approver'));
        $all = collect(JobTypeRegistry::all())->sum(fn ($j) => count(PipelineVisual::recordLinks($j)));
        $this->assertSame(collect(JobTypeRegistry::all())->sum(fn ($j) => count($j['records'])), $all);
        $this->actingAs($this->user('writer'));
        $writer = collect(JobTypeRegistry::all())->sum(fn ($j) => count(PipelineVisual::recordLinks($j)));
        $this->assertLessThan($all, $writer);
        $this->assertNull(PipelineVisual::urlOf(AgentRunResource::class));
        foreach (JobTypeRegistry::all() as $j) {
            foreach ($j['records'] as $r) {
                $expect = PipelineVisual::urlOf($r['class']);
                $found = array_filter(PipelineVisual::recordLinks($j), fn ($l) => $l['label'] === $r['label']);
                $this->assertSame($expect === null, $found === [], $j['id'].'/'.$r['label']);
            }
        }
    }

    public function test_che_do_dang_chay_hien_phien_va_poll(): void
    {
        $this->actingAs($this->user('approver'));
        $run = $this->makeRun('site_audit');
        $html = $this->get('/admin/doi-ai/pipeline')->assertOk()->getContent();
        $this->assertStringContainsString('data-run="'.$run->id.'"', $html);
        $this->assertStringContainsString('ss-live-pill', $html);
        $this->assertStringContainsString('Xem diễn biến', $html);
        $this->assertMatchesRegularExpression('/data-testid="pipeline"[^>]*wire:poll\.2s/', $html);
    }

    public function test_khong_poll_khi_khong_co_phien_running(): void
    {
        $this->actingAs($this->user('approver'));
        $this->makeRun('site_audit', 'ready_for_review');
        $html = $this->get('/admin/doi-ai/pipeline')->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/data-testid="pipeline"[^>]*wire:poll/', $html);
        $this->assertStringContainsString('Không có phiên nào đang chạy', $html);
    }

    public function test_van_phong_ai_du_role_va_dang_lam(): void
    {
        $this->actingAs($this->user('approver'));
        $this->makeRun('site_audit');
        $step = AgentRunStep::first();
        $step->update(['stage' => 'crawl', 'role' => 'auditor']);
        $this->makeRun('article_write')->steps()->update(['stage' => 'brief', 'role' => 'strategist']);
        $html = $this->get('/admin/doi-ai/pipeline')->getContent();
        foreach ((new RoleRegistry)->all() as $r) {
            $this->assertStringContainsString('data-desk="'.$r['key'].'"', $html);
        }
        $this->assertStringContainsString('Đang làm', $html);
        $desks = collect(PipelineVisual::office());
        $this->assertSame(1, $desks->where('profile.key', 'auditor')->first()['runs_today']);
        $this->assertNotEmpty($desks->where('profile.key', 'writer')->first()['working']);
        $this->assertSame([], $desks->where('profile.key', 'editor')->first()['working']);
        $this->assertStringContainsString('Rảnh', $html);
        $this->assertStringContainsString('Mở hồ sơ', $html);
    }

    public function test_cua_so_truot_buoc_co_so_lieu_that_hoac_noi_chua_co(): void
    {
        $this->actingAs($this->user('approver'));
        Livewire::test(PipelineMap::class)->call('mountAction', 'step', ['job' => 'site_audit', 'step' => 'crawl'])
            ->assertSee('Chưa có lần chạy nào')->assertSee('Chuyên viên Audit');
        $run = $this->makeRun('site_audit');
        $run->steps()->update(['stage' => 'crawl', 'role' => 'auditor']);
        Livewire::test(PipelineMap::class)->call('mountAction', 'step', ['job' => 'site_audit', 'step' => 'crawl'])
            ->assertSee('1 lần chạy')->assertDontSee('Chưa có lần chạy nào');
    }
}
