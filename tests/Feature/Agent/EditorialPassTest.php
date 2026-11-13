<?php

namespace Tests\Feature\Agent;

use App\Contracts\AiProviderInterface;
use App\Contracts\StructuredAiInterface;
use App\Models\Article;
use App\Models\Website;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\QualityFixtures;

/** Lượt biên tập trong luồng viết: sửa TRỰC TIẾP bản đang có, không viết lại mù; không bao giờ làm hỏng phiên. AI GIẢ. */
class EditorialPassTest extends TestCase
{
    use RefreshDatabase;

    private const KW = 'học bổng toàn phần';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->detectEnvironment(fn () => 'local');
        app(EmergencyStopService::class)->resetForTesting();
    }

    protected function tearDown(): void
    {
        app(EmergencyStopService::class)->resetForTesting();
        parent::tearDown();
    }

    private function article(): Article
    {
        $w = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);

        return Article::create(['website_id' => $w->id, 'primary_keyword' => self::KW, 'workflow_status' => 'planned']);
    }

    /**
     * @param  list<string>  $htmlMarkers  đoạn đánh dấu thêm vào bài của lần viết thứ n (lần cuối lặp lại)
     * @param  array<string,mixed>|\Throwable  $review  phản hồi của biên tập viên
     */
    private function ai(array $htmlMarkers, array|\Throwable $review, bool $shortSecond = false): AiProviderInterface
    {
        return new class($htmlMarkers, $review, $shortSecond) implements AiProviderInterface, StructuredAiInterface
        {
            public int $writes = 0;

            public array $options = [];

            public int $reviews = 0;

            public function __construct(private array $markers, private array|\Throwable $review, private bool $shortSecond)
            {
            }

            public function generateOutline(Article $article, array $options = []): array
            {
                return ['outline' => ['Mở đầu', 'Nội dung'], 'tokens_used' => 10, 'model' => 'fake'];
            }

            public function generateArticle(Article $article, array $outline, array $options = []): array
            {
                $this->options[] = $options;
                $m = $this->markers[min($this->writes, count($this->markers) - 1)];
                $a = QualityFixtures::goodAttrs($article->primary_keyword, 900, 1 + $this->writes);
                $a['html_content'] = $this->shortSecond && $this->writes === 1 ? '<h2>Quá ngắn</h2><p>Chỉ vài chữ.</p>' : $a['html_content']."<p>{$m}</p>";
                $this->writes++;

                return $a + ['tokens_used' => 100, 'cost_vnd' => 50.0, 'model' => 'fake', 'prompt_version' => 't'];
            }

            public function calculateCostVnd(int $promptTokens, int $completionTokens, string $model): float
            {
                return 0.0;
            }

            public function generateStructured(string $system, string $user): array
            {
                $this->reviews++;
                if ($this->review instanceof \Throwable) {
                    throw $this->review;
                }

                return ['data' => $this->review, 'tokens_used' => 30, 'cost_vnd' => 5.0, 'model' => 'fake-editor'];
            }
        };
    }

    private function chay(AiProviderInterface $ai): \App\Models\AgentRun
    {
        return (new AgentRunOrchestrator($ai, sleep: fn () => null))->run($this->article());
    }

    private function major(): array
    {
        return ['verdict' => 'can_sua', 'summary' => 'Bài né câu hỏi chính.', 'issues' => [['where' => 'mở bài', 'severity' => 'major', 'problem' => 'Không nêu câu trả lời cụ thể.', 'fix' => 'Nêu mức miễn học phí ngay đầu bài.']], 'missing' => ['Hạn nộp hồ sơ']];
    }

    public function test_bien_tap_co_diem_nghiem_trong_thi_nguoi_viet_sua_truc_tiep_ban_dang_co(): void
    {
        $ai = $this->ai(['BAN-DAU-DUY-NHAT', 'BAN-SAU-BIEN-TAP'], $this->major());
        $run = $this->chay($ai);

        $this->assertSame(2, $ai->writes);
        $this->assertSame(1, $ai->reviews);
        $this->assertArrayNotHasKey('previous_html', $ai->options[0]);
        $this->assertStringContainsString('BAN-DAU-DUY-NHAT', $ai->options[1]['previous_html'], 'Lần sửa phải THẤY bản đang có.');
        $this->assertStringStartsWith('BIÊN TẬP — mở bài', $ai->options[1]['feedback'][0]);
        $this->assertStringContainsString('THIẾU: Hạn nộp hồ sơ', implode("\n", $ai->options[1]['feedback']));

        $edit = $run->steps->firstWhere('stage', 'edit');
        $this->assertNotNull($edit);
        $this->assertSame('warn', $edit->status);
        $this->assertSame('fake-editor', $edit->model);
        $this->assertSame('ready_for_review', $run->status);
        $this->assertStringContainsString('BAN-SAU-BIEN-TAP', (string) $run->finalVersion?->html_content, 'Bản cuối là bản đã sửa theo biên tập.');
        $this->assertSame(1, $run->repair_rounds);
    }

    public function test_bien_tap_khen_bai_tot_thi_khong_viet_lai(): void
    {
        $ai = $this->ai(['BAN-DAU'], ['verdict' => 'tot', 'summary' => 'Bài chắc tay.', 'issues' => [], 'missing' => []]);
        $run = $this->chay($ai);
        $this->assertSame(1, $ai->writes);
        $this->assertSame('ok', $run->steps->firstWhere('stage', 'edit')->status);
        $this->assertSame(0, $run->repair_rounds);
    }

    public function test_ban_sau_bien_tap_truot_luat_bat_buoc_thi_giu_ban_dau(): void
    {
        $ai = $this->ai(['BAN-DAU-GIU-LAI', 'BAN-SAU'], $this->major(), shortSecond: true);
        $run = $this->chay($ai);
        $this->assertStringContainsString('BAN-DAU-GIU-LAI', (string) $run->finalVersion?->html_content);
        $decision = $run->steps->first(fn ($s) => ($s->data['decision'] ?? null) === 'edit_rejected');
        $this->assertNotNull($decision, 'Phải ghi lý do giữ bản đầu.');
        $this->assertSame('ready_for_review', $run->status);
    }

    public function test_bien_tap_loi_thi_bo_qua_va_phien_van_chay_binh_thuong(): void
    {
        $ai = $this->ai(['BAN-DAU'], new \RuntimeException('Mất kết nối'));
        $run = $this->chay($ai);
        $this->assertSame(1, $ai->writes);
        $edit = $run->steps->firstWhere('stage', 'edit');
        $this->assertSame('warn', $edit->status);
        $this->assertStringContainsString('chưa chạy', $edit->title);
        $this->assertSame('ready_for_review', $run->status);
    }

    public function test_loi_ai_o_buoc_sua_theo_bien_tap_thi_giu_ban_dau_khong_lam_phien_that_bai(): void
    {
        $ai = new class implements AiProviderInterface, StructuredAiInterface
        {
            public int $writes = 0;

            public function generateOutline(Article $article, array $options = []): array
            {
                return ['outline' => ['A', 'B'], 'tokens_used' => 1, 'model' => 'fake'];
            }

            public function generateArticle(Article $article, array $outline, array $options = []): array
            {
                if ($this->writes++ >= 1) {
                    throw new \App\Services\Ai\AiSchemaError('Phản hồi AI thiếu html_content.');
                }

                return ['html_content' => QualityFixtures::goodHtml($article->primary_keyword).'<p>BAN-DAU-CON-LAI</p>'] + QualityFixtures::goodAttrs($article->primary_keyword) + ['tokens_used' => 1, 'cost_vnd' => 1.0, 'model' => 'fake', 'prompt_version' => 't'];
            }

            public function calculateCostVnd(int $promptTokens, int $completionTokens, string $model): float
            {
                return 0.0;
            }

            public function generateStructured(string $system, string $user): array
            {
                return ['data' => ['verdict' => 'yeu', 'summary' => 'Cần sửa.', 'issues' => [['where' => 'mở bài', 'severity' => 'major', 'problem' => 'Né câu hỏi.', 'fix' => 'Trả lời thẳng.']], 'missing' => []], 'tokens_used' => 1, 'cost_vnd' => 1.0, 'model' => 'fake-editor'];
            }
        };
        $run = $this->chay($ai);
        $this->assertSame('ready_for_review', $run->status, (string) $run->outcome_reason);
        $this->assertStringContainsString('BAN-DAU-CON-LAI', (string) $run->finalVersion?->html_content);
        $this->assertTrue($run->steps->contains(fn ($s) => $s->stage === 'edit' && str_contains($s->title, 'giữ bản đầu')));
    }

    public function test_khong_co_ai_ho_tro_json_thi_khong_co_buoc_bien_tap_va_luong_cu_giu_nguyen(): void
    {
        $plain = new class implements AiProviderInterface
        {
            public int $writes = 0;

            public function generateOutline(Article $article, array $options = []): array
            {
                return ['outline' => ['A', 'B'], 'tokens_used' => 1, 'model' => 'fake'];
            }

            public function generateArticle(Article $article, array $outline, array $options = []): array
            {
                $this->writes++;

                return QualityFixtures::goodAttrs($article->primary_keyword) + ['tokens_used' => 1, 'cost_vnd' => 1.0, 'model' => 'fake', 'prompt_version' => 't'];
            }

            public function calculateCostVnd(int $promptTokens, int $completionTokens, string $model): float
            {
                return 0.0;
            }
        };
        $run = $this->chay($plain);
        $this->assertSame(1, $plain->writes);
        $this->assertNull($run->steps->firstWhere('stage', 'edit'));
    }
}
