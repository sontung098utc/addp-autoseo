<?php

namespace Tests\Feature\Agent;

use App\Contracts\AiProviderInterface;
use App\Models\Article;
use App\Models\Website;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Services\Agent\Run\RunPolicy;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Unit\QualityFixtures;

/** Quản lý SEO soát tài liệu TRƯỚC khi viết: mục dàn ý chưa có tài liệu xác thực thì ra lệnh Người nghiên cứu tìm ngay. Tavily và AI đều giả. */
class ManagerEvidenceReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(EmergencyStopService::class)->resetForTesting();
    }

    private function article(string $kw): Article
    {
        $w = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);

        return Article::create(['website_id' => $w->id, 'primary_keyword' => $kw, 'workflow_status' => 'planned']);
    }

    private function writer(): AiProviderInterface
    {
        return new class implements AiProviderInterface
        {
            public function generateOutline(Article $article, array $options = []): array
            {
                return ['outline' => ['Ngưỡng HbA1c chẩn đoán đái tháo đường', 'Chế độ ăn giảm đường huyết'], 'tokens_used' => 1, 'cost_vnd' => 0.0, 'model' => 'fake'];
            }

            public function generateArticle(Article $article, array $outline, array $options = []): array
            {
                return QualityFixtures::goodAttrs((string) $article->primary_keyword) + ['tokens_used' => 1, 'cost_vnd' => 1.0, 'model' => 'fake', 'prompt_version' => 't'];
            }

            public function calculateCostVnd(int $promptTokens, int $completionTokens, string $model): float
            {
                return 0.0;
            }
        };
    }

    public function test_muc_thieu_tai_lieu_o_chu_de_rui_ro_cao_thi_quan_ly_yeu_cau_nghien_cuu_tim_ngay(): void
    {
        config(['search.tavily.key' => 'tvly-T']);
        Http::fake(['api.tavily.com/search' => Http::response(['results' => []]), '*' => Http::response('', 404)]);
        $run = (new AgentRunOrchestrator($this->writer(), policy: new RunPolicy))->run($this->article('người tiểu đường nên ăn gì'), null);

        $step = $run->steps->first(fn ($s) => ($s->data['decision'] ?? null) === 'manager_research_request');
        $this->assertNotNull($step, 'có lệnh của Quản lý SEO cho Người nghiên cứu');
        $this->assertSame('manager', $step->role);
        $this->assertNotEmpty($step->data['weak_sections']);
        $this->assertSame(['researcher'], $step->data['routes']);
        $this->assertStringContainsString('Người nghiên cứu', $step->detail);
    }

    public function test_chu_de_thuong_hoac_khong_co_tim_web_that_thi_khong_ghi_lenh(): void
    {
        config(['search.tavily.key' => '']);
        Http::fake();
        $run = (new AgentRunOrchestrator($this->writer(), policy: new RunPolicy))->run($this->article('người tiểu đường nên ăn gì'), null);
        $this->assertNull($run->steps->first(fn ($s) => ($s->data['decision'] ?? null) === 'manager_research_request'), 'không có tìm kiếm web thật thì không giao việc tìm');
    }
}
