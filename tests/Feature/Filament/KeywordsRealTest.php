<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ContentPlanPage;
use App\Filament\Pages\KeywordsPage;
use App\Filament\Support\ContentPlan\PlanActions;
use App\Filament\Support\Keywords\CsvKeywordParser;
use App\Filament\Support\Keywords\DemoKeywordRepository;
use App\Filament\Support\Keywords\EloquentKeywordRepository;
use App\Filament\Support\Keywords\KeywordArticleLinks;
use App\Filament\Support\Keywords\KeywordService;
use App\Models\Article;
use App\Models\Keyword;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class KeywordsRealTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    private User $writer;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['studio.keywords_real' => true]);
        $this->site = Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->writer = User::create(['name' => 'w', 'email' => 'w@x.vn', 'password' => 'secret123', 'role' => 'writer', 'is_active' => true]);
        $this->actingAs($this->writer);
    }

    public function test_make_chon_ban_eloquent_hoac_minh_hoa(): void
    {
        $this->assertInstanceOf(EloquentKeywordRepository::class, KeywordService::make()->repo());
        config(['studio.keywords_real' => false]);
        $this->assertInstanceOf(DemoKeywordRepository::class, KeywordService::make()->repo());
    }

    public function test_csv_that_vao_bang_dung_nguon_va_volume_trong(): void
    {
        $svc = KeywordService::make();
        $r = (new CsvKeywordParser)->parse("keyword,volume\nhọc bổng A,100\nhọc bổng B\nhọc bổng  a,5\nX,abc\n", $svc->existingKeywords());
        $this->assertCount(2, $r['rows']);
        $this->assertCount(1, $r['duplicates']);
        $this->assertCount(1, $r['errors']);
        $this->assertSame(2, $svc->addRows($r['rows'], 'csv'));
        $b = Keyword::where('keyword_key', 'học bổng b')->first();
        $this->assertNull($b->volume);
        $this->assertNull($b->difficulty);
        $this->assertSame('csv', $b->source);
        $this->assertSame(100, Keyword::where('keyword_key', 'học bổng a')->value('volume'));
        // lần nhập lại: tất cả trùng
        $again = (new CsvKeywordParser)->parse("keyword\nHọc bổng A\n", $svc->existingKeywords());
        $this->assertCount(1, $again['duplicates']);
        $this->assertSame(0, $svc->addRows([['keyword' => 'HỌC  BỔNG a', 'volume' => 1, 'intent' => 'informational']]));
        $this->assertSame(2, Keyword::count());
    }

    public function test_them_tay_ghi_nguon_tay_va_chan_trung(): void
    {
        $svc = KeywordService::make();
        $svc->addManual('Du học Nhật', '', 'commercial');
        $k = Keyword::first();
        $this->assertSame('tay', $k->source);
        $this->assertNull($k->volume);
        $this->assertSame('commercial', $k->intent);
        $this->expectException(\InvalidArgumentException::class);
        $svc->addManual('du  học nhật', '', 'commercial');
    }

    public function test_hop_dong_repository_va_cum(): void
    {
        $svc = KeywordService::make();
        $svc->addRows([['keyword' => 'a1', 'volume' => 5, 'intent' => 'informational'], ['keyword' => 'a2', 'volume' => null, 'intent' => 'informational'], ['keyword' => 'b1', 'volume' => null, 'intent' => 'commercial']]);
        $this->assertGreaterThan(0, $svc->propose());
        $repo = $svc->repo();
        $item = $repo->all()[0];
        foreach (['id', 'keyword', 'volume', 'difficulty', 'intent', 'source', 'cluster'] as $key) {
            $this->assertArrayHasKey($key, $item);
        }
        $repo->saveProposals([['id' => 1, 'name' => 'Nhóm A', 'intent' => 'informational', 'keywords' => ['a1', 'a2']]]);
        $svc->accept(1);
        $this->assertSame([['name' => 'Nhóm A', 'intent' => 'informational', 'keywords' => ['a1', 'a2']]], $repo->clusters());
        $this->assertSame('Nhóm A', $repo->all()[0]['cluster']);
        $this->assertNull($repo->all()[2]['cluster']);
        $svc->renameCluster('Nhóm A', 'Nhóm Z');
        $this->assertSame('Nhóm Z', $repo->clusters()[0]['name']);
        $svc->delete($repo->all()[0]['id']);
        $this->assertSame(['a2'], $repo->clusters()[0]['keywords']);
    }

    public function test_cot_bai_cac_trang_thai(): void
    {
        $svc = KeywordService::make();
        $svc->addRows(array_map(fn ($k) => ['keyword' => $k, 'volume' => null, 'intent' => 'informational'], ['k none', 'k plan', 'k draft', 'k review', 'k pub']));
        $mk = fn (string $k, string $st) => Article::create(['website_id' => $this->site->id, 'primary_keyword' => strtoupper($k), 'workflow_status' => $st]);
        $mk('k plan', 'planned');
        $mk('k draft', 'drafting');
        $mk('k review', 'needs_review');
        $mk('k pub', 'published');
        $l = KeywordArticleLinks::for($this->site, ['k none', 'k plan', 'k draft', 'k review', 'k pub']);
        $this->assertSame('Chưa có', $l['k none']['label']);
        $this->assertSame('Đang viết', $l['k draft']['label']);
        $this->assertSame('Chờ duyệt', $l['k review']['label']);
        $this->assertSame('Đã đăng', $l['k pub']['label']);
        $this->assertNotNull($l['k plan']['article_id']);
        $rows = (new KeywordsPage)->keywordRows($svc->repo()->all(), $this->site);
        $st = collect($rows)->mapWithKeys(fn ($r) => [$r['keyword'] => $r['status']['text']])->all();
        $this->assertSame('Chưa dùng', $st['k none']);
        $this->assertSame('Trong kế hoạch', $st['k plan']);
        $this->assertSame('Đã có bài', $st['k draft']);
        $this->assertSame('Đã có bài', $st['k pub']);
        $this->assertSame(ContentPlanPage::getUrl(), $rows[0]['status']['href']);
        $this->assertNull($rows[0]['volume']);
    }

    public function test_them_vao_ke_hoach_gan_keyword_id_va_khong_trung(): void
    {
        $svc = KeywordService::make();
        $svc->addRows([['keyword' => 'học bổng a', 'volume' => null, 'intent' => 'informational'], ['keyword' => 'học bổng b', 'volume' => null, 'intent' => 'informational']]);
        $repo = $svc->repo();
        $repo->saveClusters([['name' => 'HB', 'intent' => 'informational', 'keywords' => ['học bổng a', 'học bổng b']]]);
        $cluster = $repo->clusters()[0];
        $res = (new PlanActions)->createFromCluster($this->writer, $this->site, $cluster, ['học bổng a'], null, []);
        $this->assertSame('created', $res[0]['status']);
        $a = Article::find($res[0]['article_id']);
        $kw = Keyword::where('keyword_key', 'học bổng a')->first();
        $this->assertSame($kw->id, $a->keyword_id);
        $this->assertSame($kw->cluster_id, $a->cluster_id);
        $this->assertSame('keyword', $a->origin);
        $this->assertSame('new', $a->kind);
        $this->assertSame('planned', $kw->fresh()->status);
        $this->assertSame('new', Keyword::where('keyword_key', 'học bổng b')->value('status'));
        $again = (new PlanActions)->createFromCluster($this->writer, $this->site, $cluster, ['học bổng a'], null, []);
        $this->assertSame('duplicate', $again[0]['status']);
        $this->assertSame(1, Article::count());
        // bắt đầu viết -> has_article
        $a->update(['workflow_status' => 'drafting']);
        $this->assertSame('has_article', $kw->fresh()->status);
    }

    public function test_trang_tu_khoa_hien_thi_du_lieu_that_va_quyen(): void
    {
        KeywordService::make()->addManual('đăng ký thi', '', 'transactional');
        $t = Livewire::test(KeywordsPage::class)->assertSee('Dữ liệu thật');
        $this->assertContains('đăng ký thi', array_column($t->viewData('kwRows'), 'keyword'));
        $viewer = User::create(['name' => 'v', 'email' => 'v@x.vn', 'password' => 'secret123', 'role' => 'viewer', 'is_active' => true]);
        $this->actingAs($viewer);
        $this->assertFalse(KeywordsPage::canAccess());
    }

    public function test_ke_hoach_noi_dung_doc_cum_that(): void
    {
        $svc = KeywordService::make();
        $svc->addRows([['keyword' => 'x1', 'volume' => null, 'intent' => 'informational']]);
        $svc->repo()->saveClusters([['name' => 'CX', 'intent' => 'informational', 'keywords' => ['x1']]]);
        Livewire::test(ContentPlanPage::class)->assertSee('CX');
    }
}
