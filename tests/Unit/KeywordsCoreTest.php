<?php

namespace Tests\Unit;

use App\Filament\Support\Keywords\CsvKeywordParser;
use App\Filament\Support\Keywords\KeywordService;
use App\Filament\Support\Keywords\MockClusterer;
use App\Filament\Support\Demo\KeywordsDemoData;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class KeywordsCoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_parser_bom_va_dau_cham_phay(): void
    {
        $r = (new CsvKeywordParser)->parse("\xEF\xBB\xBFkeyword;volume;intent\nhọc bổng  Nhật;1.900;Thông tin\n\ntuyển sinh;50;commercial\n");
        $this->assertNull($r['fatal']);
        $this->assertCount(2, $r['rows']);
        $this->assertSame('học bổng Nhật', $r['rows'][0]['keyword']);
        $this->assertSame(1900, $r['rows'][0]['volume']);
        $this->assertSame('informational', $r['rows'][0]['intent']);
        $this->assertSame(4, $r['rows'][1]['line']);
    }

    public function test_parser_loi_va_trung(): void
    {
        $csv = "keyword,volume,intent\nA a,10,informational\na  A,5,commercial\nB,abc,commercial\nC,1,bậy\n,3,commercial\nđã có,1,commercial\n";
        $r = (new CsvKeywordParser)->parse($csv, ['ĐÃ CÓ']);
        $this->assertCount(1, $r['rows']);
        $this->assertCount(2, $r['duplicates']);
        $this->assertCount(3, $r['errors']);
        $this->assertSame(4, $r['errors'][0]['line']);
        $this->assertStringContainsString('Volume', $r['errors'][0]['reason']);
        $this->assertStringContainsString('Ý định', $r['errors'][1]['reason']);
    }

    public function test_parser_thieu_cot_gioi_han(): void
    {
        $p = new CsvKeywordParser;
        $this->assertNotNull($p->parse("tu,volume\nx,1")['fatal']);
        $this->assertNotNull($p->parse('')['fatal']);
        $many = "keyword\n".implode("\n", array_map(fn ($i) => "k$i", range(1, 5001)));
        $this->assertStringContainsString('5.000', (string) $p->parse($many)['fatal']);
        $ok = "keyword\n".implode("\n", array_map(fn ($i) => "k$i", range(1, 5000)));
        $this->assertNull($p->parse($ok)['fatal']);
        $this->assertStringContainsString('1 MB', (string) $p->parse("keyword\n".str_repeat('x', CsvKeywordParser::MAX_BYTES))['fatal']);
    }

    public function test_clusterer_xac_dinh_moi_tu_khoa_mot_cum(): void
    {
        $kws = array_column(array_map(fn ($r) => ['k' => $r[0]], KeywordsDemoData::keywords()), 'k');
        $a = (new MockClusterer)->cluster($kws);
        $b = (new MockClusterer)->cluster(array_reverse($kws));
        $this->assertSame($a, $b);
        $all = array_merge(...array_column($a, 'keywords'));
        sort($all);
        $expected = $kws;
        sort($expected);
        $this->assertSame($expected, $all);
        $this->assertGreaterThan(3, count($a));
    }

    public function test_luong_nhan_gop_tach_doi_ten_giu_dong_bo(): void
    {
        $s = KeywordService::make();
        $this->assertNotEmpty($s->repo()->all());
        $n = $s->propose();
        $this->assertGreaterThan(1, $n);
        $props = $s->repo()->proposals();
        $s->accept($props[0]['id']);
        $this->assertCount(1, $s->repo()->clusters());
        $name = $s->repo()->clusters()[0]['name'];
        $this->assertSame(count($s->repo()->clusters()[0]['keywords']), count(array_filter($s->repo()->all(), fn ($r) => $r['cluster'] === $name)));

        $s->renameCluster($name, 'Tên mới');
        $this->assertSame('Tên mới', $s->repo()->clusters()[0]['name']);
        $this->assertNotEmpty(array_filter($s->repo()->all(), fn ($r) => $r['cluster'] === 'Tên mới'));

        $props = $s->repo()->proposals();
        $s->mergeProposals($props[0]['id'], $props[1]['id']);
        $this->assertCount(count($props) - 1, $s->repo()->proposals());
        $merged = $s->repo()->proposals()[0];
        if (count($merged['keywords']) >= 2) {
            $s->splitProposal($merged['id'], [$merged['keywords'][0]], 'Tách ra');
            $this->assertCount(count($props), $s->repo()->proposals());
        }
    }

    public function test_xoa_tu_khoa_va_them_tay_trung(): void
    {
        $s = KeywordService::make();
        $s->addManual('  Từ  mới ', '', 'commercial');
        $this->expectExceptionMessage('đã có');
        try {
            $s->addManual('từ mới', '', 'commercial');
        } finally {
            $id = collect($s->repo()->all())->firstWhere('keyword', 'Từ mới')['id'];
            $s->delete($id);
            $this->assertNull(collect($s->repo()->all())->firstWhere('keyword', 'Từ mới'));
        }
    }

    public function test_production_khong_co_du_lieu_mau(): void
    {
        $this->app['env'] = 'production';
        Cache::flush();
        $this->assertSame([], KeywordService::make()->repo()->all());
    }
}
