<?php

namespace Tests\Feature\Agent;

use App\Filament\Pages\AiUsagePage;
use App\Models\AgentRun;
use App\Models\AgentSetting;
use App\Models\AiModelRoute;
use App\Models\AiProvider;
use App\Models\Article;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Ai\AiModelRouter;
use App\Services\Agent\Ai\RoutedAiProvider;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Services\Agent\Run\RunPolicy;
use App\Services\Agent\Run\TransientAiError;
use App\Services\Agent\Settings\AgentAi;
use App\Services\Ai\AiProviderError;
use App\Services\Ai\AiSchemaError;
use App\Services\Ai\AnthropicAiProvider;
use App\Services\Ai\GeminiAiProvider;
use App\Services\Ai\OpenAiCompatibleProvider;
use App\Services\Publishing\EmergencyStopService;
use App\Services\Security\UrlSafetyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Unit\QualityFixtures;

/** AG-S11: nhiều provider, định tuyến theo loại việc, dự phòng thật. Mọi lời gọi HTTP đều là Http::fake (mức bằng chứng: test giả lập; chưa thử API thật). */
class AiRoutingTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'sk-test-SECRETKEY-0123456789abcdef';

    private const KW = 'học bổng toàn phần';

    protected function setUp(): void
    {
        parent::setUp();
        app(EmergencyStopService::class)->resetForTesting();
        // Không phân giải DNS thật trong test: tên miền giả → một IP công cộng; IP literal vẫn đi qua bộ chặn nội bộ thật.
        $this->app->instance(UrlSafetyService::class, new class extends UrlSafetyService {
            protected function resolveIps(string $host): array
            {
                return filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ['93.184.216.34'];
            }
        });
        putenv('GOOGLE_AI_API_KEY');
        unset($_ENV['GOOGLE_AI_API_KEY'], $_SERVER['GOOGLE_AI_API_KEY']);
    }

    private function article(): Article
    {
        $w = Website::firstOrCreate(['base_url' => 'https://a.vn'], ['name' => 'S', 'cms_type' => 'wordpress', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);

        return Article::create(['website_id' => $w->id, 'primary_keyword' => self::KW, 'workflow_status' => 'planned']);
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function provider(string $kind = 'anthropic', array $o = []): AiProvider
    {
        return AiProvider::create($o + ['name' => 'P-'.$kind, 'kind' => $kind, 'api_key' => self::KEY, 'enabled' => true, 'base_url' => $kind === 'openai_compat' ? 'https://llm.example.com/v1' : null]);
    }

    private function route(string $task, ?AiProvider $p, string $model = 'm-main', array $o = []): AiModelRoute
    {
        return AiModelRoute::create($o + ['task' => $task, 'tier' => 'standard', 'primary_provider_id' => $p?->id, 'primary_model' => $model]);
    }

    private function anthropicBody(array $payload, int $in = 100, int $out = 200): array
    {
        return ['content' => [['type' => 'text', 'text' => json_encode($payload, JSON_UNESCAPED_UNICODE)]], 'usage' => ['input_tokens' => $in, 'output_tokens' => $out]];
    }

    private function openAiBody(array $payload): array
    {
        return ['choices' => [['message' => ['content' => json_encode($payload, JSON_UNESCAPED_UNICODE)]]], 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 80, 'total_tokens' => 130]];
    }

    private function good(): array
    {
        return QualityFixtures::goodAttrs(self::KW);
    }

    // ---------------- Adapter ----------------

    public function test_anthropic_thanh_cong_gui_dung_header_model_va_tinh_chi_phi(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->anthropicBody(['outline' => ['A', 'B']]))]);

        $r = (new AnthropicAiProvider(self::KEY, 'claude-sonnet-5-5'))->generateOutline($this->article());

        $this->assertSame(['A', 'B'], $r['outline']);
        $this->assertSame(300, $r['tokens_used']);
        $this->assertSame('claude-sonnet-5-5', $r['model']);
        $this->assertGreaterThan(0, $r['cost_vnd'], 'model không có trong bảng giá vẫn được tính (ước tính trên cao), không về 0');
        Http::assertSent(fn ($req) => $req->url() === 'https://api.anthropic.com/v1/messages' && $req->hasHeader('x-api-key', self::KEY) && $req->hasHeader('anthropic-version') && $req['model'] === 'claude-sonnet-5-5');
    }

    public function test_anthropic_viet_bai_tra_json_co_cau_truc_va_chap_nhan_khung_code(): void
    {
        $payload = $this->good();
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => "```json\n".json_encode($payload)."\n```"]], 'usage' => ['input_tokens' => 1, 'output_tokens' => 2]])]);

        $r = (new AnthropicAiProvider(self::KEY, 'claude-opus-5-5'))->generateArticle($this->article(), ['A']);

        $this->assertSame($payload['html_content'], $r['html_content']);
        $this->assertSame('claude-opus-5-5', $r['model']);
    }

    public function test_anthropic_401_la_loi_cung_khong_lo_khoa(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'invalid x-api-key '.self::KEY]], 401)]);

        try {
            (new AnthropicAiProvider(self::KEY, 'm'))->generateOutline($this->article());
            $this->fail('phải ném lỗi');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(AiProviderError::class, $e);
            $this->assertFalse(TransientAiError::is($e), 'khóa sai không được thử lại');
            $this->assertStringContainsString('401', $e->getMessage());
            $this->assertStringNotContainsString('SECRETKEY', $e->getMessage());
        }
    }

    public function test_429_va_5xx_va_mat_ket_noi_la_loi_tam_thoi(): void
    {
        foreach ([429, 500, 503] as $status) {
            Http::fake(['api.anthropic.com/*' => Http::response([], $status)]);
            try {
                (new AnthropicAiProvider(self::KEY, 'm'))->generateOutline($this->article());
                $this->fail('phải ném lỗi');
            } catch (\Throwable $e) {
                $this->assertInstanceOf(TransientAiError::class, $e, "HTTP {$status}");
                $this->assertTrue(TransientAiError::is($e));
            }
        }
        Http::fake(['api.anthropic.com/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28 for https://api.anthropic.com/?key='.self::KEY)]);
        try {
            (new AnthropicAiProvider(self::KEY, 'm'))->generateOutline($this->article());
            $this->fail('phải ném lỗi');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(TransientAiError::class, $e);
            $this->assertStringNotContainsString('SECRETKEY', $e->getMessage());
        }
    }

    public function test_schema_sai_thi_nem_loi_ro_khong_bia_noi_dung(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->anthropicBody(['khong_phai_outline' => 1]))
            ->push($this->anthropicBody(['title' => 'x']))
            ->push(['content' => [['type' => 'text', 'text' => 'không phải json']], 'usage' => []])]);
        $p = new AnthropicAiProvider(self::KEY, 'm');
        $a = $this->article();

        foreach ([fn () => $p->generateOutline($a), fn () => $p->generateArticle($a, ['A']), fn () => $p->generateOutline($a)] as $call) {
            try {
                $call();
                $this->fail('phải ném AiSchemaError');
            } catch (AiSchemaError $e) {
                $this->assertFalse(TransientAiError::is($e));
            }
        }
    }

    public function test_openai_tuong_thich_thanh_cong_dung_base_url_va_bearer(): void
    {
        Http::fake(['llm.example.com/*' => Http::response($this->openAiBody($this->good()))]);

        $r = (new OpenAiCompatibleProvider(self::KEY, 'ten-model-tuy-y', 'https://llm.example.com/v1'))->generateArticle($this->article(), ['A']);

        $this->assertSame(130, $r['tokens_used']);
        $this->assertSame('ten-model-tuy-y', $r['model']);
        Http::assertSent(fn ($req) => $req->url() === 'https://llm.example.com/v1/chat/completions' && $req->hasHeader('Authorization', 'Bearer '.self::KEY) && $req['model'] === 'ten-model-tuy-y');
    }

    public function test_openai_tuong_thich_loi_401_429_va_base_url_noi_bo(): void
    {
        Http::fake(['llm.example.com/*' => Http::sequence()->push([], 401)->push([], 429)]);
        $p = new OpenAiCompatibleProvider(self::KEY, 'm', 'https://llm.example.com/v1');
        $a = $this->article();
        try {
            $p->generateOutline($a);
            $this->fail();
        } catch (\Throwable $e) {
            $this->assertInstanceOf(AiProviderError::class, $e);
            $this->assertFalse(TransientAiError::is($e));
        }
        $this->expectException(TransientAiError::class);
        try {
            $p->generateOutline($a);
        } finally {
            Http::fake();
            foreach (['http://127.0.0.1/v1', 'http://169.254.169.254/v1', 'http://10.0.0.5/v1'] as $bad) {
                try {
                    (new OpenAiCompatibleProvider(self::KEY, 'm', $bad))->generateOutline($a);
                    $this->fail("phải chặn {$bad}");
                } catch (AiProviderError $e) {
                    $this->assertStringContainsString('không an toàn', $e->getMessage());
                }
            }
            Http::assertNothingSent();
        }
    }

    public function test_gemini_dung_http_khoa_trong_header_loi_429_va_json_hong_khong_roi_ve_mock(): void
    {
        $body = fn (array $payload) => ['candidates' => [['content' => ['parts' => [['text' => json_encode($payload)]]]]], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 20, 'totalTokenCount' => 30]];
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()->push($body(['outline' => ['X']]))->push([], 429)->push($body(['title' => 'thiếu html']))]);
        $g = new GeminiAiProvider(self::KEY, 'gemini-1.5-flash');
        $a = $this->article();

        $this->assertSame(['X'], $g->generateOutline($a)['outline']);
        Http::assertSent(fn ($req) => ! str_contains($req->url(), 'key=') && $req->hasHeader('x-goog-api-key', self::KEY));
        try {
            $g->generateOutline($a);
            $this->fail();
        } catch (TransientAiError $e) {
            $this->assertTrue(true);
        }
        $this->expectException(AiSchemaError::class);
        $g->generateArticle($a, ['A']);
    }

    public function test_ping_ok_va_loi_khong_lo_khoa(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()->push($this->anthropicBody(['ok' => true]))->push(['error' => self::KEY], 403)]);
        $p = new AnthropicAiProvider(self::KEY, 'claude-haiku-5-5');

        $this->assertTrue($p->ping()['ok']);
        $bad = $p->ping();
        $this->assertFalse($bad['ok']);
        $this->assertStringNotContainsString('SECRETKEY', $bad['message']);
        $this->assertFalse((new AnthropicAiProvider('', 'm'))->ping()['ok']);
    }

    // ---------------- Router ----------------

    public function test_router_chon_dung_route_theo_loai_viec(): void
    {
        $a = $this->provider('anthropic');
        $o = $this->provider('openai_compat');
        $this->route('outline', $a, 'claude-haiku-5-5');
        $this->route('write', $o, 'gpt-x', ['fallback_provider_id' => $a->id, 'fallback_model' => 'claude-sonnet-5-5', 'cost_cap_vnd_per_call' => 2000]);
        $r = new AiModelRouter();

        $this->assertTrue(AiModelRouter::isConfigured());
        $this->assertSame('claude-haiku-5-5', $r->target('outline')->model);
        $this->assertInstanceOf(AnthropicAiProvider::class, $r->target('outline')->ai);
        $w = $r->target('write');
        $this->assertSame('gpt-x', $w->model);
        $this->assertInstanceOf(OpenAiCompatibleProvider::class, $w->ai);
        $this->assertSame(2000.0, $w->costCapVnd);
        $this->assertSame('claude-sonnet-5-5', $r->fallback('write')->model);
        $this->assertNull($r->fallback('outline'));
    }

    public function test_thieu_route_thieu_khoa_hoac_tat_thi_null_khong_mock(): void
    {
        $noKey = $this->provider('anthropic', ['api_key' => null]);
        $off = $this->provider('gemini', ['enabled' => false]);
        $this->route('outline', $noKey);
        $this->route('write', $off);
        $r = new AiModelRouter();

        $this->assertNull($r->target('outline'));
        $this->assertStringContainsString('chưa có khóa', (string) $r->why('outline'));
        $this->assertNull($r->target('write'));
        $this->assertStringContainsString('đang tắt', (string) $r->why('write'));
        $this->assertNull($r->target('repair'), 'chưa có route');
        $this->assertStringContainsString('chưa được cấu hình', (string) $r->why('repair'));
        $this->assertNull(AgentAi::real());
        $this->assertFalse(AgentAi::status()['connected']);
        $this->assertSame(['outline', 'write', 'repair'], $r->missing(['outline', 'write', 'repair']));
    }

    public function test_du_phong_trung_model_chinh_bi_bo_qua(): void
    {
        $a = $this->provider('anthropic');
        $this->route('write', $a, 'm', ['fallback_provider_id' => $a->id, 'fallback_model' => 'm']);

        $this->assertNull((new AiModelRouter())->fallback('write'));
    }

    public function test_agentai_dung_router_khi_da_co_route_va_giu_duong_cu_khi_chua_co(): void
    {
        $this->assertNull(AgentAi::real());
        AgentSetting::create(['provider' => 'gemini', 'api_key' => 'AIza-DB-1234', 'model' => 'gemini-1.5-pro']);
        $this->assertInstanceOf(GeminiAiProvider::class, AgentAi::real(), 'cấu hình cũ vẫn chạy như Gemini mặc định');
        $this->assertTrue(AgentAi::status()['connected']);

        $p = $this->provider('anthropic');
        $this->route('outline', $p, 'claude-haiku-5-5');
        $this->route('write', $p, 'claude-sonnet-5-5');
        $this->assertInstanceOf(RoutedAiProvider::class, AgentAi::real());
        $st = AgentAi::status();
        $this->assertSame('router', $st['source']);
        $this->assertSame('claude-sonnet-5-5', $st['model']);
        $this->assertSame('AIza-DB-1234', AgentSetting::current()->api_key, 'khóa cũ không bị mất');
    }

    // ---------------- Orchestrator + router ----------------

    private function runWith(?array $policy = null): array
    {
        $slept = [];
        $run = (new AgentRunOrchestrator(new RoutedAiProvider(), policy: new RunPolicy(...($policy ?? [])), sleep: function (int $s) use (&$slept) { $slept[] = $s; }))->run($this->article(), null);

        return [$run->load('steps'), $slept];
    }

    public function test_moi_buoc_dung_dung_model_cua_route_va_ghi_provider(): void
    {
        $a = $this->provider('anthropic');
        $this->route('outline', $a, 'claude-haiku-5-5');
        $this->route('write', $a, 'claude-opus-5-5');
        $this->route('repair', $a, 'claude-sonnet-5-5');
        Http::fake(['api.anthropic.com/*' => fn ($req) => Http::response($req['model'] === 'claude-haiku-5-5' ? $this->anthropicBody(['outline' => ['A', 'B', 'C']]) : $this->anthropicBody($this->good()))]);

        [$run] = $this->runWith();

        $this->assertSame('real', $run->ai_mode);
        $this->assertSame('ready_for_review', $run->status, (string) $run->outcome_reason);
        $this->assertSame('claude-haiku-5-5', $run->steps->firstWhere('stage', 'brief')->model);
        $this->assertSame('claude-opus-5-5', $run->steps->firstWhere('stage', 'write')->model);
        $this->assertSame($a->id, $run->steps->firstWhere('stage', 'write')->data['provider_id']);
        $this->assertGreaterThan(0, (float) $run->cost_vnd);
    }

    public function test_thieu_khoa_thi_phien_bi_chan_khong_goi_ai_va_khong_dung_mock(): void
    {
        $a = $this->provider('anthropic', ['api_key' => null]);
        $this->route('outline', $a);
        $this->route('write', $a);
        Http::fake();

        [$run] = $this->runWith();

        $this->assertSame('blocked', $run->status);
        $this->assertSame('real', $run->ai_mode, 'không bị gắn nhãn mock/không rơi về mock');
        $this->assertStringContainsString('chưa có khóa', $run->outcome_reason);
        $this->assertSame('blocked', $run->steps->where('stage', 'guard')->last()->status);
        $this->assertNull($run->final_version_id);
        Http::assertNothingSent();
    }

    public function test_du_phong_chuyen_sang_model_that_khac_va_ghi_buoc_failover(): void
    {
        $a = $this->provider('anthropic');
        $o = $this->provider('openai_compat', ['name' => 'Máy chủ B']);
        $this->route('outline', $a, 'claude-haiku-5-5');
        $this->route('write', $a, 'claude-opus-5-5', ['fallback_provider_id' => $o->id, 'fallback_model' => 'model-b']);
        $this->route('repair', $a, 'claude-sonnet-5-5');
        Http::fake([
            'api.anthropic.com/*' => fn ($req) => $req['model'] === 'claude-haiku-5-5' ? Http::response($this->anthropicBody(['outline' => ['A']])) : Http::response([], 503),
            'llm.example.com/*' => Http::response($this->openAiBody($this->good())),
        ]);

        [$run, $slept] = $this->runWith();

        $fo = $run->steps->firstWhere('stage', 'failover');
        $this->assertNotNull($fo, 'phải có bước failover trong nhật ký');
        $this->assertStringContainsString('Đã chuyển từ', $fo->detail);
        $this->assertStringContainsString('claude-opus-5-5', $fo->detail);
        $this->assertStringContainsString('model-b', $fo->detail);
        $this->assertStringContainsString('503', $fo->detail);
        $this->assertSame('model-b', $fo->data['to']['model']);
        $this->assertSame('model-b', $run->steps->firstWhere('stage', 'write')->model, 'bài được viết bởi model dự phòng');
        $this->assertSame($o->id, $run->steps->firstWhere('stage', 'write')->data['provider_id']);
        $this->assertCount(3, $run->steps->where('stage', 'retry'), 'thử lại hết lượt ở model chính trước khi chuyển');
        $this->assertSame([2, 5, 12], $slept);
        $this->assertSame('ready_for_review', $run->status, (string) $run->outcome_reason);
    }

    public function test_khong_co_du_phong_thi_het_luot_la_failed_khong_mock(): void
    {
        $a = $this->provider('anthropic');
        $this->route('outline', $a, 'm1');
        $this->route('write', $a, 'm2');
        Http::fake(['api.anthropic.com/*' => fn ($req) => $req['model'] === 'm1' ? Http::response($this->anthropicBody(['outline' => ['A']])) : Http::response([], 429)]);

        [$run] = $this->runWith();

        $this->assertSame('failed', $run->status);
        $this->assertNull($run->steps->firstWhere('stage', 'failover'));
        $this->assertSame('real', $run->ai_mode);
        $this->assertNull($run->final_version_id);
    }

    public function test_du_phong_cung_loi_thi_failed(): void
    {
        $a = $this->provider('anthropic');
        $o = $this->provider('openai_compat');
        $this->route('outline', $a, 'm1');
        $this->route('write', $a, 'm2', ['fallback_provider_id' => $o->id, 'fallback_model' => 'm3']);
        Http::fake([
            'api.anthropic.com/*' => fn ($req) => $req['model'] === 'm1' ? Http::response($this->anthropicBody(['outline' => ['A']])) : Http::response([], 500),
            'llm.example.com/*' => Http::response([], 500),
        ]);

        [$run] = $this->runWith(['maxRetries' => 1]);

        $this->assertSame('failed', $run->status);
        $this->assertNotNull($run->steps->firstWhere('stage', 'failover'));
        $this->assertNull($run->final_version_id);
    }

    public function test_vuot_tran_chi_phi_thi_dung_va_ghi_quyet_dinh(): void
    {
        $a = $this->provider('anthropic');
        $this->route('outline', $a, 'm1');
        $this->route('write', $a, 'm2', ['cost_cap_vnd_per_call' => 0.01]);
        Http::fake(['api.anthropic.com/*' => fn ($req) => Http::response($req['model'] === 'm1' ? $this->anthropicBody(['outline' => ['A']]) : $this->anthropicBody($this->good(), 5000, 8000))]);

        [$run] = $this->runWith();

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('vượt trần', $run->outcome_reason);
        $d = $run->steps->first(fn ($s) => ($s->data['decision'] ?? null) === 'cost_cap_exceeded');
        $this->assertNotNull($d);
        $this->assertSame(0.01, (float) $d->data['cap_vnd']);
        $this->assertGreaterThan(0.01, (float) $d->data['cost_vnd']);
        $this->assertGreaterThan(0, (float) $run->cost_vnd, 'chi phí đã phát sinh vẫn tính vào phiên');
        $this->assertNull($run->final_version_id);
    }

    public function test_loi_khoa_401_dung_phien_khong_chuyen_du_phong_va_khong_lo_khoa(): void
    {
        $a = $this->provider('anthropic');
        $o = $this->provider('openai_compat');
        $this->route('outline', $a, 'm1', ['fallback_provider_id' => $o->id, 'fallback_model' => 'm9']);
        $this->route('write', $a, 'm2');
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => self::KEY], 401)]);

        [$run] = $this->runWith();

        $this->assertSame('failed', $run->status);
        $this->assertNull($run->steps->firstWhere('stage', 'failover'), 'lỗi cứng không đổi model');
        $this->assertStringNotContainsString('SECRETKEY', json_encode([$run->toArray(), $run->steps->toArray()]));
    }

    public function test_khong_co_router_van_chay_nhu_cu(): void
    {
        $ai = new \App\Services\Ai\DemoScriptedAiProvider();
        $run = (new AgentRunOrchestrator($ai))->run($this->article(), null);

        $this->assertSame('mock', $run->ai_mode);
        $this->assertNull($run->steps->firstWhere('stage', 'failover'));
    }

    // ---------------- Giao diện & quyền ----------------

    public function test_them_provider_ma_hoa_khoa_goi_y_route_va_khong_lo_trong_html(): void
    {
        $this->actingAs($this->user('admin'));
        Livewire::withQueryParams(['tab' => 'cau-hinh'])->test(AiUsagePage::class)
            ->callAction('addProvider', ['name' => 'Claude', 'preset' => 'anthropic', 'api_key' => self::KEY, 'monthly_budget_vnd' => 3000000, 'suggest' => true])
            ->assertHasNoActionErrors();

        $this->assertStringNotContainsString('SECRETKEY', (string) \DB::table('ai_providers')->value('api_key'));
        $this->assertSame('claude-opus-5-5', AiModelRoute::where('task', 'write')->value('primary_model'));
        $this->assertSame('claude-haiku-5-5', AiModelRoute::where('task', 'outline')->value('primary_model'));
        $html = $this->get('/admin/chi-phi-ai?tab=cau-hinh')->assertOk()->assertSee('Claude')->assertSee('•••• đã lưu')->assertDontSee('Định tuyến theo loại việc')->assertSee('Hệ thống không tự dùng Mock thay thế')->getContent();
        $this->assertStringNotContainsString('SECRETKEY', $html);
        $this->assertStringNotContainsString(self::KEY, $html);
    }

    public function test_kiem_tra_ket_noi_ghi_trang_thai_va_khong_lo_khoa(): void
    {
        $p = $this->provider('anthropic');
        $this->route('write', $p, 'claude-sonnet-5-5');
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => self::KEY], 401)]);
        $this->actingAs($this->user('admin'));

        Livewire::withQueryParams(['tab' => 'cau-hinh'])->test(AiUsagePage::class)->callAction('testProvider', [], ['id' => $p->id]);

        $p->refresh();
        $this->assertFalse($p->last_test_ok);
        $this->assertStringNotContainsString('SECRETKEY', (string) $p->last_test_message);
        $this->get('/admin/chi-phi-ai?tab=cau-hinh')->assertOk()->assertDontSee('SECRETKEY');
    }

    public function test_trang_cau_hinh_ai_khong_con_bang_dinh_tuyen_hay_gan_vai_tro(): void
    {
        $p = $this->provider('anthropic');
        $this->route('write', $p);
        $this->actingAs($this->user('admin'));
        $this->get('/admin/chi-phi-ai?tab=cau-hinh')->assertOk()->assertDontSee('Định tuyến theo loại việc')->assertDontSee('Model chính')->assertDontSee('Trần chi phí');
    }

    public function test_base_url_noi_bo_bi_tu_choi_khi_them_provider(): void
    {
        $this->actingAs($this->user('admin'));

        Livewire::withQueryParams(['tab' => 'cau-hinh'])->test(AiUsagePage::class)
            ->callAction('addProvider', ['name' => 'Bad', 'preset' => 'custom', 'base_url' => 'http://127.0.0.1:8000/v1', 'api_key' => self::KEY, 'suggest' => false])
            ->assertHasActionErrors(['base_url']);
        $this->assertSame(0, AiProvider::count());
    }

    public function test_bien_tap_vien_bi_chan_khoi_trang_tich_hop_ai(): void
    {
        $this->actingAs($this->user('writer'));
        $this->get('/admin/chi-phi-ai?tab=cau-hinh')->assertForbidden();
        Livewire::withQueryParams(['tab' => 'cau-hinh'])->test(AiUsagePage::class)->assertForbidden();
    }

    public function test_quyen_nguoi_duyet_xem_khong_sua(): void
    {
        $p = $this->provider('anthropic');
        $this->route('write', $p);

        $this->actingAs($this->user('approver'));
        $this->get('/admin/chi-phi-ai?tab=cau-hinh')->assertOk()->assertSee('P-anthropic')->assertDontSee('Sửa khóa');
        Livewire::withQueryParams(['tab' => 'cau-hinh'])->test(AiUsagePage::class)
            ->assertActionHidden('addProvider')->assertActionHidden('testProvider', ['id' => $p->id])
            ->call('mountAction', 'addProvider')->assertSet('mountedActions', []);
        try {
            Livewire::withQueryParams(['tab' => 'cau-hinh'])->test(AiUsagePage::class)->callAction('addProvider', ['name' => 'X', 'preset' => 'gemini', 'api_key' => 'k']);
            $this->fail('hành động ẩn phải bị từ chối');
        } catch (\PHPUnit\Framework\ExpectationFailedException) {
            // đúng: người duyệt không thực hiện được
        }
        $this->assertSame(1, AiProvider::count());
        $this->assertSame(self::KEY, $p->fresh()->api_key);
    }
}
