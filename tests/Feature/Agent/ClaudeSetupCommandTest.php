<?php

namespace Tests\Feature\Agent;

use App\Models\AgentRoleAssignment;
use App\Models\AiProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Lệnh cài đặt Claude (bộ nối) làm model của đội AI. Bộ nối GIẢ (Http::fake). */
class ClaudeSetupCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $tokenFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokenFile = sys_get_temp_dir().'/cb-token-'.uniqid();
        file_put_contents($this->tokenFile, str_repeat('k', 40));
        config(['ai_models.private_base_url_allowlist' => ['172.17.0.1:8771']]);
        User::create(['name' => 'a', 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => 'admin', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        @unlink($this->tokenFile);
        parent::tearDown();
    }

    private function run_(array $opts = []): int
    {
        return Artisan::call('agent:claude-setup', ['--token-file' => $this->tokenFile] + $opts);
    }

    public function test_tao_nha_cung_cap_va_gan_sonnet_cho_hai_vai_tro_giu_model_du_phong(): void
    {
        $fb = AiProvider::create(['name' => 'Gemini', 'kind' => 'gemini', 'api_key' => 'x', 'enabled' => true]);
        AgentRoleAssignment::create(['role_key' => 'writer', 'provider_id' => $fb->id, 'model' => 'gemini-3.5-flash-lite', 'fallback_provider_id' => null, 'fallback_model' => null, 'enabled' => true]);
        AgentRoleAssignment::create(['role_key' => 'strategist', 'provider_id' => $fb->id, 'model' => 'gemini-3.5-flash-lite', 'fallback_provider_id' => $fb->id, 'fallback_model' => 'gemini-3.1-pro', 'enabled' => true]);

        $this->assertSame(0, $this->run_());
        $p = AiProvider::where('name', 'Claude CLI (cầu nối)')->first();
        $this->assertSame('openai_compat', $p->kind);
        $this->assertSame('http://172.17.0.1:8771/v1', $p->base_url);
        $this->assertSame(str_repeat('k', 40), $p->api_key);
        foreach (['writer', 'strategist'] as $r) {
            $a = AgentRoleAssignment::where('role_key', $r)->first();
            $this->assertSame($p->id, $a->provider_id, $r);
            $this->assertSame('sonnet', $a->model, $r);
        }
        $this->assertSame('gemini-3.1-pro', AgentRoleAssignment::where('role_key', 'strategist')->first()->fallback_model, 'Model dự phòng cũ phải được giữ.');
        $this->assertStringNotContainsString(str_repeat('k', 40), Artisan::output(), 'Khóa không được in ra.');
    }

    public function test_chay_lai_khong_sinh_trung_nha_cung_cap(): void
    {
        $this->assertSame(0, $this->run_());
        $this->assertSame(0, $this->run_(['--model' => 'opus']));
        $this->assertSame(1, AiProvider::where('name', 'Claude CLI (cầu nối)')->count());
        $this->assertSame('opus', AgentRoleAssignment::where('role_key', 'writer')->first()->model);
    }

    public function test_dry_run_khong_ghi_gi(): void
    {
        $this->assertSame(0, $this->run_(['--dry-run' => true]));
        $this->assertSame(0, AiProvider::count());
        $this->assertSame(0, AgentRoleAssignment::count());
    }

    public function test_thieu_khoa_hoac_dia_chi_khong_duoc_phep_thi_that_bai_co_huong_dan(): void
    {
        $this->assertSame(1, Artisan::call('agent:claude-setup', ['--token-file' => '/khong/co/tep']));
        $this->assertStringContainsString('claude_bridge.py', Artisan::output());

        $this->assertSame(1, $this->run_(['--base-url' => 'http://172.17.0.1:9999/v1']));
        $this->assertStringContainsString('AI_PRIVATE_BASE_URL_ALLOWLIST', Artisan::output());
        $this->assertSame(0, AiProvider::count());
    }

    public function test_test_that_bai_thi_khong_gan_vai_tro(): void
    {
        Http::fake(['172.17.0.1:8771/*' => Http::response(['error' => ['message' => 'x']], 502)]);
        $this->assertSame(1, $this->run_(['--test' => true]));
        $this->assertSame(0, AgentRoleAssignment::count());
    }
}
