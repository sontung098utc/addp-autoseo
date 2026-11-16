<?php

namespace Tests\Feature\Agent;

use App\Models\Article;
use App\Models\Website;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Services\Ai\GeminiAiProvider;
use App\Services\Ai\MockAiProvider;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Token vào/ra từng bước được ghi riêng (kể cả token suy luận tính vào đầu ra) và chi phí khớp phép tính từ hai số đó. */
class TokenSplitTest extends TestCase
{
    use RefreshDatabase;

    private function article(): Article
    {
        app(EmergencyStopService::class)->resetForTesting();
        $w = Website::query()->first() ?? Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);

        return Article::create(['website_id' => $w->id, 'primary_keyword' => 'nguyên lý máy dập viên', 'workflow_status' => 'planned']);
    }

    public function test_migration_co_cot_tach_token(): void
    {
        $this->assertTrue(Schema::hasColumns('agent_run_steps', ['prompt_tokens', 'completion_tokens']));
    }

    public function test_buoc_ghi_token_vao_ra_rieng_va_chi_phi_khop(): void
    {
        $usage = ['promptTokenCount' => 1000, 'candidatesTokenCount' => 400, 'thoughtsTokenCount' => 100, 'totalTokenCount' => 1500];
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['outline' => ['A', 'B', 'C']])]]]]], 'usageMetadata' => $usage])]);
        $ai = new GeminiAiProvider('k', 'gemini-3.5-flash-lite');
        $r = $ai->generateOutline($this->article());

        $this->assertSame(1000, $r['prompt_tokens']);
        $this->assertSame(500, $r['completion_tokens'], 'token suy luận tính như đầu ra');

        $run = (new AgentRunOrchestrator($ai))->run($this->article());
        $brief = $run->steps()->where('stage', 'brief')->first();
        $this->assertSame(1000, $brief->prompt_tokens);
        $this->assertSame(500, $brief->completion_tokens);
        $this->assertSame($brief->tokens_used, $brief->prompt_tokens + $brief->completion_tokens);
        $this->assertArrayNotHasKey('prompt_tokens', (array) $brief->data, 'không lưu trùng trong JSON');
        // 1000 vào × 0,30 + 500 ra × 2,50 USD/1M × 25.500 đ
        $this->assertEqualsWithDelta((1000 * 0.30 + 500 * 2.50) / 1_000_000 * 25500, (float) $brief->cost_vnd, 0.05);
    }

    public function test_ai_gia_lap_khong_bao_thi_de_null(): void
    {
        $run = (new AgentRunOrchestrator(new MockAiProvider))->run($this->article());
        $brief = $run->steps()->where('stage', 'brief')->first();

        $this->assertNull($brief->prompt_tokens);
        $this->assertNull($brief->completion_tokens);
        $this->assertGreaterThan(0, $brief->tokens_used);
    }

    public function test_moi_truong_chua_chay_migration_tach_token_van_chay_duoc(): void
    {
        Schema::table('agent_run_steps', fn ($t) => $t->dropColumn(['prompt_tokens', 'completion_tokens']));
        $ref = new \ReflectionProperty(AgentRunOrchestrator::class, 'tokenSplit');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        $run = (new AgentRunOrchestrator(new MockAiProvider))->run($this->article());

        $this->assertNotSame('failed', $run->status, (string) $run->outcome_reason);
        $this->assertGreaterThan(0, $run->steps()->where('stage', 'brief')->first()->tokens_used);
    }
}
