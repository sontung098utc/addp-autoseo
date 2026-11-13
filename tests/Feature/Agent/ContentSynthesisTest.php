<?php

namespace Tests\Feature\Agent;

use App\Contracts\AiProviderInterface;
use App\Contracts\StructuredAiInterface;
use App\Contracts\WebSearchProvider;
use App\Models\Article;
use App\Models\Website;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Services\Ai\AbstractLlmProvider;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\QualityFixtures;

/** Tổng hợp nguồn trước dàn ý: hồ sơ nội dung đến dàn ý và người viết; khoảng trống thì tìm thêm; lỗi thì viết tiếp như cũ. AI và tìm kiếm GIẢ. */
class ContentSynthesisTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->detectEnvironment(fn () => 'local');
        app(EmergencyStopService::class)->resetForTesting();
        $this->site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        SynthSearch::$calls = [];
        config(['search.enabled' => true, 'search.provider' => 'fake', 'search.providers.fake' => SynthSearch::class]);
    }

    protected function tearDown(): void
    {
        app(EmergencyStopService::class)->resetForTesting();
        parent::tearDown();
    }

    private function ai(array $planFirst, ?array $planSecond = null): AiProviderInterface&StructuredAiInterface
    {
        return new class($planFirst, $planSecond) implements AiProviderInterface, StructuredAiInterface
        {
            public array $outlineOpts = [];

            public array $articleOpts = [];

            public int $plans = 0;

            public function __construct(private array $first, private ?array $second) {}

            public function generateOutline(Article $article, array $options = []): array
            {
                $this->outlineOpts[] = $options;

                return ['outline' => ['A', 'B'], 'tokens_used' => 1, 'model' => 'fake'];
            }

            public function generateArticle(Article $article, array $outline, array $options = []): array
            {
                $this->articleOpts[] = $options;

                return QualityFixtures::goodAttrs($article->primary_keyword) + ['tokens_used' => 1, 'cost_vnd' => 1.0, 'model' => 'fake', 'prompt_version' => 't'];
            }

            public function calculateCostVnd(int $promptTokens, int $completionTokens, string $model): float
            {
                return 0.0;
            }

            public function generateStructured(string $system, string $user): array
            {
                if (! str_contains($system, 'HƯỚNG NỘI DUNG')) {
                    return ['data' => ['verdict' => 'tot', 'summary' => '', 'issues' => [], 'missing' => []], 'tokens_used' => 1, 'cost_vnd' => 0.0, 'model' => 'fake'];
                }
                $this->plans++;

                return ['data' => ($this->plans > 1 && $this->second !== null) ? $this->second : $this->first, 'tokens_used' => 5, 'cost_vnd' => 2.0, 'model' => 'fake'];
            }
        };
    }

    private function plan(array $gaps = []): array
    {
        return ['reader' => 'Người đọc', 'questions' => ['Câu hỏi 1', 'Câu hỏi 2'], 'angle' => 'Góc riêng của bài', 'format' => 'bảng', 'length_words' => 1200,
            'findings' => [['point' => 'Luận điểm chắc', 'basis' => ['T1'], 'use' => 'dung', 'agreement' => 'mot_nguon'], ['point' => 'Luận điểm bỏ', 'basis' => ['T1'], 'use' => 'bo']],
            'gaps' => $gaps];
    }

    private function row(string $url, string $text): array
    {
        return ['title' => 'Tài liệu', 'url' => $url, 'snippet' => str_repeat($text.'. ', 9), 'score' => 0.9];
    }

    public function test_ho_so_noi_dung_den_dan_y_va_nguoi_viet_va_buoc_tong_hop_duoc_ghi(): void
    {
        SynthSearch::$rows = [$this->row('https://who.int/a', 'Hướng dẫn chẩn đoán đái tháo đường'), $this->row('https://moh.gov.vn/b', 'Bộ Y tế ban hành hướng dẫn')];
        $ai = $this->ai($this->plan());
        $article = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'so sánh máy dập viên', 'workflow_status' => 'planned']);
        $run = (new AgentRunOrchestrator($ai, sleep: fn () => null))->run($article);

        $this->assertStringContainsString('HỒ SƠ NỘI DUNG', $ai->outlineOpts[0]['content_plan']);
        $this->assertStringContainsString('Góc riêng của bài', $ai->articleOpts[0]['content_plan']);
        $step = $run->steps->firstWhere('stage', 'synthesize');
        $this->assertSame('ok', $step->status);
        $this->assertSame('strategist', $step->role);
        $this->assertSame('Góc riêng của bài', $step->data['plan']['angle']);
        $stages = $run->steps->pluck('stage')->all();
        $this->assertLessThan(array_search('brief', $stages), array_search('synthesize', $stages), 'Tổng hợp phải đứng TRƯỚC dàn ý.');
        $this->assertSame('ready_for_review', $run->status);
    }

    public function test_con_khoang_trong_thi_tim_them_roi_tong_hop_lai_mot_lan(): void
    {
        SynthSearch::$rows = [$this->row('https://who.int/a', 'Hướng dẫn chẩn đoán đái tháo đường')];
        $ai = $this->ai($this->plan(['thiếu số liệu X']), $this->plan());
        $article = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'học bổng toàn phần', 'workflow_status' => 'planned']); // rủi ro thấp: chỉ tìm thêm tài liệu, không nạp kho
        $run = (new AgentRunOrchestrator($ai, sleep: fn () => null))->run($article);

        $topics = array_filter(SynthSearch::$calls, fn ($q) => str_contains($q, 'thiếu số liệu X'));
        $this->assertNotEmpty($topics, 'Phải tìm đúng chủ đề còn thiếu.');
        $gap = $run->steps->first(fn ($s) => ($s->data['purpose'] ?? null) === 'references_gap');
        $this->assertNotNull($gap);
        $this->assertSame('ready_for_review', $run->status);
        $this->assertLessThanOrEqual(2, $ai->plans, 'Tổng hợp lại tối đa một lần.');
    }

    public function test_khong_co_ai_co_cau_truc_hoac_khong_co_nguon_thi_viet_tiep_nhu_cu(): void
    {
        $plain = new class implements AiProviderInterface
        {
            public array $opts = [];

            public function generateOutline(Article $article, array $options = []): array
            {
                return ['outline' => ['A'], 'tokens_used' => 1, 'model' => 'fake'];
            }

            public function generateArticle(Article $article, array $outline, array $options = []): array
            {
                $this->opts[] = $options;

                return QualityFixtures::goodAttrs($article->primary_keyword) + ['tokens_used' => 1, 'cost_vnd' => 1.0, 'model' => 'fake', 'prompt_version' => 't'];
            }

            public function calculateCostVnd(int $promptTokens, int $completionTokens, string $model): float
            {
                return 0.0;
            }
        };
        SynthSearch::$rows = [$this->row('https://who.int/a', 'Hướng dẫn')];
        $article = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'so sánh máy dập viên', 'workflow_status' => 'planned']);
        $run = (new AgentRunOrchestrator($plain, sleep: fn () => null))->run($article);
        $this->assertArrayNotHasKey('content_plan', $plain->opts[0]);
        $this->assertNull($run->steps->firstWhere('stage', 'synthesize'));
        $this->assertSame('ready_for_review', $run->status);

        SynthSearch::$rows = [];
        $ai = $this->ai($this->plan());
        $run2 = (new AgentRunOrchestrator($ai, sleep: fn () => null))->run($article);
        $this->assertSame(0, $ai->plans, 'Không có nguồn nào thì không gọi AI tổng hợp.');
        $this->assertSame('ready_for_review', $run2->status);
    }

    public function test_loi_nhac_bat_viet_tieng_viet_va_khong_dan_ten_to_chuc_nuoc_ngoai_trong_than_bai(): void
    {
        $p = new class('k', 'm') extends AbstractLlmProvider
        {
            public array $seen = [];

            protected function complete(string $system, string $user): array
            {
                $this->seen[] = $system;

                return ['text' => json_encode(['html_content' => '<p>x</p>', 'outline' => ['A']]), 'prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2];
            }
        };
        $a = new Article(['primary_keyword' => 'k', 'search_intent' => 'informational']);
        $p->generateArticle($a, ['A'], ['content_plan' => "HỒ SƠ NỘI DUNG\nGóc: Z", 'references' => [['title' => 'T', 'domain' => 'who.int', 'excerpt' => 'Ngưỡng 6,5%']]]);
        $p->generateOutline($a, ['content_plan' => "HỒ SƠ NỘI DUNG\nGóc: Z"]);
        $this->assertStringContainsString('BẰNG TIẾNG VIỆT', $p->seen[0]);
        $this->assertStringContainsString('Mayo Clinic', $p->seen[0]);
        $this->assertStringContainsString('Góc: Z', $p->seen[0]);
        $this->assertStringContainsString('không viết "theo <tên tổ chức nước ngoài>"', $p->seen[0]);
        $this->assertStringContainsString('Góc: Z', $p->seen[1]);
        $this->assertStringContainsString('Dàn ý phải phục vụ hồ sơ này', $p->seen[1]);
    }
}

/** Nhà cung cấp tìm kiếm giả cho test tổng hợp. */
class SynthSearch implements WebSearchProvider
{
    /** @var list<array<string,mixed>> */
    public static array $rows = [];

    /** @var list<string> */
    public static array $calls = [];

    public function search(string $query, array $options = []): array
    {
        self::$calls[] = $query;

        return ['results' => self::$rows, 'cost_vnd' => 10.0, 'provider' => 'fake'];
    }
}
