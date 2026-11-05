<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Website;
use App\Services\Ai\MockAiProvider;
use App\Services\Content\ContentPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_pipeline_generates_new_article_version_successfully(): void
    {
        $website = Website::create([
            'name' => 'Test Site',
            'cms_type' => 'wordpress',
            'base_url' => 'https://test.local',
            'store_scope' => 'default',
            'encrypted_credentials' => ['key' => 'secret'],
            'status' => 'active',
        ]);

        $article = Article::create([
            'website_id' => $website->id,
            'primary_keyword' => 'xu hướng ai 2026',
            'search_intent' => 'informational',
            'topic_cluster' => 'Trí Tuệ Nhân Tạo',
            'workflow_status' => 'planned',
        ]);

        $pipeline = new ContentPipelineService(new MockAiProvider());
        $version1 = $pipeline->generateArticleVersion($article);

        // Kiểm tra phiên bản 1
        $this->assertEquals(1, $version1->version);
        $this->assertEquals('needs_review', $version1->review_status);
        $this->assertNotEmpty($version1->html_content);
        $this->assertNotEmpty($version1->outline);
        $this->assertGreaterThan(0, $version1->tokens_used);
        $this->assertGreaterThan(0, $version1->cost_vnd);

        // Trạng thái article phải chuyển sang needs_review
        $article->refresh();
        $this->assertEquals('needs_review', $article->workflow_status);

        // Sinh tiếp phiên bản 2
        $version2 = $pipeline->generateArticleVersion($article);
        $this->assertEquals(2, $version2->version);
        $this->assertCount(2, $article->versions);
    }
}
