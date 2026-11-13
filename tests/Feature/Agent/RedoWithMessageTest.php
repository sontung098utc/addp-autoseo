<?php

namespace Tests\Feature\Agent;

use App\Contracts\AiProviderInterface;
use App\Contracts\StructuredAiInterface;
use App\Filament\Resources\ArticleResource\Pages\ListArticles;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Services\Agent\Run\RunPolicy;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Unit\QualityFixtures;

/** "Giao làm lại": lời nhắn của người duyệt đến điều phối; điều phối quyết định giữ hay lập lại dàn ý và dặn từng bộ phận. AI GIẢ. */
class RedoWithMessageTest extends TestCase
{
    use RefreshDatabase;

    private Article $article;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->detectEnvironment(fn () => 'local');
        app(EmergencyStopService::class)->resetForTesting();
        $w = Website::create(['name' => 'Y', 'cms_type' => 'wordpress', 'base_url' => 'https://y.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->article = Article::create(['website_id' => $w->id, 'primary_keyword' => 'học bổng toàn phần', 'workflow_status' => 'needs_review']);
        ArticleVersion::create(['article_id' => $this->article->id, 'version' => 1, 'title' => 'Bản cũ', 'slug' => 'ban-cu', 'outline' => ['Điều kiện nhận', 'Hồ sơ cần chuẩn bị'],
            'html_content' => '<h2>Điều kiện nhận</h2><p>Đoạn mở đầu bản cũ rất dài dòng và lan man.</p><div class="autoseo-refs"><p>Nguồn</p></div>', 'review_status' => 'needs_review']);
    }

    private function ai(array $plan, bool $structured = true, bool $throws = false): AiProviderInterface
    {
        $cls = new class($plan, $throws) implements AiProviderInterface, StructuredAiInterface
        {
            public int $outlines = 0;

            public array $outlineOptions = [];

            public array $writeOptions = [];

            public int $judged = 0;

            public function __construct(private array $plan, private bool $throws) {}

            public function generateOutline(Article $article, array $options = []): array
            {
                $this->outlines++;
                $this->outlineOptions[] = $options;

                return ['outline' => ['Mục mới một', 'Mục mới hai'], 'tokens_used' => 1, 'model' => 'fake'];
            }

            public function generateArticle(Article $article, array $outline, array $options = []): array
            {
                $this->writeOptions[] = $options + ['_outline' => $outline];

                return QualityFixtures::goodAttrs('học bổng toàn phần') + ['tokens_used' => 1, 'cost_vnd' => 1.0, 'model' => 'fake', 'prompt_version' => 't'];
            }

            public function calculateCostVnd(int $promptTokens, int $completionTokens, string $model): float
            {
                return 0.0;
            }

            public function generateStructured(string $system, string $user): array
            {
                $this->judged++;
                if ($this->throws) {
                    throw new \RuntimeException('hết hạn mức');
                }

                return ['data' => $this->plan, 'tokens_used' => 7, 'cost_vnd' => 3.0, 'model' => 'fake-coordinator'];
            }
        };

        return $cls;
    }

    private function redo(AiProviderInterface $ai, string $message = 'Mở bài dài dòng, mục hồ sơ thiếu số liệu.'): AgentRun
    {
        return (new AgentRunOrchestrator($ai, policy: new RunPolicy))->run($this->article, null, 'manual', null, null, false, [], ['message' => $message]);
    }

    public function test_dieu_phoi_giu_dan_y_va_dan_nguoi_viet_khi_loi_nhan_ve_cau_chu(): void
    {
        $ai = $this->ai(['reuse_outline' => true, 'writer_note' => 'Rút gọn mở bài, thêm số liệu cho mục Hồ sơ.', 'summary' => 'Giữ dàn ý, viết lại mở bài và mục Hồ sơ.']);
        $run = $this->redo($ai);

        $this->assertSame(0, $ai->outlines, 'giữ dàn ý: không gọi AI lập dàn ý');
        $plan = $run->steps->first(fn ($s) => ($s->data['decision'] ?? null) === 'redo_plan');
        $this->assertNotNull($plan);
        $this->assertSame('manager', $plan->role);
        $this->assertTrue($plan->data['reuse_outline']);
        $this->assertSame('ai', $plan->data['source']);
        $this->assertStringContainsString('Người viết: Rút gọn mở bài', implode(' | ', $plan->data['told']));
        $this->assertStringNotContainsString('Bộ phận dàn ý', implode(' | ', $plan->data['told']));
        $this->assertGreaterThan(0, (float) $plan->cost_vnd);

        $w = $ai->writeOptions[0];
        $this->assertSame(['Điều kiện nhận', 'Hồ sơ cần chuẩn bị'], $w['_outline']);
        $this->assertSame('Mở bài dài dòng, mục hồ sơ thiếu số liệu.', $w['redo']['message']);
        $this->assertSame('Rút gọn mở bài, thêm số liệu cho mục Hồ sơ.', $w['redo']['note']);
        $this->assertStringContainsString('lan man', $w['redo']['previous'], 'người viết thấy bản trước để giữ phần đạt');
        $this->assertStringNotContainsString('Nguồn', $w['redo']['previous'], 'khối nguồn của bản trước không đưa vào');
    }

    public function test_dieu_phoi_lap_lai_dan_y_va_bao_bo_phan_dan_y_va_hoa_vien(): void
    {
        $ai = $this->ai(['reuse_outline' => false, 'outline_note' => 'Thêm mục So sánh các loại học bổng, bỏ mục Hồ sơ.', 'writer_note' => 'Viết theo dàn ý mới.', 'image_note' => 'Ảnh bìa nên là sinh viên ở giảng đường.', 'summary' => 'Lập lại dàn ý.']);
        $run = $this->redo($ai, 'Thiếu mục so sánh các loại học bổng và ảnh bìa chưa hợp.');

        $this->assertSame(1, $ai->outlines);
        $guidance = (string) ($ai->outlineOptions[0]['guidance'] ?? '');
        $this->assertStringContainsString('Thêm mục So sánh các loại học bổng', $guidance);
        $this->assertStringContainsString('sinh viên ở giảng đường', $guidance, 'lời dặn về ảnh nằm trong dàn ý (kế hoạch ảnh thuộc dàn ý)');
        $this->assertSame(['Mục mới một', 'Mục mới hai'], $ai->writeOptions[0]['_outline']);
        $plan = $run->steps->first(fn ($s) => ($s->data['decision'] ?? null) === 'redo_plan');
        $told = implode(' | ', $plan->data['told']);
        $this->assertStringContainsString('Bộ phận dàn ý: Thêm mục So sánh', $told);
        $this->assertStringContainsString('Họa viên: Ảnh bìa nên là', $told);
    }

    public function test_khong_co_ai_phan_loai_thi_giu_dan_y_chuyen_nguyen_loi_nhan_va_ghi_ro(): void
    {
        $ai = $this->ai([], true, true); // AI kiểm lỗi
        $run = $this->redo($ai, 'Sửa giọng văn cho thân thiện hơn.');

        $plan = $run->steps->first(fn ($s) => ($s->data['decision'] ?? null) === 'redo_plan');
        $this->assertSame('warn', $plan->status);
        $this->assertSame('fallback', $plan->data['source']);
        $this->assertStringContainsString('Chưa nhờ AI phân loại', $plan->detail);
        $this->assertSame(0, $ai->outlines, 'mặc định thận trọng: giữ dàn ý');
        $this->assertSame('Sửa giọng văn cho thân thiện hơn.', $ai->writeOptions[0]['redo']['note']);
        $this->assertSame('ready_for_review', $run->status, 'lỗi phân loại không làm hỏng phiên');
    }

    public function test_loi_dan_hoa_vien_di_vao_prompt_anh_ke_ca_khi_giu_dan_y(): void
    {
        config(['agent.images.medical' => true]);
        $ai = $this->ai(['reuse_outline' => true, 'writer_note' => 'Rút gọn mở bài.', 'image_note' => 'Ảnh bìa nên là sinh viên ở giảng đường.', 'summary' => 'x']);
        $run = $this->redo($ai, 'Ảnh bìa chưa hợp chủ đề.');

        $img = $run->steps->firstWhere('stage', 'images');
        $slots = (array) ($img?->data['plan']['slots'] ?? []);
        if ($slots === []) {
            $this->markTestSkipped('Môi trường test không có nhà cung cấp ảnh nên không có kế hoạch ảnh.');
        }
        foreach ($slots as $s) {
            $has = str_contains((string) $s['prompt'], 'sinh viên ở giảng đường');
            $this->assertSame($s['role'] === 'featured', $has, 'lời dặn nhắc "bìa" chỉ áp cho ảnh bìa');
        }
    }

    public function test_khong_co_loi_nhan_thi_khong_co_buoc_dieu_phoi(): void
    {
        $ai = $this->ai([]);
        $run = (new AgentRunOrchestrator($ai, policy: new RunPolicy))->run($this->article, null);
        $this->assertNull($run->steps->first(fn ($s) => ($s->data['decision'] ?? null) === 'redo_plan'));
        $this->assertArrayNotHasKey('redo', $ai->writeOptions[0]);
    }

    public function test_nut_giao_lam_lai_bat_buoc_loi_nhan_va_hien_dung_trang_thai(): void
    {
        $user = User::create(['name' => 'w', 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => 'writer', 'is_active' => true]);
        $this->actingAs($user);

        Livewire::test(ListArticles::class)->assertTableActionVisible('redo_agent', $this->article)
            ->callTableAction('redo_agent', $this->article, data: ['message' => ''])->assertHasTableActionErrors(['message' => 'required']);
        $this->assertSame(0, AgentRun::count());

        Livewire::test(ListArticles::class)->callTableAction('redo_agent', $this->article, data: ['message' => 'ngắn'])->assertHasTableActionErrors(['message' => 'min']);

        $planned = Article::create(['website_id' => $this->article->website_id, 'primary_keyword' => 'bài chưa viết', 'workflow_status' => 'planned']);
        Livewire::test(ListArticles::class)->assertTableActionHidden('redo_agent', $planned); // chưa có bản nháp thì không có gì để làm lại

        Livewire::test(ListArticles::class)->callTableAction('redo_agent', $this->article, data: ['message' => 'Mở bài dài dòng, cần ngắn gọn hơn nhiều.'])->assertHasNoTableActionErrors();
        $run = AgentRun::first();
        $this->assertNotNull($run);
        $this->assertNotNull($run->steps->first(fn ($s) => ($s->data['decision'] ?? null) === 'redo_plan'), 'lời nhắn từ giao diện đến điều phối');
    }
}
