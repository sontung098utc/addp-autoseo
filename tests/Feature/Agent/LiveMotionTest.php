<?php

namespace Tests\Feature\Agent;

use App\Filament\Resources\AgentRunResource;
use App\Filament\Resources\AgentRunResource\Pages\ListAgentRuns;
use App\Filament\Support\Agent\RunActivity;
use App\Livewire\AgentRunningPill;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Article;
use App\Models\User;
use App\Models\Website;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** AG-S07: hiệu ứng "đang chạy" chỉ xuất hiện khi có phiên running thật. */
class LiveMotionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(EmergencyStopService::class)->resetForTesting();
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function makeRun(string $status = 'running', array $stages = []): AgentRun
    {
        $w = Website::query()->first() ?? Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $a = Article::create(['website_id' => $w->id, 'primary_keyword' => 'kw '.uniqid(), 'workflow_status' => 'planned']);
        $run = AgentRun::create(['website_id' => $w->id, 'article_id' => $a->id, 'triggered_by' => null, 'trigger' => 'manual', 'status' => $status, 'trust_tier' => 'T1', 'ai_mode' => 'mock', 'started_at' => now()]);
        foreach ($stages as $i => $st) {
            [$stage, $stStatus] = is_array($st) ? $st : [$st, 'ok'];
            AgentRunStep::create(['agent_run_id' => $run->id, 'seq' => $i + 1, 'stage' => $stage, 'role' => 'system', 'status' => $stStatus, 'title' => $stage, 'detail' => 'x']);
        }

        return $run->refresh();
    }

    private function age(AgentRun $run, int $minutes): void
    {
        $t = now()->utc()->subMinutes($minutes)->format('Y-m-d H:i:s');
        DB::table('agent_runs')->where('id', $run->id)->update(['started_at' => $t, 'created_at' => $t]);
        DB::table('agent_run_steps')->where('agent_run_id', $run->id)->update(['created_at' => $t]);
    }

    public function test_chang_dang_chay_suy_tu_buoc_cuoi(): void
    {
        $cases = [
            [[], 'guard'], [['guard'], 'risk'], [['guard', 'risk'], 'brief'], [['guard', 'risk', 'brief'], 'write'],
            [['guard', 'risk', 'brief', 'write'], 'grade'],
            [['guard', 'risk', 'brief', 'write', ['grade', 'ok']], 'handoff'],
            [['guard', 'risk', 'brief', 'write', ['grade', 'blocked']], 'repair'],
            [['guard', 'risk', 'brief', 'write', ['grade', 'blocked'], 'repair'], 'grade'],
            [['guard', 'risk', 'brief', ['retry', 'warn']], 'write'],
            // AI thử lại/chuyển model lúc LẬP DÀN Ý: vẫn là chặng dàn ý, không phải Viết.
            [['guard', 'risk', ['retry', 'warn'], ['retry', 'warn'], ['failover', 'warn']], 'brief'],
            [['guard', 'risk', 'brief', 'research', ['retry', 'warn']], 'write'],
            [['guard', 'risk', 'brief', 'write', ['grade', 'blocked'], ['retry', 'warn']], 'repair'],
            [['guard', 'risk', 'brief', 'write', 'grade', 'handoff'], null],
        ];
        foreach ($cases as [$stages, $expect]) {
            $run = $this->makeRun('running', $stages);
            $this->assertSame($expect, RunActivity::currentStage($run->steps), json_encode($stages));
        }
    }

    public function test_trang_chi_tiet_poll_va_pulse_khi_dang_chay_va_dung_khi_xong(): void
    {
        $this->actingAs($this->user('approver'));
        $run = $this->makeRun('running', ['guard', 'risk', 'brief']);

        $html = $this->get(AgentRunResource::getUrl('view', ['record' => $run]))->assertOk()->getContent();
        $this->assertStringContainsString('wire:poll.2s', $html);
        $this->assertStringContainsString('data-live-stage="write"', $html);
        $this->assertStringContainsString('Đang ở:', $html);

        $run->update(['status' => 'ready_for_review']);
        $html = $this->get(AgentRunResource::getUrl('view', ['record' => $run]))->assertOk()->getContent();
        $this->assertStringNotContainsString('wire:poll.2s', $html);
        $this->assertStringNotContainsString('data-live-stage', $html);
        $this->assertStringNotContainsString('data-testid="run-live"', $html);
    }

    public function test_phien_treo_khong_quay_vo_han(): void
    {
        $this->actingAs($this->user('approver'));
        $run = $this->makeRun('running', ['guard', 'risk']);
        $this->age($run, 20);
        $run = $run->refresh();

        $this->assertTrue(RunActivity::isStale($run));
        $this->assertFalse(RunActivity::isLive($run));
        $this->assertSame(0, RunActivity::liveCount());

        $html = $this->get(AgentRunResource::getUrl('view', ['record' => $run]))->assertOk()->getContent();
        $this->assertStringContainsString('Có thể bị treo', $html);
        $this->assertStringNotContainsString('wire:poll.2s', $html);
        $this->assertStringNotContainsString('data-live-stage', $html);
        $this->assertStringNotContainsString('data-testid="run-live"', $html);
    }

    public function test_danh_sach_phien_poll_cham_khi_ranh_va_nhanh_co_spinner_khi_chay(): void
    {
        $this->actingAs($this->user('approver'));
        $done = $this->makeRun('ready_for_review', ['guard']);
        $html = $this->get(AgentRunResource::getUrl('index'))->assertOk()->getContent();
        $this->assertStringContainsString('15s', $html);
        $this->assertStringNotContainsString('data-running', $html);

        $this->makeRun('running', ['guard']);
        $html = $this->get(AgentRunResource::getUrl('index'))->assertOk()->getContent();
        $this->assertStringContainsString('3s', $html);
        $this->assertStringContainsString('ss-badge-live', $html);
        $this->assertStringContainsString('Đang ở: Phân hạng rủi ro', $html);
        Livewire::test(ListAgentRuns::class)->assertSuccessful();
    }

    public function test_tong_quan_banner_va_poll(): void
    {
        $this->actingAs($this->user('approver'));
        $url = \App\Filament\Clusters\AgentTeam\Pages\AgentOverview::getUrl();

        $html = $this->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('wire:poll.10s', $html);
        $this->assertStringNotContainsString('overview-live', $html);
        $this->assertStringNotContainsString('data-live-run', $html);

        $run = $this->makeRun('running', ['guard', 'risk', 'brief']);
        $html = $this->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('wire:poll.2s', $html);
        $this->assertStringContainsString('Đang chạy 1 phiên', $html);
        // Mỗi phiên đang chạy một dòng, ghi chặng hiện tại (không còn lưới chặng gộp mọi phiên).
        $this->assertStringContainsString('data-live-run="'.$run->id.'"', $html);
        $this->assertStringContainsString('Viết', $html);
    }

    public function test_pill_toan_cuc_chi_cho_approver_va_chi_khi_co_phien(): void
    {
        Livewire::actingAs($this->user('approver'))->test(AgentRunningPill::class)
            ->assertDontSee('Đội AI đang chạy');

        $this->makeRun('running', ['guard']);
        Livewire::actingAs($this->user('approver'))->test(AgentRunningPill::class)
            ->assertSee('Đội AI đang chạy (1)');
        Livewire::actingAs($this->user('writer'))->test(AgentRunningPill::class)
            ->assertDontSee('Đội AI đang chạy');

        $html = $this->actingAs($this->user('approver'))->get(AgentRunResource::getUrl('index'))->getContent();
        $this->assertStringContainsString('agent-running-pill', $html);
        $html = $this->actingAs($this->user('writer'))->get('/admin')->getContent();
        $this->assertStringNotContainsString('agent-running-pill', $html);
    }

    public function test_css_ton_trong_reduced_motion(): void
    {
        $css = file_get_contents(public_path('css/seo-studio.css'));
        $this->assertMatchesRegularExpression('/@media \(prefers-reduced-motion: reduce\)\s*\{[^}]*\.ss-spin\s*\{\s*animation:\s*none/s', $css);
        $this->assertStringContainsString('.ss-stage-live { animation: none; }', $css);
    }
}
