<?php

namespace Tests\Feature\Agent;

use App\Contracts\StructuredAiInterface;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\KnowledgeItem;
use App\Models\Website;
use App\Services\Agent\Eval\ArticleMetrics;
use App\Services\Agent\Eval\GoldenQuery;
use App\Services\Knowledge\KnowledgeDedupe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\QualityFixtures;

/** Q0.2: bốn số đo của một phiên bản bài. AI GIẢ. Mục cần AI mà chưa có AI phải là not_run, không phải số 0. */
class ArticleMetricsTest extends TestCase
{
    use RefreshDatabase;

    private Article $article;

    protected function setUp(): void
    {
        parent::setUp();
        $w = Website::create(['name' => 'Y', 'cms_type' => 'wordpress', 'base_url' => 'https://y.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->article = Article::create(['website_id' => $w->id, 'primary_keyword' => 'học bổng toàn phần', 'workflow_status' => 'planned']);
    }

    private function version(string $extraHtml = ''): ArticleVersion
    {
        $a = QualityFixtures::goodAttrs('học bổng toàn phần');
        $a['html_content'] .= $extraHtml;

        return ArticleVersion::create($a + ['article_id' => $this->article->id, 'version' => 1, 'review_status' => 'drafting']);
    }

    private function query(): GoldenQuery
    {
        return new GoldenQuery('q-x', 'học bổng toàn phần', 'informational', 'low', 'x', [
            ['id' => 'x-1', 'q' => 'Học bổng toàn phần miễn bao nhiêu học phí?', 'kind' => 'so_lieu', 'needs_source' => true],
        ]);
    }

    private function fakeAi(callable $by): StructuredAiInterface
    {
        return new class($by) implements StructuredAiInterface
        {
            public function __construct(private $by)
            {
            }

            public function generateStructured(string $system, string $user): array
            {
                return ['data' => ($this->by)($system), 'tokens_used' => 5, 'cost_vnd' => 1.0, 'model' => 'fake'];
            }
        };
    }

    public function test_khong_co_ai_thi_muc_can_ai_la_not_run_con_muc_tat_dinh_van_co_so(): void
    {
        $v = $this->version('<p>Học bổng miễn 100% học phí cho sinh viên xuất sắc theo quy định.</p>');
        $m = (new ArticleMetrics(null))->measure($v, $this->article, $this->query());
        $this->assertSame('not_run', $m['intent']['status']);
        $this->assertNull($m['intent']['coverage']);
        $this->assertSame('not_run', $m['claims']['judge']['status']);
        $this->assertNull($m['claims']['judge']['supported_ratio']);
        $this->assertIsInt($m['hygiene']['score']);
        $this->assertSame(1, $m['claims']['fact_sentences']);
        $this->assertSame(0.0, $m['claims']['cited_ratio']);
        $this->assertNull($m['human']);
        $this->assertFalse($m['has_sources']);
    }

    public function test_bai_khong_khop_tu_khoa_chuan_thi_do_phu_y_dinh_chua_chay(): void
    {
        $m = (new ArticleMetrics($this->fakeAi(fn () => [])))->measure($this->version(), $this->article, null);
        $this->assertSame('not_run', $m['intent']['status']);
        $this->assertNull($m['golden_id']);
    }

    public function test_co_ai_thi_ra_do_phu_y_dinh_va_ti_le_cap_trich_dan_duoc_ho_tro(): void
    {
        $kb = KnowledgeItem::create(['type' => 'fact', 'title' => 'Học bổng toàn phần', 'body' => 'Học bổng toàn phần miễn 100% học phí.', 'status' => 'approved', 'version' => 1, 'content_hash' => KnowledgeDedupe::hash('a', 'x'), 'website_id' => $this->article->website_id]);
        $v = $this->version("<p>Học bổng toàn phần miễn 100% học phí cho sinh viên xuất sắc theo quy định. [KB#{$kb->id}]</p>");
        $ai = $this->fakeAi(function (string $system) {
            return str_contains($system, 'câu hỏi bắt buộc')
                ? ['items' => [['n' => 1, 'status' => 'answered', 'quote' => 'Học bổng toàn phần miễn 100% học phí cho sinh viên xuất sắc theo quy định.']]]
                : ['verdicts' => [['n' => 1, 'verdict' => 'supported', 'reason' => '']]];
        });
        $m = (new ArticleMetrics($ai, null, fn () => null))->measure($v, $this->article, $this->query());
        $this->assertSame('done', $m['intent']['status']);
        $this->assertSame(1.0, $m['intent']['coverage']);
        $this->assertSame('done', $m['claims']['judge']['status']);
        $this->assertSame(1, $m['claims']['judge']['checked']);
        $this->assertSame(1.0, $m['claims']['judge']['supported_ratio']);
        $this->assertSame(1.0, $m['claims']['cited_ratio']);
    }
}
