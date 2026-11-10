<?php

namespace Tests\Feature\Knowledge;

use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\KnowledgeGap;
use App\Models\Website;
use App\Services\Knowledge\Suggest\KnowledgeSuggestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class KnowledgeSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->site = Website::create(['name' => 'Ánh Dương', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function article(string $kw, array $cited, ?array $failed = null): Article
    {
        $a = Article::create(['website_id' => $this->site->id, 'primary_keyword' => $kw, 'workflow_status' => 'needs_review']);
        $v = ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 'Bài '.$kw, 'slug' => 's-'.uniqid(), 'html_content' => '<p>x</p>', 'sources_cited' => $cited, 'review_status' => 'needs_review']);
        if ($failed !== null) {
            $run = AgentRun::create(['website_id' => $this->site->id, 'job_type' => 'article_write', 'article_id' => $a->id, 'status' => 'ready_for_review', 'trigger' => 'manual', 'ai_mode' => 'real', 'final_version_id' => $v->id, 'started_at' => now()]);
            AgentRunStep::create(['agent_run_id' => $run->id, 'seq' => 1, 'stage' => 'grade', 'role' => 'editor', 'status' => 'warn', 'title' => 'Chấm', 'data' => ['failed' => $failed]]);
        }

        return $a;
    }

    public function test_ba_loai_loai_trung_va_thu_tu(): void
    {
        KnowledgeGap::create(['website_id' => $this->site->id, 'query' => 'học bổng Úc', 'count' => 3, 'status' => 'open']);
        KnowledgeGap::create(['website_id' => $this->site->id, 'query' => 'đã lấp', 'count' => 1, 'status' => 'filled']);
        $fail = [['code' => 'KB01', 'severity' => 'P1', 'message' => 'Bài ghi "9 triệu" cho "Gói Cơ bản — giá", nhưng kho [KB#1] ghi "8 triệu".']];
        $this->article('du học Úc', ['Bộ Giáo dục Úc', 'Nguồn Lạ Hoắc'], $fail);
        $this->article('du học Canada', ['Nguồn Lạ Hoắc'], $fail); // trùng nguồn và số liệu

        $s = (new KnowledgeSuggestions)->forWebsite($this->site);

        $this->assertSame(['gap', 'figure', 'source', 'source'], array_column($s, 'type'));
        $this->assertSame('build', $s[0]['action']);
        $this->assertSame('học bổng Úc', $s[0]['query']);
        $this->assertStringContainsString('Gói Cơ bản — giá', $s[1]['title']);
        $titles = implode('|', array_column($s, 'title'));
        $this->assertStringContainsString('Nguồn Lạ Hoắc', $titles);
        $this->assertSame(1, substr_count($titles, 'Nguồn «Bộ Giáo dục Úc»'));
        $this->assertCount(2, array_filter($s, fn ($x) => $x['type'] === 'source'), 'Lạ Hoắc xuất hiện ở hai bài: mỗi bài một gợi ý khác tiêu đề; Bộ Giáo dục Úc một');
        $this->assertNotContains('đã lấp', array_map(fn ($x) => (string) $x['query'], $s));
    }

    public function test_nguon_khop_ten_website_khong_duoc_goi_y(): void
    {
        $this->article('x', ['Ánh Dương', 'a.vn/gioi-thieu']);
        $this->assertSame([], (new KnowledgeSuggestions)->forWebsite($this->site));
    }

    public function test_bo_qua_an_goi_y_theo_website_va_gioi_han(): void
    {
        foreach (['một', 'hai', 'ba'] as $q) {
            KnowledgeGap::create(['website_id' => $this->site->id, 'query' => 'chủ đề '.$q, 'count' => 1, 'status' => 'open']);
        }
        $svc = new KnowledgeSuggestions;
        $this->assertCount(2, $svc->forWebsite($this->site, 2));
        $first = $svc->forWebsite($this->site)[0];
        KnowledgeSuggestions::dismiss($this->site->id, $first['key']);
        $after = $svc->forWebsite($this->site);
        $this->assertCount(2, $after);
        $this->assertNotContains($first['key'], array_column($after, 'key'));
    }

    public function test_khong_co_gi_thi_rong(): void
    {
        $this->assertSame([], (new KnowledgeSuggestions)->forWebsite($this->site));
    }

    public function test_bai_cu_hon_30_ngay_khong_tinh(): void
    {
        $a = $this->article('cũ', ['Nguồn Lạ Hoắc']);
        Article::query()->where('id', $a->id)->update(['updated_at' => now()->subDays(60)]);
        ArticleVersion::query()->where('article_id', $a->id)->update(['created_at' => now()->subDays(60)]);
        $this->assertSame([], (new KnowledgeSuggestions)->forWebsite($this->site));
    }
}
