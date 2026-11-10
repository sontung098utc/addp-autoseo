<?php

namespace Tests\Feature\Ai;

use App\Contracts\PartialRefreshInterface;
use App\Models\AiModelRoute;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Website;
use App\Services\Ai\AiSchemaError;
use App\Services\Ai\DemoScriptedAiProvider;
use App\Services\Ai\MockAiProvider;
use App\Services\Ai\OpenAiCompatibleProvider;
use App\Services\Content\Quality\QualityThresholds as T;
use App\Services\Security\UrlSafetyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** G5: hai phương thức AI làm mới một phần (Http::fake; chưa gọi AI thật). */
class PartialRefreshTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'NOIDUNGMUCKHONGCHON';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(UrlSafetyService::class, new class extends UrlSafetyService
        {
            protected function resolveIps(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
    }

    private function article(): array
    {
        $w = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://s.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $a = Article::create(['website_id' => $w->id, 'primary_keyword' => 'học bổng', 'workflow_status' => 'planned']);
        $html = '<h1>Học bổng</h1><p>Mở đầu bài viết về học bổng.</p>'
            .'<h2>Mục không chọn A</h2><p>'.self::SECRET.' '.str_repeat('dài ', 300).'</p>'
            .'<h2>Mục cần sửa B</h2><p>Nội dung B lỗi.</p>'
            .'<h2>Mục không chọn C</h2><p>'.self::SECRET.' khác</p>';
        $v = new ArticleVersion(['article_id' => $a->id, 'title' => 'Tiêu đề cũ', 'seo_title' => 'SEO cũ', 'meta_description' => 'Meta cũ', 'html_content' => $html]);

        return [$a, $v];
    }

    private function fake(array $payload, int $in = 1000, int $out = 200): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => json_encode($payload, JSON_UNESCAPED_UNICODE)]]], 'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $out, 'total_tokens' => $in + $out]])]);
    }

    private function provider(): OpenAiCompatibleProvider
    {
        return new OpenAiCompatibleProvider('sk-test', 'm', 'https://api.example.com/v1');
    }

    private function sentBody(): string
    {
        $body = '';
        Http::assertSent(function (Request $r) use (&$body) {
            $body = json_encode($r->data(), JSON_UNESCAPED_UNICODE);

            return true;
        });

        return $body;
    }

    public function test_meta_chi_gui_phan_can_thiet_va_parse_dung(): void
    {
        [$a, $v] = $this->article();
        $meta = str_repeat('Mô tả hợp lệ ', 11);
        $this->fake(['seo_title' => 'Học bổng toàn phần: hướng dẫn', 'meta_description' => $meta]);
        $r = $this->provider()->regenerateMeta($a, $v, ['Meta quá ngắn']);

        $this->assertSame('Học bổng toàn phần: hướng dẫn', $r['seo_title']);
        $this->assertSame(trim($meta), $r['meta_description']);
        $this->assertSame(1200, $r['tokens_used']);
        $this->assertSame($this->provider()->calculateCostVnd(1000, 200, 'm'), $r['cost_vnd']);
        $this->assertSame(['m', 'v1.0-refresh-meta'], [$r['model'], $r['prompt_version']]);
        $body = $this->sentBody();
        $this->assertStringContainsString('Mục không chọn A', $body);
        $this->assertStringContainsString('Meta quá ngắn', $body);
        $this->assertStringContainsString('Mở đầu bài viết', $body);
        $this->assertStringNotContainsString(self::SECRET, $body);
        $this->assertStringNotContainsString('Nội dung B lỗi', $body);
    }

    public function test_meta_cat_khi_qua_dai_va_loi_khi_hong(): void
    {
        [$a, $v] = $this->article();
        $this->fake(['seo_title' => str_repeat('từ ', 40), 'meta_description' => str_repeat('mô tả ', 60)]);
        $r = $this->provider()->regenerateMeta($a, $v, []);
        $this->assertLessThanOrEqual(T::MAX_SEO_TITLE_CHARS, mb_strlen($r['seo_title']));
        $this->assertLessThanOrEqual(T::MAX_META_CHARS, mb_strlen($r['meta_description']));
        $this->assertGreaterThanOrEqual(T::MIN_META_CHARS, mb_strlen($r['meta_description']));

        foreach ([['seo_title' => '', 'meta_description' => str_repeat('a ', 70)], ['seo_title' => 'ok', 'meta_description' => 'quá ngắn'], ['foo' => 1]] as $bad) {
            $this->fake($bad);
            try {
                $this->provider()->regenerateMeta($a, $v, []);
                $this->fail('phải ném AiSchemaError');
            } catch (AiSchemaError) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_repair_chi_gui_muc_can_sua_va_bo_index_la(): void
    {
        [$a, $v] = $this->article();
        $this->fake(['sections' => [1 => '<h2>Mục cần sửa B</h2><p>Đã sửa.</p>', 99 => '<h2>Lạ</h2>']], 500, 100);
        $r = $this->provider()->repairSections($a, $v, [['index' => 1, 'heading' => 'Mục cần sửa B', 'html' => '<h2>Mục cần sửa B</h2><p>Nội dung B lỗi.</p>']], ['Thiếu từ khóa']);

        $this->assertSame([1], array_keys($r['sections']));
        $this->assertStringStartsWith('<h2>', $r['sections'][1]);
        $this->assertSame(600, $r['tokens_used']);
        $this->assertSame($this->provider()->calculateCostVnd(500, 100, 'm'), $r['cost_vnd']);
        $this->assertSame('v1.0-refresh-section', $r['prompt_version']);
        $body = $this->sentBody();
        $this->assertStringContainsString('Nội dung B lỗi', $body);
        $this->assertStringContainsString('Mục không chọn A', $body);
        $this->assertStringContainsString('Thiếu từ khóa', $body);
        $this->assertStringNotContainsString(self::SECRET, $body);
    }

    public function test_repair_loi_khi_khong_bat_dau_bang_h2_hoac_thieu_sections(): void
    {
        [$a, $v] = $this->article();
        $sec = [['index' => 1, 'heading' => 'B', 'html' => '<h2>B</h2>']];
        foreach ([['sections' => [1 => '<p>Không có h2</p>']], ['sections' => [99 => '<h2>x</h2>']], ['sections' => []], ['x' => 1]] as $bad) {
            $this->fake($bad);
            try {
                $this->provider()->repairSections($a, $v, $sec, []);
                $this->fail('phải ném AiSchemaError');
            } catch (AiSchemaError) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_mock_va_demo_tat_dinh_dung_cau_truc(): void
    {
        [$a, $v] = $this->article();
        $sec = [['index' => 1, 'heading' => 'Mục B', 'html' => '<h2>Mục B</h2>']];
        foreach ([[new MockAiProvider, 'mock'], [new DemoScriptedAiProvider, 'demo']] as [$p, $label]) {
            $this->assertInstanceOf(PartialRefreshInterface::class, $p);
            $m = $p->regenerateMeta($a, $v, ['x']);
            $this->assertSame($m, $p->regenerateMeta($a, $v, ['x']));
            $this->assertStringStartsWith($label, $m['model']);
            $this->assertLessThanOrEqual(T::MAX_SEO_TITLE_CHARS, mb_strlen($m['seo_title']));
            $this->assertGreaterThanOrEqual(T::MIN_META_CHARS, mb_strlen($m['meta_description']));
            $this->assertLessThanOrEqual(T::MAX_META_CHARS, mb_strlen($m['meta_description']));
            $s = $p->repairSections($a, $v, $sec, ['x']);
            $this->assertSame($s, $p->repairSections($a, $v, $sec, ['x']));
            $this->assertSame([1], array_keys($s['sections']));
            $this->assertStringStartsWith('<h2>', $s['sections'][1]);
            $this->assertStringStartsWith($label, $s['model']);
        }
    }

    public function test_hai_task_moi_co_trong_dinh_tuyen(): void
    {
        $this->assertSame('cheap', AiModelRoute::TASKS['meta_regen'][1]);
        $this->assertSame('standard', AiModelRoute::TASKS['section_repair'][1]);
    }
}
