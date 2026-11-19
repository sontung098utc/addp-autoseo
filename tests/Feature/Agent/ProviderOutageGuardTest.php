<?php

namespace Tests\Feature\Agent;

use App\Contracts\AiProviderInterface;
use App\Contracts\StructuredAiInterface;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\Campaign;
use App\Models\Website;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Services\Agent\Run\AiCircuitBreaker;
use App\Services\Agent\Run\AiFailureReason;
use App\Services\Agent\Run\CampaignRunner;
use App\Services\Agent\Run\TransientAiError;
use App\Services\Ai\AiProviderError;
use App\Services\Ai\AiQuotaExceeded;
use App\Services\Ai\AiSchemaError;
use App\Services\Ai\DemoScriptedAiProvider;
use App\Services\Publishing\EmergencyStopService;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * #36: bốn phiên "Viết bài" lỗi nhà cung cấp AI sau khi đã tốn tiền nghiên cứu và ảnh. Kiểm: ảnh chờ bản viết thành công, ngắt mạch, phân loại lý do thật,
 * Cache hỏng không làm hỏng phiên, bài lỗi/bài hoãn không bị nhặt lại sai. Nhà cung cấp GIẢ tất định (mức bằng chứng: test giả lập; chưa thử AI thật).
 */
class ProviderOutageGuardTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('public');
        app(EmergencyStopService::class)->resetForTesting();
        $this->site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(EmergencyStopService::class)->resetForTesting();
        parent::tearDown();
    }

    private function article(string $kw = 'dịch vụ thiết kế website'): Article
    {
        return Article::create(['website_id' => $this->site->id, 'primary_keyword' => $kw, 'workflow_status' => 'planned']);
    }

    /** Nhà cung cấp giả: mọi bước như bản Demo, riêng lượt viết ném $writeError (hoặc đếm lần gọi). */
    private function ai(?\Throwable $writeError): object
    {
        return new class($writeError) implements AiProviderInterface, StructuredAiInterface {
            public int $writes = 0;

            private DemoScriptedAiProvider $demo;

            public function __construct(private ?\Throwable $err)
            {
                $this->demo = new DemoScriptedAiProvider;
            }

            public function generateOutline(Article $article, array $options = []): array
            {
                return $this->demo->generateOutline($article, $options);
            }

            public function generateArticle(Article $article, array $outline, array $options = []): array
            {
                $this->writes++;
                if ($this->err) {
                    throw $this->err;
                }

                return $this->demo->generateArticle($article, $outline, $options);
            }

            public function calculateCostVnd(int $promptTokens, int $completionTokens, string $model): float
            {
                return 0.0;
            }

            public function generateStructured(string $system, string $user): array
            {
                return $this->demo->generateStructured($system, $user);
            }
        };
    }

    private function runWith(object $ai, ?Article $a = null): AgentRun
    {
        $this->app->detectEnvironment(fn () => 'local'); // chế độ Demo: có ảnh giả lập
        $r = (new AgentRunOrchestrator($ai, sleep: fn (int $s) => null))->run($a ?? $this->article(), null, 'manual');

        return $r->load('steps');
    }

    private function http(int $code, string $cls = ServerException::class): \Throwable
    {
        return new $cls('http key=AIzaSyD4mmyKeyForTestOnly1234567890abcd', new Request('POST', 'https://x.test/v1?key=AIzaSyD4mmyKeyForTestOnly1234567890abcd'), new Response($code));
    }

    // ---- (1) Ảnh chờ bản viết ----

    public function test_viet_loi_thi_khong_tao_anh_va_khong_ton_tien_anh(): void
    {
        $ai = $this->ai($this->http(503));
        $run = $this->runWith($ai);

        $this->assertSame('failed', $run->status);
        $this->assertSame(4, $ai->writes);
        $this->assertNull($run->steps->firstWhere('stage', 'images'), 'không có bước tạo ảnh nào');
        $this->assertSame(0, \App\Models\MediaAsset::count(), 'không có ảnh nào được tạo');
        $this->assertNotEmpty($run->steps->firstWhere('stage', 'brief')->data['image_plan']['plan']['slots'], 'kế hoạch ảnh vẫn lập (rẻ)');
        $this->assertStringContainsString('Chưa tạo ảnh', (string) $run->outcome_reason);
        $this->assertStringNotContainsString('TransientAiError', $run->outcome_reason.json_encode($run->steps->toArray(), JSON_UNESCAPED_UNICODE));
    }

    public function test_viet_thanh_cong_thi_van_tao_anh_nhu_cu(): void
    {
        $run = $this->runWith($this->ai(null));

        $images = $run->steps->firstWhere('stage', 'images');
        $this->assertNotNull($images);
        $this->assertGreaterThan(0, $images->data['generated']);
        $this->assertLessThan($run->steps->firstWhere('stage', 'write')->seq, $images->seq, 'ảnh bắt đầu ngay khi bản viết đầu có phản hồi');
        $this->assertGreaterThan(0, \App\Models\MediaAsset::count());
    }

    // ---- (2) Ngắt mạch ----

    public function test_ngat_mach_mo_sau_3_loi_dong_sau_5_phut_hoac_sau_thanh_cong(): void
    {
        Carbon::setTestNow('2026-11-19 00:00:00');
        $k = AiCircuitBreaker::key(1, 'gpt-x');
        AiCircuitBreaker::failure($k);
        AiCircuitBreaker::failure($k);
        $this->assertSame(0, AiCircuitBreaker::openFor($k), '2 lỗi chưa mở');
        AiCircuitBreaker::failure($k);
        $this->assertGreaterThan(0, AiCircuitBreaker::openFor($k));
        $this->assertSame(0, AiCircuitBreaker::openFor(AiCircuitBreaker::key(1, 'model-khac')), 'khóa theo (provider, model)');

        Carbon::setTestNow('2026-11-19 00:04:00');
        $this->assertGreaterThan(0, AiCircuitBreaker::openFor($k));
        Carbon::setTestNow('2026-11-19 00:05:01');
        $this->assertSame(0, AiCircuitBreaker::openFor($k), 'đóng sau 5 phút');

        Carbon::setTestNow('2026-11-19 01:00:00');
        foreach (range(1, 3) as $_) {
            AiCircuitBreaker::failure($k);
        }
        $this->assertGreaterThan(0, AiCircuitBreaker::openFor($k));
        AiCircuitBreaker::success($k);
        $this->assertSame(0, AiCircuitBreaker::openFor($k), 'một lần thành công đóng ngay');
    }

    public function test_loi_cach_nhau_qua_10_phut_khong_tinh_la_lien_tiep(): void
    {
        Carbon::setTestNow('2026-11-19 00:00:00');
        $k = AiCircuitBreaker::key(1, 'm');
        AiCircuitBreaker::failure($k);
        AiCircuitBreaker::failure($k);
        Carbon::setTestNow('2026-11-19 00:11:00');
        AiCircuitBreaker::failure($k);
        $this->assertSame(0, AiCircuitBreaker::openFor($k));
    }

    public function test_phien_loi_lien_tiep_mo_mach_roi_phien_sau_bi_hoan_khong_ton_tien_va_khong_loi(): void
    {
        $ai = $this->ai($this->http(503));
        $first = $this->runWith($ai);
        $this->assertSame('failed', $first->status);

        $a = $this->article('bài thứ hai');
        $writesBefore = $ai->writes;
        $second = $this->runWith($ai, $a);
        $this->assertSame('blocked', $second->status);
        $this->assertStringStartsWith(AgentRunOrchestrator::DEFERRED_PREFIX, (string) $second->outcome_reason);
        $this->assertSame($writesBefore, $ai->writes, 'không gọi AI viết');
        $this->assertSame(0.0, (float) $second->cost_vnd, 'không tốn đồng nào');
        $this->assertNull($second->steps->firstWhere('stage', 'research'));
        $this->assertNull($second->steps->firstWhere('stage', 'images'));
        $this->assertSame('planned', $a->refresh()->workflow_status, 'bài không bị đánh dấu lỗi');
        $this->assertTrue($second->steps->contains(fn ($st) => (bool) (((array) $st->data)['deferred'] ?? false)));
    }

    public function test_phien_thanh_cong_dong_ngat_mach(): void
    {
        $ai = $this->ai(null);
        $key = AiCircuitBreaker::key(null, 'default:'.get_class($ai));
        foreach (range(1, 2) as $_) {
            AiCircuitBreaker::failure($key); // 2 lỗi cũ, chưa mở
        }
        $this->assertSame('ready_for_review', $this->runWith($ai)->status);
        AiCircuitBreaker::failure($key);
        $this->assertSame(0, AiCircuitBreaker::openFor($key), 'thành công đã xóa chuỗi lỗi nên 1 lỗi mới chưa mở');
    }

    public function test_cache_loi_khong_lam_hong_phien_va_bo_qua_ngat_mach(): void
    {
        // Chỉ khóa của ngắt mạch bị lỗi quyền (các phần khác của hệ thống như dừng khẩn cấp dùng Cache theo cách riêng của chúng).
        Cache::extend('boom', fn () => Cache::repository(new class extends \Illuminate\Cache\ArrayStore {
            public function get($key)
            {
                return str_starts_with((string) $key, 'ai-breaker') ? throw new \RuntimeException('Permission denied: storage/framework/cache') : parent::get($key);
            }

            public function put($key, $value, $seconds)
            {
                return str_starts_with((string) $key, 'ai-breaker') ? throw new \RuntimeException('Permission denied: storage/framework/cache') : parent::put($key, $value, $seconds);
            }

            public function forget($key)
            {
                return str_starts_with((string) $key, 'ai-breaker') ? throw new \RuntimeException('Permission denied: storage/framework/cache') : parent::forget($key);
            }
        }));
        config(['cache.stores.boom' => ['driver' => 'boom'], 'cache.default' => 'boom']);
        app('cache')->forgetDriver();
        AiCircuitBreaker::failure('k');
        AiCircuitBreaker::success('k');
        $this->assertSame(0, AiCircuitBreaker::openFor('k'));

        $run = $this->runWith($this->ai(null));
        $this->assertSame('ready_for_review', $run->status, 'Cache hỏng: phiên vẫn chạy bình thường: '.$run->outcome_reason.' '.json_encode($run->steps->pluck('detail', 'stage')));
    }

    // ---- (3) Phân loại lý do ----

    public function test_phan_loai_ly_do_that_co_ma_http_va_goi_y(): void
    {
        $conn = fn (string $m) => new TransientAiError('Không kết nối được tới nhà cung cấp AI (timeout hoặc mất mạng).', 0, new ConnectionException($m));
        $cases = [
            [AiQuotaExceeded::withStatus('x', 429), AiFailureReason::QUOTA, 'hết hạn mức (HTTP 429)'],
            [TransientAiError::withHint('x', 429, 5), AiFailureReason::QUOTA, 'hết hạn mức (HTTP 429)'],
            [AiProviderError::withStatus('x', 401), AiFailureReason::AUTH, '(HTTP 401)'],
            [AiProviderError::withStatus('x', 403), AiFailureReason::AUTH, '(HTTP 403)'],
            [AiProviderError::withStatus('x', 404), AiFailureReason::MODEL_MISSING, '(HTTP 404)'],
            [$conn('cURL error 28: Operation timed out after 60001 milliseconds'), AiFailureReason::TIMEOUT, 'hết thời gian chờ'],
            [$this->http(504), AiFailureReason::TIMEOUT, '(HTTP 504)'],
            [$conn('cURL error 6: Could not resolve host: api.openai.com'), AiFailureReason::NETWORK, 'mạng hoặc DNS'],
            [TransientAiError::withHint('x', 503, null), AiFailureReason::SERVER, '(HTTP 503)'],
            [$this->http(500), AiFailureReason::SERVER, '(HTTP 500)'],
            [new AiSchemaError('Phản hồi AI thiếu "html_content"'), AiFailureReason::BAD_FORMAT, 'sai định dạng'],
            [AiProviderError::withStatus('x', 400), AiFailureReason::REJECTED, '(HTTP 400)'],
            [new \LogicException('lỗi lập trình'), AiFailureReason::UNKNOWN, 'chưa phân loại'],
        ];
        foreach ($cases as [$e, $code, $needle]) {
            $r = AiFailureReason::of($e);
            $this->assertSame($code, $r->code, get_class($e));
            $this->assertStringContainsString($needle, $r->label);
            $this->assertNotSame('', $r->hint);
            $this->assertStringNotContainsString('Exception', $r->summary());
            $this->assertStringNotContainsString('TransientAiError', $r->summary());
            $this->assertStringNotContainsString('AIza', $r->summary());
            $this->assertStringNotContainsString('api.openai.com', $r->summary());
        }
        $this->assertSame('Nhà cung cấp AI từ chối vì hết hạn mức (HTTP 429)', AiFailureReason::of(AiQuotaExceeded::withStatus('x', 429))->label);
    }

    public function test_ly_do_that_hien_o_phien_va_dien_bien_khi_het_lan_thu(): void
    {
        $run = $this->runWith($this->ai($this->http(429, ClientException::class)));

        $this->assertSame('failed', $run->status);
        $all = (string) $run->outcome_reason.json_encode($run->steps->toArray(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('hết hạn mức (HTTP 429)', (string) $run->outcome_reason);
        $this->assertStringContainsString('Chờ hạn mức đặt lại', (string) $run->outcome_reason, 'có gợi ý việc nên làm');
        $this->assertStringContainsString('hết hạn mức', $run->steps->firstWhere('stage', 'retry')->detail);
        $this->assertStringNotContainsString('TransientAiError', $all);
        $this->assertStringNotContainsString('AiRetriesExhausted', $all);
        $this->assertStringNotContainsString('AIzaSyD4mmyKeyForTestOnly1234567890abcd', $all);
    }

    public function test_loi_xac_thuc_hien_ly_do_that_va_khong_thu_lai(): void
    {
        $ai = $this->ai(AiProviderError::withStatus('Khóa API bị từ chối (HTTP 401). Kiểm tra lại khóa hoặc quyền của khóa.', 401));
        $run = $this->runWith($ai);

        $this->assertSame(1, $ai->writes);
        $this->assertStringContainsString('từ chối khóa API (HTTP 401)', (string) $run->outcome_reason);
        $this->assertNull($run->steps->firstWhere('stage', 'images'));
    }

    // ---- (4) Bài lỗi / bài hoãn không bị nhặt lại sai ----

    private function campaign(): Campaign
    {
        return Campaign::create(['website_id' => $this->site->id, 'name' => 'CD', 'status' => 'active', 'source' => 'plan', 'daily_limit' => 10, 'keywords' => []]);
    }

    private function planned(Campaign $c, string $kw): Article
    {
        return Article::create(['website_id' => $this->site->id, 'primary_keyword' => $kw, 'workflow_status' => 'planned', 'campaign_id' => $c->id]);
    }

    public function test_bai_that_bai_vi_nha_cung_cap_loi_khong_bi_nhat_lai(): void
    {
        $c = $this->campaign();
        $a = $this->planned($c, 'bài lỗi');
        AgentRun::create(['article_id' => $a->id, 'website_id' => $this->site->id, 'campaign_id' => $c->id, 'status' => 'failed', 'trigger' => 'schedule', 'started_at' => now()->subHour(), 'finished_at' => now()->subHour(), 'ai_mode' => 'real',
            'outcome_reason' => 'Nhà cung cấp AI lỗi tạm thời, đã thử 4 lần']);
        $a->update(['workflow_status' => 'planned']); // bộ dọn trả bài về kế hoạch

        $runner = new CampaignRunner(fn () => null);
        $this->assertNull($runner->nextPlanned($c), 'bài đã có phiên lỗi không được nhặt lại (không tốn thêm tiền trong ngày)');
        $this->assertNull($runner->nextRetryable($c));
    }

    public function test_bai_bi_hoan_chi_duoc_nhat_lai_sau_thoi_gian_cho_va_khong_tinh_vao_gioi_han_ngay(): void
    {
        Carbon::setTestNow('2026-11-19 09:00:00');
        $c = $this->campaign();
        $a = $this->planned($c, 'bài hoãn');
        AgentRun::create(['article_id' => $a->id, 'website_id' => $this->site->id, 'campaign_id' => $c->id, 'status' => 'blocked', 'trigger' => 'schedule', 'started_at' => now(), 'finished_at' => now(), 'ai_mode' => 'real',
            'outcome_reason' => AgentRunOrchestrator::DEFERRED_PREFIX.' (x).']);
        $runner = new CampaignRunner(fn () => null);

        $this->assertNull($runner->nextPlanned($c), 'vừa hoãn xong thì chưa nhặt lại');
        Carbon::setTestNow('2026-11-19 09:07:00');
        $this->assertSame($a->id, $runner->nextPlanned($c)?->id, 'qua thời gian chờ thì được thử lại');

        // chiến dịch từ khóa nhập tay: bài đã tồn tại nên nextKeyword bỏ qua, nextRetryable nhặt lại
        AgentRun::create(['article_id' => $a->id, 'website_id' => $this->site->id, 'campaign_id' => $c->id, 'status' => 'failed', 'trigger' => 'schedule', 'started_at' => now(), 'finished_at' => now(), 'ai_mode' => 'real']); // lần thử lại của bài đầu đã lỗi thật
        $b = $this->planned($c, 'bài hoãn từ khóa');
        AgentRun::create(['article_id' => $b->id, 'website_id' => $this->site->id, 'campaign_id' => $c->id, 'status' => 'blocked', 'trigger' => 'schedule', 'started_at' => now()->subMinutes(30), 'finished_at' => now()->subMinutes(30), 'ai_mode' => 'real',
            'outcome_reason' => AgentRunOrchestrator::DEFERRED_PREFIX.' (x).']);
        $this->assertSame($b->id, $runner->nextRetryable($c)?->id, 'bài hoãn từ khóa nhập tay được nhặt lại; bài đã lỗi thật thì không');
    }

    public function test_nhip_chien_dich_khong_giao_bai_khi_ngat_mach_mo_va_khong_danh_dau_loi(): void
    {
        $c = $this->campaign();
        $a = $this->planned($c, 'bài chờ');
        $provider = \App\Models\AiProvider::create(['name' => 'Chatgpt', 'kind' => 'openai_compat', 'base_url' => 'https://api.openai.com/v1', 'api_key' => 'sk-test', 'is_active' => true]);
        \App\Models\AiModelRoute::create(['task' => 'write', 'primary_provider_id' => $provider->id, 'primary_model' => 'gpt-test']);
        foreach (range(1, 3) as $_) {
            AiCircuitBreaker::failure(AiCircuitBreaker::key($provider->id, 'gpt-test'));
        }

        $res = (new CampaignRunner(fn () => new DemoScriptedAiProvider))->tick($c);

        $this->assertSame('deferred', $res['result']);
        $this->assertStringContainsString('Hoãn: nhà cung cấp AI đang lỗi', $res['message']);
        $this->assertSame(0, AgentRun::count(), 'không tạo phiên, không đốt tiền');
        $this->assertSame('planned', $a->refresh()->workflow_status);
        $this->assertNull((new CampaignRunner(fn () => null))->continueAfter($c, ['result' => 'ran', 'message' => '', 'run' => new AgentRun(['status' => 'blocked', 'outcome_reason' => AgentRunOrchestrator::DEFERRED_PREFIX.' x'])]));
    }
}
