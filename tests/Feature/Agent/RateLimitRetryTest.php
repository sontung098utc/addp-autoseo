<?php

namespace Tests\Feature\Agent;

use App\Models\Article;
use App\Models\Website;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Services\Agent\Run\RunPolicy;
use App\Services\Agent\Run\TransientAiError;
use App\Services\Ai\AiQuotaExceeded;
use App\Services\Ai\AiRateGate;
use App\Services\Ai\GeminiAiProvider;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** 429/503: đọc Retry-After, backoff mũ có nhiễu, thử nhiều lần hơn trong trần, phân biệt hạn mức ngày, cooldown dùng chung. */
class RateLimitRetryTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-key-not-real';

    protected function setUp(): void
    {
        parent::setUp();
        app(EmergencyStopService::class)->resetForTesting();
        Cache::flush();
        AiRateGate::$sleeper = fn (float $s) => null; // test không chờ thật
    }

    protected function tearDown(): void
    {
        AiRateGate::$sleeper = null;
        parent::tearDown();
    }

    private function article(): Article
    {
        $w = Website::query()->first() ?? Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);

        return Article::create(['website_id' => $w->id, 'primary_keyword' => 'kw', 'workflow_status' => 'planned']);
    }

    private function ok(array $payload): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => json_encode($payload)]]]]], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 20, 'totalTokenCount' => 30]];
    }

    private function g(): GeminiAiProvider
    {
        return new GeminiAiProvider(self::KEY, 'gemini-3.5-flash-lite');
    }

    public function test_doc_retry_after_dang_giay_va_ngay_gio(): void
    {
        Http::fake(['*' => Http::sequence()->push([], 429, ['Retry-After' => '17'])->push([], 503, ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() + 30)])]);
        foreach ([[17, 17], [28, 31]] as [$lo, $hi]) {
            try {
                $this->g()->generateOutline($this->article());
                $this->fail();
            } catch (TransientAiError $e) {
                $this->assertGreaterThanOrEqual($lo, $e->retryAfter);
                $this->assertLessThanOrEqual($hi, $e->retryAfter);
            }
        }
    }

    public function test_doc_retry_delay_cua_google_trong_body(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'details' => [['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => '21.4s']]]], 429)]);
        try {
            $this->g()->generateOutline($this->article());
            $this->fail();
        } catch (TransientAiError $e) {
            $this->assertSame(22, $e->retryAfter);
            $this->assertSame(429, $e->status);
        }
    }

    public function test_het_han_muc_ngay_khong_thu_lai_va_bao_ro(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'details' => [['@type' => 'type.googleapis.com/google.rpc.QuotaFailure', 'violations' => [['quotaId' => 'GenerateRequestsPerDayPerProjectPerModel-FreeTier']]]]]], 429)]);
        $slept = [];
        $run = (new AgentRunOrchestrator($this->g(), sleep: function (int $s) use (&$slept) {
            $slept[] = $s;
        }))->run($this->article());

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('hạn mức theo ngày', $run->outcome_reason);
        $this->assertSame([], $slept, 'hạn mức ngày không được chờ/thử lại');
        Http::assertSentCount(1);
        $this->expectException(AiQuotaExceeded::class);
        $this->g()->generateOutline($this->article());
    }

    public function test_thu_lai_nhieu_lan_hon_khi_co_retry_after_va_cho_it_nhat_bang_retry_after(): void
    {
        // 5 lần 429 kèm Retry-After rồi thành công: trước đây hết 3 lần thử là bỏ cuộc.
        $seq = Http::sequence();
        for ($i = 0; $i < 5; $i++) {
            $seq->push([], 429, ['Retry-After' => '3']);
        }
        $seq->push($this->ok(['outline' => ['X']]));
        Http::fake(['*' => $seq]);
        $slept = [];
        $o = new AgentRunOrchestrator($this->g(), policy: new RunPolicy(maxRetries: 3), sleep: function (int $s) use (&$slept) {
            $slept[] = $s;
        });
        $run = $o->run($this->article());

        $retries = $run->steps()->where('stage', 'retry')->get();
        $this->assertCount(5, $retries);
        $this->assertSame('Thử lại lần 1/6: lập dàn ý', $retries[0]->title);
        foreach ($slept as $s) {
            $this->assertGreaterThanOrEqual(3, $s);
        }
        $this->assertNotSame('Nhà cung cấp AI lỗi tạm thời, đã hết 3 lần thử lại.', (string) $run->outcome_reason);
    }

    public function test_tran_tong_thoi_gian_cho_dung_lai(): void
    {
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => '80'])]);
        $slept = [];
        $run = (new AgentRunOrchestrator($this->g(), policy: new RunPolicy(retryBudgetSeconds: 100), sleep: function (int $s) use (&$slept) {
            $slept[] = $s;
        }))->run($this->article());

        $this->assertSame('failed', $run->status);
        $this->assertLessThanOrEqual(100, array_sum($slept), 'tổng chờ vượt trần ngân sách thời gian');
        $this->assertCount(1, $slept);
    }

    public function test_loi_khong_co_goi_y_giu_so_lan_cu_va_bac_thang_cu(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        $slept = [];
        $run = (new AgentRunOrchestrator($this->g(), sleep: function (int $s) use (&$slept) {
            $slept[] = $s;
        }))->run($this->article());

        $this->assertSame('failed', $run->status);
        $this->assertSame([2, 5, 12], $slept);
    }

    public function test_cooldown_dung_chung_giua_tien_trinh_qua_cache(): void
    {
        $naps = [];
        AiRateGate::$sleeper = function (float $s) use (&$naps) {
            $naps[] = $s;
        };
        Http::fake(['*' => Http::sequence()->push([], 429, ['Retry-After' => '20'])->push($this->ok(['outline' => ['X']]))]);
        try {
            $this->g()->generateOutline($this->article());
        } catch (TransientAiError) {
        }
        // Một "tiến trình" khác (provider mới cùng model) phải chờ phần cooldown còn lại trước khi gọi.
        $this->g()->generateOutline($this->article());
        $this->assertNotEmpty($naps);
        $this->assertGreaterThan(15, max($naps));
        $this->assertLessThanOrEqual(20, max($naps));
    }

    public function test_gian_cach_toi_thieu_theo_model(): void
    {
        config(['ai_rate.per_model' => ['gemini-3.5-flash-lite' => 5.0]]);
        $naps = [];
        AiRateGate::$sleeper = function (float $s) use (&$naps) {
            $naps[] = $s;
        };
        Http::fake(['*' => Http::response($this->ok(['outline' => ['X']]))]);
        $a = $this->article();
        $this->g()->generateOutline($a);
        $this->g()->generateOutline($a);

        $this->assertCount(1, $naps, 'lần đầu không chờ, lần hai phải chờ');
        $this->assertGreaterThan(3, $naps[0]);
        $this->assertLessThanOrEqual(5, $naps[0]);
    }
}
