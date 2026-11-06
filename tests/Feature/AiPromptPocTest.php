<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\User;
use App\Models\Website;
use App\Services\Ai\GeminiAiProvider;
use App\Services\Ai\TokenCostCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiPromptPocTest extends TestCase
{
    use RefreshDatabase;

    public function test_prompt_template_files_exist_and_have_required_sections(): void
    {
        $articlePromptPath = base_path('resources/prompts/v1.0-seo-vietnamese-article.prompt.md');
        $imagePromptPath = base_path('resources/prompts/v1.0-image-alt.prompt.md');

        $this->assertFileExists($articlePromptPath);
        $this->assertFileExists($imagePromptPath);

        $articlePromptContent = file_get_contents($articlePromptPath);
        $this->assertStringContainsString('E-E-A-T', $articlePromptContent);
        $this->assertStringContainsString('html_content', $articlePromptContent);
        $this->assertStringContainsString('faq_schema', $articlePromptContent);
        $this->assertStringContainsString('seo_title', $articlePromptContent);
        $this->assertStringContainsString('meta_description', $articlePromptContent);
    }

    public function test_token_cost_calculator_calculates_correct_costs(): void
    {
        $calculator = new TokenCostCalculator(25500.0);

        // Giả lập 800 prompt tokens và 3000 completion tokens
        $flashCost = $calculator->calculate(800, 3000, 'gemini-1.5-flash');

        $this->assertEquals(3800, $flashCost['total_tokens']);
        $this->assertGreaterThan(0, $flashCost['total_cost_usd']);
        $this->assertGreaterThan(0, $flashCost['total_cost_vnd']);
        // 1 bài Flash < 100 VNĐ
        $this->assertLessThan(100.0, $flashCost['total_cost_vnd']);

        // Gemini 1.5 Pro
        $proCost = $calculator->calculate(800, 3000, 'gemini-1.5-pro');
        $this->assertGreaterThan($flashCost['total_cost_vnd'], $proCost['total_cost_vnd']);
    }

    public function test_poc_report_exists_and_contains_benchmark_metrics(): void
    {
        $reportPath = base_path('reports/0-3_AI_POC_REPORT.md');
        $this->assertFileExists($reportPath);

        $reportContent = file_get_contents($reportPath);
        $this->assertStringContainsString('Gemini 1.5 Flash', $reportContent);
        $this->assertStringContainsString('quy trình sản xuất viên nén đạt chuẩn gmp', $reportContent);
        $this->assertStringContainsString('Chi phí 1 bài (VNĐ)', $reportContent);
    }
}
