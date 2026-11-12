<?php

namespace Tests\Feature\Agent;

use App\Filament\Support\AiUsage\UsageReader;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Trang Chi phí AI mặc định không tính model giả (mock-*, demo-*). */
class AiUsageFakeModelsTest extends TestCase
{
    use RefreshDatabase;

    private function version(Article $a, int $v, string $model, float $cost): void
    {
        ArticleVersion::create(['article_id' => $a->id, 'version' => $v, 'title' => 'T'.$v, 'slug' => 's'.$v, 'html_content' => '<p>x</p>',
            'ai_model' => $model, 'prompt_version' => 'v1.0', 'tokens_used' => 1000, 'cost_vnd' => $cost]);
    }

    public function test_an_model_gia_va_giu_model_that(): void
    {
        $site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $a = Article::create(['website_id' => $site->id, 'primary_keyword' => 'kw']);
        $this->version($a, 1, 'gemini-3.5-flash-lite', 190.0);
        $this->version($a, 2, 'mock-gemini-pro', 2880.0);
        $this->version($a, 3, 'demo-kich-ban', 90.0);

        $models = array_column(UsageReader::rows(), 'model');
        $this->assertContains('gemini-3.5-flash-lite', $models);
        $this->assertNotContains('mock-gemini-pro', $models);
        $this->assertNotContains('demo-kich-ban', $models);

        config(['agent.usage.include_fake_models' => true]);
        $all = array_column(UsageReader::rows(), 'model');
        $this->assertContains('mock-gemini-pro', $all);
        $this->assertContains('demo-kich-ban', $all);
    }

    public function test_cong_chi_phi_ca_luong_vao_phien_ban_cuoi_va_khong_doi_so_dem(): void
    {
        $site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $a = Article::create(['website_id' => $site->id, 'primary_keyword' => 'kw']);
        $this->version($a, 1, 'gemini-3.5-flash-lite', 131.0);
        $vid = (int) ArticleVersion::where('article_id', $a->id)->value('id');
        \DB::table('agent_runs')->insert(['website_id' => $site->id, 'article_id' => $a->id, 'final_version_id' => $vid, 'status' => 'ready_for_review',
            'tokens_used' => 5495, 'cost_vnd' => 191.0, 'created_at' => now(), 'updated_at' => now()]);
        $b = Article::create(['website_id' => $site->id, 'primary_keyword' => 'loi']);
        \DB::table('agent_runs')->insert(['website_id' => $site->id, 'article_id' => $b->id, 'final_version_id' => null, 'status' => 'failed',
            'tokens_used' => 800, 'cost_vnd' => 60.0, 'created_at' => now(), 'updated_at' => now()]);

        $rows = UsageReader::rows();
        $byArticle = collect($rows)->groupBy('article_id');
        $this->assertCount(1, $byArticle[$a->id]);
        $this->assertEqualsWithDelta(191.0, $byArticle[$a->id][0]['cost'], 0.01);
        $this->assertEqualsWithDelta(60.0, $byArticle[0]->sum('cost'), 0.01, 'lượt thất bại có bài vẫn tốn tiền, không đếm là bài');
        $this->assertEqualsWithDelta(251.0, collect($rows)->sum('cost'), 0.01);
    }
}
