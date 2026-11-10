<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Campaign;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\Website;
use App\Services\Agent\Run\CampaignRunner;
use App\Services\Ai\DemoScriptedAiProvider;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** G0: mọi bài đều nối được về kho từ khóa (mắt xích 1 của chuỗi), kể cả bài tạo tay và bài chiến dịch giao từ danh sách từ khóa. */
class ArticleKeywordLinkTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Website
    {
        return Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://'.uniqid().'.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    public function test_bai_tao_tay_tu_tao_tu_khoa_va_noi_keyword_id(): void
    {
        $w = $this->site();
        $a = Article::create(['website_id' => $w->id, 'primary_keyword' => '  Cách  cải thiện tiểu đường ', 'workflow_status' => 'needs_review']);

        $k = Keyword::where('website_id', $w->id)->first();
        $this->assertNotNull($k);
        $this->assertSame('Cách cải thiện tiểu đường', $k->keyword);
        $this->assertSame('tay', $k->source);
        $this->assertNull($k->volume, 'Không điền số lượt tìm giả.');
        $this->assertSame($k->id, $a->fresh()->keyword_id);
        $this->assertSame('manual', $a->fresh()->origin);
        $this->assertSame('has_article', $k->fresh()->status);
    }

    public function test_dung_lai_tu_khoa_da_co_va_lay_cum_cua_no(): void
    {
        $w = $this->site();
        $c = KeywordCluster::create(['website_id' => $w->id, 'name' => 'Tiểu đường']);
        $k = Keyword::create(['website_id' => $w->id, 'keyword' => 'Tiểu đường tuýp 2', 'keyword_key' => 'tiểu đường tuýp 2', 'volume' => 900, 'source' => 'csv', 'cluster_id' => $c->id]);

        $a = Article::create(['website_id' => $w->id, 'primary_keyword' => 'tiểu đường  TUÝP 2', 'workflow_status' => 'needs_review']);

        $this->assertSame(1, Keyword::count());
        $this->assertSame($k->id, $a->fresh()->keyword_id);
        $this->assertSame($c->id, $a->fresh()->cluster_id);
        $this->assertSame(900, $k->fresh()->volume);
        $this->assertSame('csv', $k->fresh()->source);
    }

    public function test_khong_ghi_de_nguon_goc_khi_da_co_keyword_id(): void
    {
        $w = $this->site();
        $k = Keyword::create(['website_id' => $w->id, 'keyword' => 'abc', 'keyword_key' => 'abc', 'source' => 'ai']);
        $a = Article::create(['website_id' => $w->id, 'primary_keyword' => 'abc', 'keyword_id' => $k->id, 'origin' => 'plan', 'workflow_status' => 'planned']);

        $this->assertSame('plan', $a->fresh()->origin);
        $this->assertSame('planned', $k->fresh()->status);
        $this->assertSame(1, Keyword::count());
    }

    public function test_bai_chien_dich_giao_tu_danh_sach_tu_khoa_co_nguon_va_tu_khoa(): void
    {
        app(EmergencyStopService::class)->resetForTesting();
        $w = $this->site();
        $c = Campaign::create(['website_id' => $w->id, 'name' => 'CD', 'status' => 'active', 'daily_limit' => 2, 'source' => 'keywords', 'keywords' => ['quảng cáo google']]);

        $r = (new CampaignRunner(fn () => new DemoScriptedAiProvider))->tick($c);

        $this->assertSame('ran', $r['result']);
        $a = $r['run']->article->fresh();
        $this->assertSame('keyword', $a->origin);
        $this->assertSame($c->id, $a->campaign_id);
        $this->assertNotNull($a->keyword_id);
        $this->assertSame('quảng cáo google', $a->keyword->keyword);
    }
}
