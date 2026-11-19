<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\KeywordsPage;
use App\Filament\Support\Keywords\CsvKeywordParser;
use App\Filament\Support\Keywords\KeywordSanitizer;
use App\Filament\Support\Keywords\KeywordService;
use App\Models\Keyword;
use App\Models\User;
use App\Models\Website;
use App\Services\Keywords\KeywordResearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Từ khóa ở mọi lối vào qua một bộ lọc; CSV nhận tiêu đề Việt/không tiêu đề; chữ kỹ thuật không lộ cho người dùng. */
class KeywordsInputSafetyTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        config(['studio.keywords_real' => true, 'keywords.dataforseo.login' => null, 'keywords.dataforseo.password' => null]);
        $this->site = Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->actingAs(User::create(['name' => 'w', 'email' => 'w@x.vn', 'password' => 'secret123', 'role' => 'writer', 'is_active' => true]));
    }

    public static function dangerous(): array
    {
        return [['<script>alert(1)</script>'], ['abc <img src=x onerror=alert(1)>'], ['javascript:alert(1)'], ['{{ 7*7 }}'], ['x onclick=foo()'], ['&lt;b&gt;'], ['<?php echo 1 ?>']];
    }

    /** @dataProvider dangerous */
    public function test_bo_loc_tu_choi_chuoi_nguy_hiem_kem_ly_do(string $raw): void
    {
        $r = KeywordSanitizer::check($raw);
        $this->assertNull($r['keyword']);
        $this->assertStringContainsString('từ chối', $r['reason']);
    }

    public function test_bo_loc_giu_nghia_tu_khoa_binh_thuong_va_bo_ky_tu_dieu_khien(): void
    {
        $this->assertSame('học bổng du học Pháp', KeywordSanitizer::check("  học\u{200B} bổng\tdu học\x07  Pháp ")['keyword']);
        $this->assertSame('giá < 5 triệu', KeywordSanitizer::check('giá < 5 triệu')['keyword']);
        $this->assertNull(KeywordSanitizer::check(str_repeat('a', 300))['keyword']);
        $this->assertNull(KeywordSanitizer::check("\x01 ")['keyword']);
    }

    public function test_csv_tu_choi_dong_co_the_html_va_van_nhan_dong_hop_le(): void
    {
        $r = (new CsvKeywordParser)->parse("keyword,volume\n<script>alert(1)</script>,10\nmua giày,1.200\n");
        $this->assertCount(1, $r['rows']);
        $this->assertSame(1200, $r['rows'][0]['volume']);
        $this->assertCount(1, $r['errors']);
        $this->assertSame(2, $r['errors'][0]['line']);
        $this->assertStringContainsString('thẻ HTML', $r['errors'][0]['reason']);
    }

    public function test_csv_tieu_de_tieng_viet_dau_cham_phay_va_tab(): void
    {
        $r = (new CsvKeywordParser)->parse("Từ khóa;Lượt tìm;Ý định\nmua giày;1.200;thương mại\n");
        $this->assertNull($r['fatal']);
        $this->assertSame([['line' => 2, 'keyword' => 'mua giày', 'volume' => 1200, 'intent' => 'commercial']], $r['rows']);
        $r = (new CsvKeywordParser)->parse("kw\tsearch volume\nmua giày\t900\n");
        $this->assertSame(900, $r['rows'][0]['volume']);
        $this->assertStringContainsString('tiêu đề', $r['note']);
    }

    public function test_khong_tieu_de_thi_ca_tep_la_danh_sach_tu_khoa(): void
    {
        $r = (new CsvKeywordParser)->parse("mua giày chạy bộ\nhọc bổng du học Pháp,720\nmua nhà, giá rẻ\n");
        $this->assertNull($r['fatal']);
        $this->assertSame(['mua giày chạy bộ', 'học bổng du học Pháp', 'mua nhà, giá rẻ'], array_column($r['rows'], 'keyword'));
        $this->assertSame([null, 720, null], array_column($r['rows'], 'volume'));
        $this->assertStringContainsString('mỗi dòng là một từ khóa', $r['note']);
    }

    public function test_nhap_tay_va_kho_tu_choi_the_html(): void
    {
        $s = KeywordService::make();
        try {
            $s->addManual('<script>alert(1)</script>', '', 'informational');
            $this->fail('phải ném lỗi');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('thẻ HTML', $e->getMessage());
        }
        $this->assertSame(0, $s->addRows([['keyword' => '<b>x</b>', 'volume' => null, 'intent' => 'informational']], 'csv'));
        $this->assertSame(0, Keyword::count());
    }

    public function test_ai_de_xuat_bo_tu_khoa_co_the(): void
    {
        $r = (new KeywordResearchService)->validate(['keywords' => [['keyword' => '<script>alert(1)</script>'], ['keyword' => 'giày chạy bộ tốt', 'intent' => 'commercial']]]);
        $this->assertSame(['giày chạy bộ tốt'], array_column($r['items'], 'keyword'));
        $this->assertSame(1, $r['invalid']);
    }

    public function test_man_hinh_khoa_nut_xac_nhan_khi_khong_co_dong_hop_le_va_noi_cach_hieu(): void
    {
        $t = Livewire::test(KeywordsPage::class)->set('csvText', "<script>alert(1)</script>\n")->call('previewCsv');
        $html = $t->html();
        $this->assertStringContainsString('Xác nhận nhập 0 từ khóa', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*disabled[^>]*>\s*(?:<[^>]+>\s*)*Xác nhận nhập 0/s', $html);
        $t->assertSee('Tệp không có dòng tiêu đề nhận ra được')->assertSee('thẻ HTML');
        $t->call('confirmImport');
        $this->assertSame(0, Keyword::count());
        $t->set('csvText', "mua giày\nbán ghế\n")->call('previewCsv')->call('confirmImport');
        $this->assertSame(2, Keyword::count());
    }

    public function test_thong_bao_khong_lo_ten_bien_moi_truong_va_nhan_tieng_viet(): void
    {
        Keyword::create(['website_id' => $this->site->id, 'keyword' => 'a', 'keyword_key' => 'a', 'intent' => 'informational', 'source' => 'csv', 'status' => 'new']);
        config(['studio.demo' => false]); // không dùng số liệu giả lập: nguồn lượt tìm thật sự "chưa kết nối"
        $t = Livewire::test(KeywordsPage::class);
        $t->assertSee('Chưa kết nối nguồn số liệu lượt tìm. Liên hệ quản trị để kết nối')->assertSee('Lượt tìm (tùy chọn)')->assertDontSee('Volume (tùy chọn)');
        $this->assertStringNotContainsString('DATAFORSEO', \App\Services\Keywords\KeywordMetricsResolver::HOWTO);
        $this->assertStringNotContainsString('DATAFORSEO', \App\Services\Keywords\Serp\SerpInsights::HOWTO);
        $this->assertStringContainsString('DATAFORSEO_LOGIN', \App\Services\Keywords\KeywordMetricsResolver::ADMIN_HINT);
    }

    public function test_xoa_tu_khoa_co_hop_xac_nhan_va_noi_ro_khi_dang_gan_bai(): void
    {
        $k = Keyword::create(['website_id' => $this->site->id, 'keyword' => 'a', 'keyword_key' => 'a', 'intent' => 'informational', 'source' => 'csv', 'status' => 'new']);
        $t = Livewire::test(KeywordsPage::class)->call('confirmDeleteKeyword', $k->id);
        $this->assertSame(1, Keyword::count(), 'chưa xác nhận thì chưa xóa');
        $this->assertStringContainsString('chưa gắn với bài nào', $t->instance()->deleteWarning($k->id));
        $a = \App\Models\Article::create(['website_id' => $this->site->id, 'keyword_id' => $k->id, 'primary_keyword' => 'a', 'status' => 'draft', 'title' => 'A']);
        $this->assertStringContainsString('đang gắn với một bài viết', $t->instance()->deleteWarning($k->id));
    }
}
