<?php

namespace Tests\Feature\Images;

use App\Contracts\ImageGeneratorInterface;
use App\Filament\Support\Article\ImageGalleryData;
use App\Models\AgentRun;
use App\Models\AiModelRoute;
use App\Models\AiProvider;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Brain;
use App\Models\BrandProfile;
use App\Models\MediaAsset;
use App\Models\Website;
use App\Services\Images\ArticleImageService;
use App\Services\Images\ArticleImageStep;
use App\Services\Images\GeminiImageGenerator;
use App\Services\Images\ImageGenerationError;
use App\Services\Images\ImageGeneratorResolver;
use App\Services\Images\ImagePromptBuilder;
use App\Services\Images\MockImageGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Tạo ảnh: nhà cung cấp giả/Http::fake, không gọi mạng thật, chưa đo chi phí ảnh thật. */
class ArticleImageTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function make(string $kw = 'cách trồng rau sạch', int $h2 = 4): array
    {
        $a = Article::create(['website_id' => $this->site->id, 'primary_keyword' => $kw, 'workflow_status' => 'planned']);
        $html = '<p>Mở đầu</p>';
        for ($i = 1; $i <= $h2; $i++) {
            $html .= "<h2>Mục số {$i}</h2><p>Nội dung {$i}</p>";
        }
        $v = ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 'T', 'slug' => 't-'.uniqid(), 'html_content' => $html]);

        return [$a, $v];
    }

    private function svc(?ImageGeneratorInterface $g = null): ArticleImageService
    {
        return new ArticleImageService($g ?? new MockImageGenerator);
    }

    private function libraryCover(ArticleVersion $v): void
    {
        $m = MediaAsset::create(['website_id' => $this->site->id, 'disk' => 'public', 'file_path' => 'media/x.webp', 'file_name' => 'x.webp', 'original_name' => 'x.webp', 'mime_type' => 'image/webp', 'file_size' => 1, 'sha256' => str_repeat('a', 64), 'title' => 'x', 'alt_text' => 'x']);
        $v->mediaAssets()->attach($m->id, ['is_featured' => true, 'position' => 0]);
    }

    public function test_library_cover_means_no_generation(): void
    {
        [$a, $v] = $this->make();
        $this->libraryCover($v);
        $r = $this->svc()->generateFor($a, $v);
        $this->assertSame(0, $r['generated']);
        $this->assertSame('library_cover', $r['skip_code']);
        $this->assertSame(1, MediaAsset::count());
    }

    public function test_ai_images_are_optimized_to_webp_and_capped_in_width(): void
    {
        $im = imagecreatetruecolor(1920, 1080);
        for ($i = 0; $i < 400; $i++) {
            imagefilledrectangle($im, random_int(0, 1900), random_int(0, 1060), random_int(0, 1900), random_int(0, 1060), imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        }
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        $g = new class($png) implements ImageGeneratorInterface
        {
            public function __construct(private string $png) {}

            public function generate(string $prompt, array $options = []): array
            {
                return ['bytes' => $this->png, 'mime' => 'image/png', 'model' => 'm', 'cost_vnd' => 1.0, 'prompt' => $prompt, 'revised_prompt' => null];
            }

            public function estimatedCostVnd(): float
            {
                return 1.0;
            }
        };
        [$a, $v] = $this->make();
        $this->svc($g)->generateFor($a, $v);

        $asset = MediaAsset::query()->latest('id')->first();
        $this->assertSame('image/webp', $asset->mime_type);
        $this->assertLessThanOrEqual(1200, $asset->width);
        $this->assertSame(1200, $asset->width, 'ảnh 1920 px bị thu về 1200 px');
        $this->assertLessThan(150_000, $asset->file_size);
    }

    public function test_generates_cover_plus_two_inline_by_default(): void
    {
        [$a, $v] = $this->make();
        $r = $this->svc()->generateFor($a, $v);
        $this->assertSame(3, $r['generated']);
        $this->assertSame(['featured', 'inline', 'inline'], array_column($r['items'], 'role'));
        $this->assertSame(3, $v->mediaAssets()->count());
        $this->assertSame(1, $v->mediaAssets()->wherePivot('is_featured', true)->count());
        $this->assertSame(2, substr_count($v->fresh()->html_content, 'data-ai-image="1"'));
        $this->assertStringContainsString('alt="Hình minh họa mục', $v->fresh()->html_content);
    }

    public function test_hard_cap_is_four_images_total(): void
    {
        [$a, $v] = $this->make(h2: 8);
        $r = $this->svc()->generateFor($a, $v, ['max_inline' => 10]);
        $this->assertSame(4, $r['generated']);
    }

    public function test_fewer_h2_than_max_and_faq_heading_skipped(): void
    {
        $a = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'rau sạch', 'workflow_status' => 'planned']);
        $v = ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 'T', 'slug' => 't-'.uniqid(), 'html_content' => '<p>a</p><h2>Một mục</h2><p>x</p><h2>Câu hỏi thường gặp</h2><p>y</p>']);
        $r = $this->svc()->generateFor($a, $v);
        $this->assertSame(2, $r['generated']);
    }

    public function test_media_asset_valid_and_marked_ai(): void
    {
        [$a, $v] = $this->make();
        $this->svc()->generateFor($a, $v);
        $asset = MediaAsset::first();
        $this->assertSame('image/webp', $asset->mime_type); // MediaService luôn nén sang webp
        $this->assertSame('image/png', $asset->metadata['original_mime']);
        $this->assertTrue($asset->metadata['ai_generated']);
        $this->assertSame('mock-image', $asset->metadata['model']);
        $this->assertNotEmpty($asset->alt_text);
        $this->assertGreaterThan(0, $asset->file_size);
        Storage::disk('public')->assertExists($asset->file_path);
    }

    public function test_rerun_does_not_duplicate_unless_regenerate(): void
    {
        [$a, $v] = $this->make();
        $this->svc()->generateFor($a, $v);
        $again = $this->svc()->generateFor($a, $v);
        $this->assertSame('already_generated', $again['skip_code']);
        $this->assertSame(3, $v->mediaAssets()->count());
        $re = $this->svc()->generateFor($a, $v->fresh(), ['regenerate' => true]);
        $this->assertSame(3, $re['generated']);
        $this->assertSame(3, $v->mediaAssets()->count());
        $this->assertSame(2, substr_count($v->fresh()->html_content, 'data-ai-image="1"'));
    }

    public function test_cost_cap_stops_midway(): void
    {
        [$a, $v] = $this->make();
        $g = new class implements ImageGeneratorInterface
        {
            public function generate(string $prompt, array $options = []): array
            {
                return ['cost_vnd' => 1000.0] + (new MockImageGenerator)->generate($prompt, $options);
            }

            public function estimatedCostVnd(): float
            {
                return 1000.0;
            }
        };
        // ngân sách 15.000đ → trần ảnh 1.500đ → chỉ vừa 1 ảnh
        $r = $this->svc($g)->generateFor($a, $v, ['budget_vnd' => 15000.0]);
        $this->assertSame(1, $r['generated']);
        $this->assertSame('cost_cap', $r['stopped']);
        $this->assertSame(1000.0, $r['cost_vnd']);
    }

    public function test_no_provider_configured_skips_with_reason(): void
    {
        [$a, $v] = $this->make();
        $r = (new ArticleImageService)->generateFor($a, $v);
        $this->assertSame(0, $r['generated']);
        $this->assertSame('no_provider', $r['skip_code']);
        $this->assertStringContainsString('Chưa cấu hình', $r['skipped']);
        $this->assertSame(0, MediaAsset::count());
    }

    public function test_thuat_ngu_y_te_moi_va_tri_tue_rui_ro_cao_deu_bi_coi_la_suc_khoe(): void
    {
        config(['agent.images.medical' => false]); // nhận diện lĩnh vực sức khỏe chỉ có tác dụng khi tắt ảnh cho lĩnh vực này
        // "đường huyết" không còn lọt: nằm trong danh sách rủi ro cao của RiskClassifier.
        [$a, $v] = $this->make('chỉ số đường huyết bình thường');
        $this->assertSame('medical', $this->svc()->generateFor($a, $v)['skip_code']);

        // Chủ đề lạ nhưng website đã gắn Trí tuệ xếp hạng rủi ro cao thì vẫn coi là sức khỏe.
        [$b, $w] = $this->make('thực đơn tuần cho người lớn tuổi');
        $brain = Brain::create(['name' => 'Y khoa', 'slug' => 'y_khoa', 'domain_key' => 'y_khoa', 'language' => 'vi', 'status' => 'active', 'description' => '', 'settings' => ['risk_class' => 'high']]);
        $b->website->brains()->attach($brain->id, ['priority' => 10]);
        $this->assertSame('medical', $this->svc()->generateFor($b, $w)['skip_code']);
    }

    public function test_health_topic_gets_illustrations_by_default_and_can_be_turned_off(): void
    {
        [$a, $v] = $this->make('cách điều trị bệnh tiểu đường');
        $r = $this->svc()->generateFor($a, $v);
        $this->assertGreaterThan(0, $r['generated'], 'lĩnh vực sức khỏe mặc định vẫn được tạo ảnh minh họa');
        $this->assertStringContainsString('Friendly, calm, stylized illustration', $r['items'][0]['prompt']);
        $this->assertStringContainsString('no text, letters or numbers', $r['items'][0]['prompt']);
        $this->assertStringNotContainsString('no medical devices', $r['items'][0]['prompt']);

        config(['agent.images.medical' => false]);
        [$b, $w] = $this->make('cách điều trị bệnh tiểu đường');
        $off = $this->svc()->generateFor($b, $w);
        $this->assertSame('medical', $off['skip_code']);
        $this->assertSame('Lĩnh vực sức khỏe: không tự tạo ảnh.', $off['skipped']);
    }

    public function test_prompt_strips_brand_terms_competitors_and_before_after(): void
    {
        $brand = new BrandProfile(['business_field' => 'Rau củ', 'forbidden_terms' => ['Vinfast'], 'avoid_competitors' => ['AcmeCorp']]);
        $p = ImagePromptBuilder::build('inline', 'rau AcmeCorp', 'trước và sau khi dùng Vinfast "<b>x</b>"', $brand);
        $this->assertStringNotContainsString('AcmeCorp', $p);
        $this->assertStringNotContainsString('Vinfast', $p);
        $this->assertStringNotContainsString('<', $p);
        $this->assertStringContainsString('no logos, no brand names, no text', $p);
        $this->assertStringContainsString('Business domain: Rau củ', $p);
    }

    public function test_provider_error_gives_warn_step_and_keeps_article(): void
    {
        [$a, $v] = $this->make();
        $before = $v->html_content;
        $g = new class implements ImageGeneratorInterface
        {
            public function generate(string $prompt, array $options = []): array
            {
                throw new ImageGenerationError('HTTP 500 key=AIzaSyDUMMYDUMMYDUMMYDUMMY12345678');
            }

            public function estimatedCostVnd(): float
            {
                return 0.0;
            }
        };
        $run = AgentRun::create(['website_id' => $this->site->id, 'article_id' => $a->id, 'trigger' => 'manual', 'status' => 'running', 'ai_mode' => 'real', 'started_at' => now()]);
        $res = (new ArticleImageStep(new ArticleImageService($g)))->run($run, $a, $v);
        $this->assertSame('warn', $res['status']);
        $step = $run->steps()->where('stage', 'images')->first();
        $this->assertSame('warn', $step->status);
        $this->assertStringNotContainsString('AIzaSy', json_encode($step->data).$step->detail);
        $this->assertSame($before, $v->fresh()->html_content);
    }

    public function test_step_ok_and_skipped_write_one_row(): void
    {
        [$a, $v] = $this->make();
        $run = AgentRun::create(['website_id' => $this->site->id, 'article_id' => $a->id, 'trigger' => 'manual', 'status' => 'running', 'ai_mode' => 'real', 'started_at' => now()]);
        $res = (new ArticleImageStep($this->svc()))->run($run, $a, $v, 7);
        $this->assertSame('ok', $res['status']);
        $this->assertSame(7, $run->steps()->first()->seq);
        $this->assertSame(3, $run->steps()->first()->data['generated']);

        $run2 = AgentRun::create(['website_id' => $this->site->id, 'article_id' => $a->id, 'trigger' => 'manual', 'status' => 'running', 'ai_mode' => 'real', 'started_at' => now()]);
        $res2 = (new ArticleImageStep(new ArticleImageService))->run($run2, $a, $v);
        $this->assertSame('skipped', $res2['status']);
        $this->assertSame(1, $run2->steps()->count());
    }

    public function test_gallery_data_and_view(): void
    {
        [$a, $v] = $this->make();
        $this->libraryCover($v);
        $lib = ImageGalleryData::forVersion($v);
        $this->assertFalse($lib[0]['ai']);
        $this->assertSame('featured', $lib[0]['role']);

        [$a2, $v2] = $this->make('rau muống');
        $this->svc()->generateFor($a2, $v2);
        $g = ImageGalleryData::forVersion($v2);
        $this->assertCount(3, $g);
        $this->assertSame('featured', $g[0]['role']);
        $this->assertTrue($g[0]['ai']);
        $this->assertNotEmpty($g[0]['prompt']);
        $this->assertSame('mock-image', $g[0]['model']);
        $html = view('filament.article.images-gallery', ['version' => $v2])->render();
        $this->assertStringContainsString('AI tạo', $html);

        [$a3, $v3] = $this->make('rau cải');
        $this->assertStringContainsString('chưa có ảnh', view('filament.article.images-gallery', ['version' => $v3])->render());
    }

    public function test_gemini_generator_with_http_fake_and_resolver(): void
    {
        $png = (new MockImageGenerator)->generate('x')['bytes'];
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()->push(['predictions' => [['bytesBase64Encoded' => base64_encode($png)]]])->push(['predictions' => [['bytesBase64Encoded' => base64_encode($png)]]])->push('boom', 500)]);
        $r = (new GeminiImageGenerator('K', 'imagen-4.0-fast-generate-001', 500.0))->generate('p');
        $this->assertSame('image/png', $r['mime']);
        $this->assertSame(500.0, $r['cost_vnd']);
        Http::assertSent(fn ($req) => $req->hasHeader('x-goog-api-key', 'K') && ! str_contains($req->url(), 'K='));

        $p = AiProvider::create(['name' => 'G', 'kind' => 'gemini', 'api_key' => 'secret-key', 'enabled' => true]);
        AiModelRoute::create(['task' => 'image_gen', 'tier' => 'cheap', 'primary_provider_id' => $p->id, 'primary_model' => 'imagen-4.0-fast-generate-001']);
        $this->assertInstanceOf(GeminiImageGenerator::class, ImageGeneratorResolver::resolve()['generator']);
        [$a, $v] = $this->make();
        $res = (new ArticleImageService)->generateFor($a, $v, ['max_inline' => 0]);
        $this->assertSame(1, $res['generated']);
        $this->assertSame(1000.0, $res['cost_vnd']);

        $this->expectException(ImageGenerationError::class);
        (new GeminiImageGenerator('K', 'imagen-x', 1.0))->generate('p');
    }

    public function test_resolver_rejects_anthropic_and_missing_key(): void
    {
        $p = AiProvider::create(['name' => 'C', 'kind' => 'anthropic', 'api_key' => 'k', 'enabled' => true]);
        AiModelRoute::create(['task' => 'image_gen', 'tier' => 'cheap', 'primary_provider_id' => $p->id, 'primary_model' => 'm']);
        $this->assertStringContainsString('không hỗ trợ tạo ảnh', (string) ImageGeneratorResolver::why());
        $p->update(['api_key' => null]);
        $this->assertStringContainsString('chưa có khóa', (string) ImageGeneratorResolver::why());
    }

    public function test_command_runs_with_mock_in_local(): void
    {
        [$a] = $this->make();
        $this->app['env'] = 'local';
        $this->artisan('article:images', ['article' => $a->id])->expectsOutputToContain('Đã tạo 3 ảnh')->assertSuccessful();
    }

    private function failingGenerator(ImageGenerationError $err): ImageGeneratorInterface
    {
        return new class($err) implements ImageGeneratorInterface
        {
            public int $calls = 0;

            public function __construct(private ImageGenerationError $err) {}

            public function generate(string $prompt, array $options = []): array
            {
                $this->calls++;
                throw $this->err;
            }

            public function estimatedCostVnd(): float
            {
                return 0.0;
            }
        };
    }

    private function slots(int $n): array
    {
        $out = [['id' => 'cover', 'role' => 'featured', 'prompt' => 'p0', 'aspect' => '16:9']];
        for ($i = 1; $i < $n; $i++) {
            $out[] = ['id' => 's'.$i, 'role' => 'inline', 'prompt' => 'p'.$i, 'aspect' => '4:3'];
        }

        return $out;
    }

    public function test_het_han_ngach_dung_ngay_khong_thu_lai_va_nho_de_cac_phien_sau_khong_goi(): void
    {
        [$a] = $this->make();
        $g = $this->failingGenerator(new ImageGenerationError('Nhà cung cấp ảnh trả lỗi HTTP 429 (hết hạn ngạch hoặc quá tải tạo ảnh, chờ khoảng 60 phút)', 429, 3600));
        $r = $this->svc($g)->generateSlots($a, $this->slots(3), $g, 100.0);

        $this->assertSame(1, $g->calls, 'hết hạn ngạch: không thử lại, không gọi các ô còn lại');
        $this->assertSame('quota', $r['stopped']);
        $this->assertSame(0, $r['generated']);
        $this->assertSame('failed', $r['items']['cover']['status']);
        $this->assertSame('skipped', $r['items']['s1']['status']);
        $this->assertStringContainsString('hết hạn ngạch', $r['errors'][0]);

        // Phiên sau: cờ còn hiệu lực nên không gọi nhà cung cấp, và lý do vẫn hiện.
        $g2 = $this->failingGenerator(new ImageGenerationError('x', 502));
        $r2 = $this->svc($g2)->generateSlots($a, $this->slots(3), $g2, 100.0);
        $this->assertSame(0, $g2->calls);
        $this->assertSame('quota', $r2['stopped']);
        $this->assertStringContainsString('thử lại sau', $r2['errors'][0]);
    }

    public function test_loi_lien_tiep_dung_sau_hai_o_va_loi_don_le_van_chay_tiep(): void
    {
        [$a] = $this->make();
        $g = $this->failingGenerator(new ImageGenerationError('Nhà cung cấp ảnh trả lỗi HTTP 502.', 502));
        $r = $this->svc($g)->generateSlots($a, $this->slots(3), $g, 100.0);

        $this->assertSame(4, $g->calls, '2 ô đầu mỗi ô thử 2 lần, ô thứ 3 bị bỏ qua');
        $this->assertSame('provider_errors', $r['stopped']);
        $this->assertSame('skipped', $r['items']['s2']['status']);
        $this->assertNull(\Illuminate\Support\Facades\Cache::get(ArticleImageService::QUOTA_KEY), 'lỗi 502 không đặt cờ hết hạn ngạch');
    }

    public function test_image_generation_error_phan_biet_het_han_ngach(): void
    {
        $this->assertTrue((new ImageGenerationError('x', 429))->isQuota());
        $this->assertTrue((new ImageGenerationError('báo RESOURCE_EXHAUSTED'))->isQuota());
        $this->assertFalse((new ImageGenerationError('Nhà cung cấp ảnh trả lỗi HTTP 502.', 502))->isQuota());
        Http::fake(['*' => Http::response(['error' => ['message' => 'x']], 429, ['Retry-After' => '3600'])]);
        $e = ImageGenerationError::http(Http::get('https://x.test'));
        $this->assertSame(429, $e->status);
        $this->assertSame(3600, $e->retryAfter);
        $this->assertStringContainsString('60 phút', $e->getMessage());
    }
}
