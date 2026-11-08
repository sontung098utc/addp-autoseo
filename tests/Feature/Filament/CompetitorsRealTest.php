<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\CompetitorsPage;
use App\Filament\Support\Competitors\CompetitorDomain;
use App\Filament\Support\Competitors\CompetitorStore;
use App\Filament\Support\Competitors\CsvGapParser;
use App\Filament\Support\Competitors\PriorityScorer;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\BrandProfile;
use App\Models\Competitor;
use App\Models\Keyword;
use App\Models\KeywordGap;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\Quality\ContentQualityChecker;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Unit\QualityFixtures;

/** AG-S24: đối thủ + khoảng trống THẬT (bảng competitors, keyword_gaps), nối Từ khóa và luật BAN01. */
class CompetitorsRealTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        config(['studio.competitors_real' => true]);
        $this->site = Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://blog.company.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function user(string $role = 'approver'): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function comp(string $domain = 'doithu.vn', string $kind = 'truc_tiep', ?string $brand = null): Competitor
    {
        return CompetitorStore::add($this->site, $this->user(), $domain, $brand, $kind);
    }

    public function test_domain_noi_bo_bi_chan(): void
    {
        foreach (['127.0.0.1', 'localhost', 'a.local', '169.254.169.254', 'http://user:pw@x.vn', 'x.vn:8080', 'blog.company.vn'] as $bad) {
            try {
                CompetitorStore::add($this->site, $this->user(), $bad, null, 'truc_tiep');
                $this->fail("$bad lẽ ra bị chặn");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, Competitor::count());
        $this->assertSame('doithu.vn', CompetitorDomain::validate('HTTPS://www.DoiThu.vn/x', []));
    }

    public function test_unique_domain_theo_website(): void
    {
        $this->comp();
        $this->expectException(InvalidArgumentException::class);
        $this->comp('www.doithu.vn');
    }

    public function test_unique_gap_o_muc_db(): void
    {
        $c = $this->comp();
        $row = ['website_id' => $this->site->id, 'competitor_id' => $c->id, 'keyword' => 'học bổng'];
        KeywordGap::create($row);
        $this->expectException(QueryException::class);
        KeywordGap::create($row);
    }

    public function test_csv_vao_bang_bao_dong_loi_va_trung(): void
    {
        $this->comp();
        $csv = "keyword,competitor_domain,competitor_rank,our_rank,volume\n"
            ."học bổng,doithu.vn,3,,1.200\n"
            ."Học  bổng,doithu.vn,5,,100\n"          // trùng (chuẩn hóa)
            ."du học Pháp,doithu.vn,2,40,\n"          // không volume
            ."du học Úc,127.0.0.1,2,,10\n"            // IP nội bộ
            ."du học Mỹ,chuaco.vn,2,,10\n"            // đối thủ chưa thêm
            ."du học Anh,doithu.vn,abc,,10\n"         // hạng sai
            .",doithu.vn,1,,1\n";                     // thiếu từ khóa
        $r = CompetitorStore::previewCsv($this->site, $csv);
        $this->assertNull($r['fatal']);
        $this->assertCount(2, $r['rows']);
        $this->assertCount(1, $r['duplicates']);
        $this->assertCount(4, $r['errors']);
        $this->assertSame(2, CompetitorStore::importRows($this->site, $r['rows']));
        $this->assertSame(2, KeywordGap::count());
        // nhập lại: cả hai thành trùng
        $r2 = CompetitorStore::previewCsv($this->site, $csv);
        $this->assertCount(0, $r2['rows']);
        $this->assertCount(3, $r2['duplicates']);
    }

    public function test_csv_gioi_han_va_dinh_dang(): void
    {
        $p = new CsvGapParser;
        $this->assertNotNull($p->parse("abc\nx", [])['fatal']);
        $this->assertNotNull($p->parse(str_repeat('a', 1048577), [])['fatal']);
        $this->assertNotNull($p->parse("keyword\n\xFF\xFE", [])['fatal']);
        $this->assertNotNull($p->parse("keyword\n".str_repeat("k\n", 5001), [])['fatal']);
    }

    public function test_khong_dien_so_gia_khi_thieu_luong_tim(): void
    {
        $c = $this->comp();
        $r = CompetitorStore::previewCsv($this->site, "keyword,competitor_domain,volume\na b c,doithu.vn,\nd e f,doithu.vn,500\n");
        CompetitorStore::importRows($this->site, $r['rows']);
        $noVol = KeywordGap::where('keyword', 'a b c')->first();
        $this->assertNull($noVol->volume);
        $this->assertNull($noVol->priority_score);
        $this->assertNotNull(KeywordGap::where('keyword', 'd e f')->first()->priority_score);
        $this->assertNull(PriorityScorer::scoreReal(null, 1, 1, 100));
    }

    public function test_them_tu_khoa_tao_keyword_dung_nguon_khong_trung(): void
    {
        $this->comp();
        $this->comp('khac.vn');
        CompetitorStore::importRows($this->site, CompetitorStore::previewCsv($this->site, "keyword,competitor_domain,volume\nhọc bổng,doithu.vn,900\nHọc bổng,khac.vn,\n")['rows']);
        Keyword::create(['website_id' => $this->site->id, 'keyword' => 'sẵn có', 'keyword_key' => 'sẵn có', 'source' => 'tay']);
        $gap = KeywordGap::where('keyword', 'học bổng')->first();
        $kw = CompetitorStore::addKeyword($this->site, $this->user(), $gap->id);

        $this->assertSame('competitor', $kw->source);
        $this->assertSame(900, $kw->volume);
        $this->assertSame(1, Keyword::where('keyword_key', 'học bổng')->count());
        foreach (KeywordGap::all() as $g) {
            $this->assertSame('da_them_tu_khoa', $g->status);
            $this->assertSame($kw->id, $g->keyword_id);
        }
        // bấm lại bị từ chối, không tạo bản sao
        try {
            CompetitorStore::addKeyword($this->site, $this->user(), $gap->id);
            $this->fail();
        } catch (InvalidArgumentException) {
        }
        $this->assertSame(2, Keyword::count());

        // gap trùng khóa đã có trong kho: chỉ nối, không tạo mới
        KeywordGap::create(['website_id' => $this->site->id, 'competitor_id' => null, 'keyword' => 'Sẵn  có']);
        $g = KeywordGap::where('keyword', 'Sẵn  có')->first();
        $k2 = CompetitorStore::addKeyword($this->site, $this->user(), $g->id);
        $this->assertSame(2, Keyword::count());
        $this->assertSame('tay', $k2->source);
    }

    public function test_ban01_nhan_ten_thuong_hieu_doi_thu_truc_tiep(): void
    {
        $this->comp('congtyz.com.vn', 'truc_tiep', 'Công Ty Z');
        $this->comp('baochi.vn', 'bao_chi', 'Báo Chí Lớn');
        BrandProfile::create(['website_id' => $this->site->id, 'avoid_competitors' => ['HồSơX']]);
        $a = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'học bổng toàn phần', 'workflow_status' => 'planned']);
        $mk = function (string $extra) use ($a) {
            $attrs = QualityFixtures::goodAttrs('học bổng toàn phần');
            $attrs['html_content'] .= '<p>'.$extra.'</p>';

            return (new ContentQualityChecker)->check(new ArticleVersion($attrs + ['article_id' => $a->id]), $a);
        };
        $this->assertContains('BAN01', $mk('Chúng tôi tốt hơn Công Ty Z.')->blockingCodes());
        $this->assertContains('BAN01', $mk('So với congtyz nhiều lắm.')->blockingCodes());
        $this->assertContains('BAN01', $mk('Khác HồSơX.')->blockingCodes());
        $this->assertNotContains('BAN01', $mk('Theo Báo Chí Lớn đưa tin.')->blockingCodes());
        // website khác không bị ảnh hưởng
        $other = Website::create(['name' => 'B', 'cms_type' => 'wordpress', 'base_url' => 'https://b.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $a->website_id = $other->id;
        $this->assertNotContains('BAN01', $mk('Công Ty Z ở đây.')->blockingCodes());
    }

    public function test_trang_that_them_nhap_va_thao_tac_gap(): void
    {
        $this->actingAs($this->user());
        $t = Livewire::test(CompetitorsPage::class)
            ->set('newDomain', 'doithu.vn')->set('newBrand', 'DoiThu')->set('newKind', 'truc_tiep')->call('addCompetitor')
            ->assertSee('doithu.vn')
            ->set('csvText', "keyword,competitor_domain,volume\nhọc bổng,doithu.vn,300\n")->call('importCsv');
        $this->assertSame(1, KeywordGap::count());
        $id = KeywordGap::value('id');
        $t->call('addGapKeyword', $id);
        $this->assertSame('competitor', Keyword::first()->source);
        $t->call('ignoreGap', $id); // đã thêm: không đổi
        $this->assertSame('da_them_tu_khoa', KeywordGap::first()->status);
        $t->call('removeCompetitorById', Competitor::value('id'));
        $this->assertSame(0, Competitor::count());
    }

    public function test_quyen_writer_bi_chan(): void
    {
        $this->actingAs($this->user('writer'));
        Livewire::test(CompetitorsPage::class)->assertForbidden();
        $this->actingAs($this->user('writer'))->get('/admin/doi-thu')->assertForbidden();
    }

    public function test_approver_mo_trang_that(): void
    {
        $this->comp();
        $this->actingAs($this->user('approver'))->get('/admin/doi-thu')->assertOk()->assertSee('doithu.vn')->assertSee('data-co-state="real"', false);
    }
}
