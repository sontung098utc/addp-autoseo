<?php

namespace Tests\Feature\Images;

use App\Models\AiModelRoute;
use App\Models\AiProvider;
use App\Services\Images\CustomHttpImageGenerator;
use App\Services\Images\Http\HttpApiSpec;
use App\Services\Images\Http\HttpTemplate;
use App\Services\Images\ImageGenerationError;
use App\Services\Images\ImageGeneratorResolver;
use App\Services\Images\ImageGeneratorUnavailable;
use App\Services\Images\MockImageGenerator;
use App\Services\Security\UrlSafetyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * API tạo ảnh tùy chỉnh (custom_http). Chỉ dùng Http::fake: chứng minh logic, KHÔNG chứng minh chạy được với dịch vụ thật
 * (mẫu sangtao.ai viết theo tài liệu công khai, chưa thử với khóa thật).
 */
class CustomHttpImageGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'sk_live_TESTKEY1234567890abcdef';

    private string $png;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        $this->png = (new MockImageGenerator)->generate('x')['bytes'];
        // Không phân giải DNS thật cho tên miền giả trong test (luật SSRF thật được kiểm ở test riêng bên dưới).
        $this->mock(UrlSafetyService::class, function ($m) {
            $m->shouldReceive('validateAiBaseUrl')->andReturn(true);
            // tên miền giả: bỏ qua DNS; địa chỉ IP thẳng: dùng luật thật để chứng minh chặn mạng nội bộ
            $m->shouldReceive('validateUrl')->andReturnUsing(fn (string $u) => filter_var(parse_url($u, PHP_URL_HOST), FILTER_VALIDATE_IP) ? (new UrlSafetyService)->validateUrl($u) : true);
        });
    }

    private function sangtao(array $over = []): CustomHttpImageGenerator
    {
        $spec = HttpApiSpec::normalize(array_replace_recursive(HttpApiSpec::preset('sangtao'), $over));

        return new CustomHttpImageGenerator(self::KEY, 'gpt-image-2.6', 'https://api.sangtao.test/v2', $spec, 900.0);
    }

    public function test_sync_openai_style_sends_bearer_and_reads_base64(): void
    {
        Http::fake(['api.example.test/*' => Http::response(['data' => [['b64_json' => base64_encode($this->png)]]])]);
        $g = new CustomHttpImageGenerator(self::KEY, 'm1', 'https://api.example.test/v1', HttpApiSpec::normalize(HttpApiSpec::preset('openai_images')), 500.0);
        $r = $g->generate('một quả táo', ['aspect' => '1:1']);

        $this->assertSame('image/png', $r['mime']);
        $this->assertSame(500.0, $r['cost_vnd']);
        Http::assertSent(fn ($q) => $q->url() === 'https://api.example.test/v1/images/generations'
            && $q->hasHeader('Authorization', 'Bearer '.self::KEY)
            && $q['model'] === 'm1' && $q['prompt'] === 'một quả táo' && $q['size'] === '1024x1024' && $q['n'] === 1);
    }

    public function test_async_job_uses_custom_header_polls_and_downloads_without_key(): void
    {
        Http::fake([
            'api.sangtao.test/v2/images/generations' => Http::response(['jobId' => 'job/1', 'status' => 'WaitingForAgent']),
            'api.sangtao.test/v2/images/generations/*' => Http::sequence()
                ->push(['status' => 'Processing'])
                ->push(['status' => 'COMPLETE', 'resultUrl' => 'https://cdn.example.test/a.png']),
            'cdn.example.test/*' => Http::response($this->png, 200, ['Content-Type' => 'image/png']),
        ]);
        $r = $this->sangtao()->generate('một quả táo', ['aspect' => '16:9']);

        $this->assertSame('image/png', $r['mime']);
        Http::assertSent(fn ($q) => $q->method() === 'POST' && $q->hasHeader('X-Api-Key', self::KEY) && ! $q->hasHeader('Authorization')
            && $q['aspectRatio'] === '16:9' && $q['model'] === 'gpt-image-2.6' && ! empty($q['idempotencyKey']));
        Http::assertSent(fn ($q) => $q->method() === 'GET' && $q->url() === 'https://api.sangtao.test/v2/images/generations/job%2F1' && $q->hasHeader('X-Api-Key', self::KEY));
        Http::assertSent(fn ($q) => str_starts_with($q->url(), 'https://cdn.example.test/') && ! $q->hasHeader('X-Api-Key') && ! $q->hasHeader('Authorization'));
        Sleep::assertSleptTimes(2);
    }

    public function test_401_gives_clear_message_without_leaking_key(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Missing or invalid API key '.self::KEY], 401)]);
        $g = new CustomHttpImageGenerator(self::KEY, 'm', 'https://api.example.test/v1', HttpApiSpec::normalize(HttpApiSpec::preset('openai_images')), 1.0);
        try {
            $g->generate('p');
            $this->fail('phải ném lỗi');
        } catch (ImageGenerationError $e) {
            $this->assertSame(401, $e->status);
            $this->assertStringContainsString('X-Api-Key', $e->getMessage()); // gợi ý đúng chỗ hay sai của API không dùng Bearer
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
        }
    }

    public function test_async_timeout_reports_job_id_and_never_resubmits(): void
    {
        Http::fake([
            'api.sangtao.test/v2/images/generations' => Http::response(['jobId' => 'J9']),
            'api.sangtao.test/v2/images/generations/*' => Http::response(['status' => 'Processing']),
        ]);
        try {
            $this->sangtao(['async' => ['timeout_s' => 10, 'interval_s' => 4]])->generate('p');
            $this->fail('phải ném lỗi');
        } catch (ImageGenerationError $e) {
            $this->assertStringContainsString('J9', $e->getMessage());
            $this->assertStringContainsString('không tự gửi lại', $e->getMessage());
        }
        Http::assertSentCount(1 + 3); // đúng 1 lần gửi job, còn lại là hỏi trạng thái
        Http::assertSent(fn ($q) => $q->method() === 'POST');
    }

    public function test_failed_job_status_is_reported(): void
    {
        Http::fake([
            'api.sangtao.test/v2/images/generations' => Http::response(['jobId' => 'J2']),
            'api.sangtao.test/v2/images/generations/*' => Http::response(['status' => 'error']),
        ]);
        $this->expectException(ImageGenerationError::class);
        $this->expectExceptionMessage('thất bại');
        $this->sangtao()->generate('p');
    }

    public function test_result_url_to_internal_address_is_blocked_by_ssrf_rule(): void
    {
        Http::fake([
            'api.sangtao.test/v2/images/generations' => Http::response(['jobId' => 'J3']),
            'api.sangtao.test/v2/images/generations/*' => Http::response(['status' => 'complete', 'resultUrl' => 'http://169.254.169.254/latest/meta-data']),
        ]);
        try {
            $this->sangtao()->generate('p');
            $this->fail('phải ném lỗi');
        } catch (ImageGenerationError $e) {
            $this->assertStringContainsString('không an toàn', $e->getMessage());
        }
        Http::assertNotSent(fn ($q) => str_contains($q->url(), '169.254.169.254'));
    }

    public function test_download_that_is_not_an_image_is_rejected(): void
    {
        Http::fake([
            'api.sangtao.test/v2/images/generations' => Http::response(['jobId' => 'J4']),
            'api.sangtao.test/v2/images/generations/*' => Http::response(['status' => 'complete', 'resultUrl' => 'https://cdn.example.test/x']),
            'cdn.example.test/*' => Http::response('<html>không phải ảnh</html>'),
        ]);
        $this->expectException(ImageGenerationError::class);
        $this->expectExceptionMessage('không phải ảnh');
        $this->sangtao()->generate('p');
    }

    public function test_ping_does_not_generate_and_distinguishes_bad_key(): void
    {
        Http::fake(['api.sangtao.test/v2/models' => Http::sequence()->push(['models' => []])->push([], 401)]);
        $this->assertTrue($this->sangtao()->ping()['ok']);
        $bad = $this->sangtao()->ping();
        $this->assertFalse($bad['ok']);
        $this->assertStringContainsString('401', $bad['message']);
        Http::assertNotSent(fn ($q) => str_contains($q->url(), 'images/generations'));
    }

    public function test_ping_without_health_path_says_so_instead_of_pretending_ok(): void
    {
        $spec = HttpApiSpec::preset('openai_images');
        unset($spec['health']);
        $g = new CustomHttpImageGenerator(self::KEY, 'm', 'https://api.example.test/v1', HttpApiSpec::normalize($spec), 1.0);
        $r = $g->ping();
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('health.path', $r['message']);
    }

    public function test_list_models_autodetects_list_key_and_top_level_array(): void
    {
        Http::fake(['api.sangtao.test/v2/models*' => Http::sequence()
            ->push(['models' => [['slug' => 'gpt-image-2.6', 'displayName' => 'GPT Image 2.6']]])
            ->push([['slug' => 'a'], ['slug' => 'b', 'displayName' => 'B']])]);
        $this->assertSame([['id' => 'gpt-image-2.6', 'name' => 'GPT Image 2.6']], $this->sangtao()->listModels());
        $this->assertSame([['id' => 'a', 'name' => null], ['id' => 'b', 'name' => 'B']], $this->sangtao()->listModels());
    }

    public function test_spec_validation_rejects_unsafe_or_wrong_config(): void
    {
        $base = HttpApiSpec::preset('sangtao');
        $cases = [
            'path có tên miền' => ['submit' => ['path' => '//evil.test/x']],
            'path có ..' => ['submit' => ['path' => '/a/../b']],
            'chỗ trống lạ' => ['submit' => ['body' => ['x' => '{{secret}}']]],
            'poll thiếu job_id' => ['async' => ['poll_path' => '/jobs']],
            'auth lạ' => ['auth' => ['type' => 'cookie']],
            'header hệ thống' => ['auth' => ['type' => 'header', 'name' => 'Host']],
            'result sai' => ['result' => ['type' => 'file']],
        ];
        foreach ($cases as $label => $over) {
            try {
                HttpApiSpec::normalize(array_replace_recursive($base, $over));
                $this->fail("phải từ chối: {$label}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        HttpApiSpec::normalize('{không phải json');
    }

    public function test_template_renders_and_reads_paths(): void
    {
        $this->assertSame(['a' => 'x-1', 'b' => 3], HttpTemplate::render(['a' => 'x-{{n}}', 'b' => '{{w}}'], ['n' => 1, 'w' => 3]));
        $this->assertSame('v', HttpTemplate::get(['x' => ['0' => ['u' => 'v']]], 'nope|x.0.u'));
        $this->assertNull(HttpTemplate::get(['x' => 1], 'y.z'));
    }

    public function test_resolver_builds_custom_http_provider_and_reports_bad_config(): void
    {
        $p = AiProvider::create(['name' => 'S', 'kind' => 'custom_http', 'base_url' => 'https://api.sangtao.test/v2', 'api_key' => self::KEY, 'enabled' => true,
            'http_config' => HttpApiSpec::preset('sangtao'), 'pricing' => ['image_vnd' => 750]]);
        AiModelRoute::create(['task' => 'image_gen', 'tier' => 'cheap', 'primary_provider_id' => $p->id, 'primary_model' => 'gpt-image-2.6']);
        $gen = ImageGeneratorResolver::resolve()['generator'];
        $this->assertInstanceOf(CustomHttpImageGenerator::class, $gen);
        $this->assertSame(750.0, $gen->estimatedCostVnd());

        $p->update(['http_config' => ['mode' => 'async']]);
        $this->assertStringContainsString('cấu hình API tùy chỉnh không hợp lệ', (string) ImageGeneratorResolver::why());
        $this->expectException(ImageGeneratorUnavailable::class);
        CustomHttpImageGenerator::fromProvider($p->fresh(), 'm');
    }
}
