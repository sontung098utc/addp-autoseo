<?php

namespace Tests\Feature\Images;

use App\Contracts\ImageGeneratorInterface;
use App\Models\AgentRunStep;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\MediaAsset;
use App\Models\Website;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Services\Ai\DemoScriptedAiProvider;
use App\Services\Images\ArticleImageService;
use App\Services\Images\ImageJob;
use App\Services\Images\ImagePlanner;
use App\Services\Images\MockImageGenerator;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Luồng ảnh mới: kế hoạch ảnh từ dàn ý -> tạo ảnh theo kế hoạch (làm lại ô lỗi) -> lắp ráp vào bài. Ảnh giả lập, không gọi mạng. */
class ImagePipelineTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        app(EmergencyStopService::class)->resetForTesting();
        $this->site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function article(string $kw = 'quy trình chăm sóc khách hàng'): Article
    {
        return Article::create(['website_id' => $this->site->id, 'primary_keyword' => $kw, 'workflow_status' => 'planned']);
    }

    /** Bộ tạo ảnh giả có móc: $hook(số lần gọi, prompt) có thể ném lỗi để mô phỏng ô lỗi. */
    private function gen(\Closure $hook): ImageGeneratorInterface
    {
        return new class($hook) implements ImageGeneratorInterface
        {
            public int $calls = 0;

            private MockImageGenerator $inner;

            public function __construct(private \Closure $hook)
            {
                $this->inner = new MockImageGenerator;
            }

            public function generate(string $prompt, array $options = []): array
            {
                ($this->hook)(++$this->calls, $prompt);

                return $this->inner->generate($prompt, $options);
            }

            public function estimatedCostVnd(): float
            {
                return $this->inner->estimatedCostVnd();
            }
        };
    }

    private function outline(): array
    {
        return [
            ['level' => 'H1', 'heading' => 'Chăm sóc khách hàng', 'purpose' => 'Giới thiệu chủ đề', 'key_points' => ['định nghĩa']],
            ['level' => 'H2', 'heading' => 'Chăm sóc khách hàng là gì', 'purpose' => 'Giải thích khái niệm', 'key_points' => ['khách hàng vui vẻ', 'trải nghiệm']],
            ['level' => 'H2', 'heading' => 'Quy trình 5 bước', 'purpose' => 'Các bước thực hiện', 'key_points' => ['tiếp nhận', 'xử lý']],
            ['level' => 'H2', 'heading' => 'Công cụ hỗ trợ', 'purpose' => 'Phần mềm CRM', 'key_points' => ['CRM']],
            ['level' => 'H2', 'heading' => 'Câu hỏi thường gặp', 'purpose' => 'FAQ', 'key_points' => []],
        ];
    }

    public function test_ke_hoach_anh_tu_dan_y_co_vi_tri_ly_do_prompt_va_alt(): void
    {
        $plan = ImagePlanner::plan($this->article(), $this->outline(), ['brand' => null]);

        $this->assertSame('ok', $plan['status']);
        $this->assertSame(['featured', 'inline', 'inline'], array_column($plan['slots'], 'role'));
        $s = $plan['slots'][1];
        $this->assertSame('Chăm sóc khách hàng là gì', $s['section_heading']);
        $this->assertSame(1, $s['section_order']);
        $this->assertStringContainsString('Giải thích khái niệm', $s['why']);
        $this->assertStringContainsString('Scene idea', $s['prompt'], 'prompt lấy gợi ý cảnh từ mục đích của mục');
        $this->assertStringContainsString('no text, letters or numbers', $s['prompt'], 'luật an toàn vẫn nối vào cuối');
        $this->assertSame('Hình minh họa mục Chăm sóc khách hàng là gì', $s['alt']);
        $this->assertNotContains('Câu hỏi thường gặp', array_column($plan['slots'], 'section_heading'), 'FAQ không cần ảnh');
    }

    public function test_co_anh_thu_vien_thi_khong_lap_o_anh_bia(): void
    {
        $plan = ImagePlanner::plan($this->article(), $this->outline(), ['brand' => null, 'library_cover' => true]);

        $this->assertNotContains('featured', array_column($plan['slots'], 'role'));
        $this->assertTrue($plan['library_cover']);
    }

    public function test_tao_anh_theo_ke_hoach_o_loi_duoc_thu_lai_mot_lan_va_chi_o_do(): void
    {
        $plan = ImagePlanner::plan($this->article(), $this->outline(), ['brand' => null]);
        $gen = $this->gen(function (int $n, string $prompt) {
            if ($n === 2) {
                throw new \RuntimeException('tạm lỗi');
            }
        });
        $r = (new ArticleImageService)->generateSlots($this->article(), $plan['slots'], $gen, 1000000.0);

        $this->assertSame(3, $r['generated']);
        $this->assertSame(4, $gen->calls, '3 ô + 1 lần thử lại đúng ô lỗi');
        $this->assertSame(2, $r['items'][$plan['slots'][1]['id']]['attempts']);
        $this->assertSame(1, $r['items'][$plan['slots'][0]['id']]['attempts']);
    }

    public function test_o_loi_het_lan_thu_thi_bao_loi_va_cac_o_khac_van_co_anh(): void
    {
        $plan = ImagePlanner::plan($this->article(), $this->outline(), ['brand' => null]);
        $gen = $this->gen(function (int $n, string $prompt) {
            if (str_contains($prompt, 'Quy trình 5 bước')) {
                throw new \RuntimeException('luôn lỗi');
            }
        });
        $r = (new ArticleImageService)->generateSlots($this->article(), $plan['slots'], $gen, 1000000.0);

        $this->assertSame(2, $r['generated']);
        $this->assertSame('failed', $r['items']['s2']['status']);
        $this->assertCount(1, $r['errors']);
    }

    public function test_lap_rap_chen_anh_sau_muc_dung_ten_ke_ca_khi_bai_doi_ten_muc(): void
    {
        $a = $this->article();
        $plan = ImagePlanner::plan($a, $this->outline(), ['brand' => null]);
        $r = (new ArticleImageService)->generateSlots($a, $plan['slots'], new MockImageGenerator, 1000000.0);
        $v = ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 'T', 'slug' => 't', 'html_content' =>
            '<p>Mở đầu</p><h2>Chăm sóc khách hàng là gì</h2><p>Nội dung 1</p><h2>Các bước trong quy trình 5 bước</h2><p>Nội dung 2</p><h2>Công cụ hỗ trợ</h2><p>Nội dung 3</p>']);

        $res = (new ArticleImageService)->assemble($v, $plan['slots'], $r['items']);

        $this->assertSame(3, $res['attached']);
        $by = array_column($res['placements'], null, 'slot');
        $this->assertSame('featured', $by['cover']['status']);
        $this->assertSame('inserted', $by['s1']['status']);
        $this->assertSame('inserted', $by['s2']['status'], 'mục được đổi tên vẫn khớp theo tên chứa nhau');
        $html = (string) $v->fresh()->html_content;
        $this->assertSame(2, substr_count($html, '<figure'));
        $this->assertLessThan(strpos($html, '<h2>Các bước'), strpos($html, 'Nội dung 1</p>') + 100, 'ảnh 1 nằm trong mục 1');
        $cover = $v->mediaAssets()->wherePivot('is_featured', true)->first();
        $this->assertNotNull($cover);
        $this->assertStringNotContainsString($cover->url, $html, 'ảnh bìa không chèn vào nội dung');
    }

    public function test_lap_rap_bao_ro_anh_khong_tim_duoc_muc_va_o_thieu(): void
    {
        $a = $this->article();
        $plan = ImagePlanner::plan($a, $this->outline(), ['brand' => null]);
        $r = (new ArticleImageService)->generateSlots($a, $plan['slots'], new MockImageGenerator, 1000000.0);
        unset($r['items']['s2']);
        $v = ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 'T', 'slug' => 't', 'html_content' => '<p>Chỉ có mở đầu, không có mục H2 nào.</p>']);

        $by = array_column((new ArticleImageService)->assemble($v, $plan['slots'], $r['items'])['placements'], null, 'slot');

        $this->assertSame('unplaced', $by['s1']['status']);
        $this->assertSame('missing', $by['s2']['status']);
        $this->assertSame(0, substr_count((string) $v->fresh()->html_content, '<figure'));
    }

    public function test_image_job_dong_bo_ghi_ket_qua_vao_buoc_images(): void
    {
        $a = $this->article();
        $plan = ImagePlanner::plan($a, $this->outline(), ['brand' => null]);
        $run = \App\Models\AgentRun::create(['website_id' => $this->site->id, 'article_id' => $a->id, 'trigger' => 'manual', 'status' => 'running', 'trust_tier' => 'T1']);
        $step = AgentRunStep::create(['agent_run_id' => $run->id, 'seq' => 1, 'stage' => 'images', 'role' => 'designer', 'status' => 'ok', 'title' => 't', 'detail' => 'd',
            'data' => ['plan' => $plan, 'article_id' => $a->id, 'cap_vnd' => 1000000.0, 'gen' => ['done' => false]]]);

        ImageJob::execute($step, new MockImageGenerator, $a);

        $d = (array) $step->fresh()->data;
        $this->assertTrue($d['gen']['done']);
        $this->assertSame(3, $d['gen']['generated']);
        $this->assertSame(3, MediaAsset::count());
    }

    public function test_ke_hoach_anh_nam_trong_dan_y_roi_tao_anh_song_song_va_lap_rap(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $run = (new AgentRunOrchestrator(new DemoScriptedAiProvider))->run($this->article('dịch vụ thiết kế website'), null, 'manual');
        $stages = $run->steps->pluck('stage')->all();

        $this->assertSame(['guard', 'risk', 'brief', 'images', 'research', 'write', 'grade', 'assemble', 'handoff'], array_values(array_unique(array_diff($stages, ['retry', 'decision', 'repair']))));
        $brief = $run->steps->firstWhere('stage', 'brief');
        $this->assertNotEmpty($brief->data['image_plan']['plan']['slots'], 'kế hoạch ảnh nằm trong dữ kiện bước Dàn ý');
        $this->assertNull($run->steps->firstWhere('stage', 'image_plan'), 'không có bước kế hoạch ảnh riêng');
        $images = $run->steps->firstWhere('stage', 'images');
        $this->assertGreaterThan(0, $images->data['generated']);
        $this->assertSame('designer', $images->role);
        $asm = $run->steps->firstWhere('stage', 'assemble');
        $this->assertNotEmpty($asm->data['placements']);
        $this->assertGreaterThan(0, substr_count((string) $run->finalVersion->html_content, 'data-ai-image'), 'ảnh đã được chèn vào nội dung bài');
    }

    public function test_tien_trinh_con_nhan_cau_hinh_csdl_cua_tien_trinh_cha(): void
    {
        config(['database.connections.sqlite.database' => '/tmp/x-preview.sqlite', 'cache.default' => 'array']);
        $env = \App\Services\Images\ImageJob::childEnv('sqlite');
        $this->assertSame('sqlite', $env['DB_CONNECTION']);
        $this->assertSame('/tmp/x-preview.sqlite', $env['DB_DATABASE']);
        $this->assertSame('array', $env['CACHE_STORE']);
        $this->assertArrayHasKey('APP_ENV', $env);
        $this->assertSame((string) config('app.key'), $env['APP_KEY'], 'con cần APP_KEY để giải mã khóa nhà cung cấp');

        config(['database.connections.mysql.host' => 'dbhost', 'database.connections.mysql.database' => 'seo', 'database.connections.mysql.username' => 'u', 'database.connections.mysql.password' => 'p', 'database.connections.mysql.port' => '3306']);
        $m = \App\Services\Images\ImageJob::childEnv('mysql');
        $this->assertSame('mysql', $m['DB_CONNECTION']);
        $this->assertSame('dbhost', $m['DB_HOST']);
        $this->assertSame('seo', $m['DB_DATABASE']);
        $this->assertSame('u', $m['DB_USERNAME']);
    }
}
