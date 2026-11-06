<?php

namespace Tests\Unit;

use App\Filament\Support\Demo\SeoAuditDemoData;
use App\Filament\Support\SeoAudit\AuditReport;
use App\Filament\Support\SeoAudit\OnPageAnalyzer;
use PHPUnit\Framework\TestCase;

class SeoAuditAnalyzerTest extends TestCase
{
    private const SENT = 'một hai ba bốn năm sáu bảy tám chín mười ';

    private function page(string $head = '', string $body = '', string $url = '/a', bool $sitemap = true, int $status = 200): array
    {
        $words = str_repeat(self::SENT, 30); // 300 từ
        $html = '<html><head>'.$head.'</head><body>'.$body.'<p>'.$words.'</p></body></html>';

        return ['url' => $url, 'status' => $status, 'html' => $html, 'in_sitemap' => $sitemap];
    }

    private function good(string $url = '/a', string $title = 'Tiêu đề hợp lệ dài đủ ba mươi ký tự'): array
    {
        return $this->page('<title>'.$title.'</title><meta name="description" content="Mô tả '.$url.'"><link rel="canonical" href="https://x.vn'.$url.'">', '<h1>Chủ đề</h1>', $url);
    }

    /** @return list<string> */
    private function types(array $pages, ?string $url = null): array
    {
        $t = [];
        foreach ((new OnPageAnalyzer())->analyze($pages) as $f) {
            if ($url === null || $f['url'] === $url) {
                $t[] = $f['type'];
            }
        }
        sort($t);

        return $t;
    }

    public function test_trang_tot_khong_loi(): void
    {
        $this->assertSame([], $this->types([$this->good()]));
    }

    public function test_thieu_va_rong_title(): void
    {
        $p = $this->page('<meta name="description" content="m"><link rel="canonical" href="/a">', '<h1>x</h1>');
        $this->assertSame(['missing_title'], $this->types([$p]));
        $p2 = $this->page('<title>  </title><meta name="description" content="m"><link rel="canonical" href="/a">', '<h1>x</h1>');
        $this->assertSame(['missing_title'], $this->types([$p2]));
    }

    public function test_bien_do_dai_title(): void
    {
        $mk = fn (int $n) => $this->good('/a', str_repeat('đ', $n));
        $this->assertSame(['title_short'], $this->types([$mk(29)]));
        $this->assertSame([], $this->types([$mk(30)]));
        $this->assertSame([], $this->types([$mk(60)]));
        $this->assertSame(['title_long'], $this->types([$mk(61)]));
        $f = (new OnPageAnalyzer())->analyze([$mk(61)])[0];
        $this->assertSame('61 ký tự', $f['measured']);
        $this->assertSame('/a', $f['url']);
    }

    public function test_bien_do_so_tu(): void
    {
        $mk = fn (int $n) => ['url' => '/a', 'status' => 200, 'in_sitemap' => true, 'html' => '<html><head><title>'.str_repeat('t', 40).'</title><meta name="description" content="m"><link rel="canonical" href="/a"></head><body><h1>x</h1><p>'.trim(str_repeat('từ ', $n - 1)).'</p></body></html>'];
        $this->assertSame(['thin_page'], $this->types([$mk(299)]));
        $this->assertSame([], $this->types([$mk(300)]));
    }

    public function test_meta_canonical_h1_alt_noindex(): void
    {
        $p = $this->page('<title>'.str_repeat('t', 40).'</title><meta name="robots" content="NOINDEX, follow">', '<h1>a</h1><h1>b</h1><img src="/1.jpg"><img src="/2.jpg" alt="">');
        $this->assertSame(['image_no_alt', 'missing_canonical', 'missing_meta', 'multiple_h1', 'noindex_in_sitemap'], $this->types([$p]));
        $f = collect((new OnPageAnalyzer())->analyze([$p]))->keyBy('type');
        $this->assertSame('1 ảnh thiếu alt', $f['image_no_alt']['measured']);
        $this->assertStringContainsString('/1.jpg', $f['image_no_alt']['evidence']);
        $this->assertSame('2 thẻ h1', $f['multiple_h1']['measured']);
        $q = $this->page('<title>'.str_repeat('t', 40).'</title><meta name="description" content="m"><link rel="canonical" href="/a">', '');
        $this->assertSame(['missing_h1'], $this->types([$q]));
    }

    public function test_noindex_ngoai_sitemap_khong_la_loi(): void
    {
        $p = $this->good();
        $p['html'] = str_replace('</head>', '<meta name="robots" content="noindex"></head>', $p['html']);
        $p['in_sitemap'] = false;
        $this->assertSame([], $this->types([$p]));
    }

    public function test_trung_title_va_meta_khong_dem_hai_lan(): void
    {
        $a = $this->good('/a', 'Cùng một tiêu đề đủ dài để hợp lệ');
        $b = $this->good('/b', 'cùng  một TIÊU ĐỀ đủ dài để hợp lệ');
        $c = $this->good('/c', 'Tiêu đề khác hoàn toàn và đủ dài');
        $r = (new OnPageAnalyzer())->analyze([$a, $b, $c, $a]); // /a xuất hiện hai lần trong đầu vào
        $dup = array_values(array_filter($r, fn ($f) => $f['type'] === 'duplicate_title'));
        $this->assertCount(2, $dup);
        $this->assertSame(['/a', '/b'], array_column($dup, 'url'));
        $this->assertStringContainsString('/b', $dup[0]['evidence']);
        $this->assertSame([], array_filter($r, fn ($f) => $f['type'] === 'duplicate_meta'));
    }

    public function test_mot_trang_khong_tu_trung(): void
    {
        $this->assertSame([], $this->types([$this->good('/a'), $this->good('/b', 'Tiêu đề khác cũng đủ dài ba mươi')]));
    }

    public function test_lien_ket_gay(): void
    {
        $p = $this->page('<title>'.str_repeat('t', 40).'</title><meta name="description" content="m"><link rel="canonical" href="/a">', '<h1>x</h1><a href="/chet.pdf">Tải</a><a href="/chet.pdf#x">Lại</a><a href="/song">ok</a><a href="https://ngoai.vn/chet.pdf">ngoài</a><a href="mailto:a@b.vn">m</a>');
        $dead = ['url' => '/chet.pdf', 'status' => 404, 'html' => null, 'in_sitemap' => false];
        $live = $this->good('/song');
        $r = (new OnPageAnalyzer())->analyze([$p, $dead, $live]);
        $b = array_values(array_filter($r, fn ($f) => $f['type'] === 'broken_link'));
        $this->assertCount(1, $b); // cùng đích chỉ ghi một lần/trang
        $this->assertSame('/a', $b[0]['url']);
        $this->assertSame('/chet.pdf', $b[0]['measured']);
        $this->assertStringContainsString('<a href="/chet.pdf">Tải</a>', $b[0]['evidence']);
    }

    public function test_lien_ket_tuong_doi_va_trang_khong_phai_200_bi_bo_qua(): void
    {
        $p = $this->page('<title>'.str_repeat('t', 40).'</title><meta name="description" content="m"><link rel="canonical" href="/a">', '<h1>x</h1><a href="../chet">c</a>', '/muc/trang');
        $dead = ['url' => '/chet', 'status' => 404, 'html' => '<html></html>', 'in_sitemap' => false];
        $r = (new OnPageAnalyzer())->analyze([$p, $dead]);
        $this->assertSame(['broken_link'], array_column($r, 'type')); // trang 404 không bị phân tích
    }

    public function test_html_hong_rong_va_tieng_viet_khong_nem_loi(): void
    {
        $an = new OnPageAnalyzer();
        foreach (['', '   ', '<<<>>', '<html><head><title>Chưa đóng', "\xff\xfe<p>", '<p>Học bổng toàn phần ĐẠI HỌC &amp; Ơ</p>'] as $h) {
            $r = $an->analyze([['url' => '/x', 'status' => 200, 'html' => $h, 'in_sitemap' => true]]);
            $this->assertIsArray($r);
            foreach ($r as $f) {
                $this->assertSame('/x', $f['url']);
                $this->assertNotSame('', $f['evidence']);
            }
        }
        $this->assertContains('missing_title', array_column($an->analyze([['url' => '/x', 'status' => 200, 'html' => '']]), 'type'));
        $v = $an->analyze([$this->good('/v', 'Học bổng toàn phần Đại học Ơ Ư năm hai nghìn')]);
        $this->assertSame([], $v);
    }

    public function test_gom_nhom_muc_va_xu_huong(): void
    {
        $f = [
            ['type' => 'image_no_alt', 'url' => '/1', 'measured' => 'm', 'evidence' => 'e'],
            ['type' => 'broken_link', 'url' => '/1', 'measured' => 'm', 'evidence' => 'e'],
            ['type' => 'broken_link', 'url' => '/1', 'measured' => 'm2', 'evidence' => 'e'],
            ['type' => 'missing_meta', 'url' => '/2', 'measured' => 'm', 'evidence' => 'e'],
            ['type' => 'missing_title', 'url' => '/3', 'measured' => 'm', 'evidence' => 'e'],
        ];
        $g = AuditReport::groups($f, ['broken_link' => 3, 'missing_meta' => 0]);
        $this->assertSame(['broken_link', 'missing_title', 'missing_meta', 'image_no_alt'], array_column($g, 'type'));
        $this->assertSame(['P0', 'P0', 'P1', 'P2'], array_column($g, 'severity'));
        $this->assertSame(1, $g[0]['urls']);
        $this->assertSame(2, $g[0]['occurrences']);
        $this->assertSame([-2, 1, 1, 1], array_column($g, 'delta'));
        $this->assertNull(AuditReport::groups($f)[0]['delta']);
    }

    public function test_bang_muc_co_dinh(): void
    {
        $exp = ['noindex_in_sitemap' => 'P0', 'broken_link' => 'P0', 'missing_title' => 'P0', 'missing_meta' => 'P1', 'missing_canonical' => 'P1', 'duplicate_meta' => 'P1',
            'duplicate_title' => 'P1', 'thin_page' => 'P1', 'missing_h1' => 'P1', 'image_no_alt' => 'P2', 'title_long' => 'P2', 'title_short' => 'P2', 'multiple_h1' => 'P2'];
        foreach ($exp as $t => $s) {
            $this->assertSame($s, \App\Filament\Support\SeoAudit\IssueCatalog::severity($t), $t);
        }
        $this->assertCount(13, \App\Filament\Support\SeoAudit\IssueCatalog::types());
    }

    public function test_bo_trang_mau_cho_ra_du_13_nhom_va_xu_huong(): void
    {
        $an = new OnPageAnalyzer();
        $g1 = AuditReport::groups($an->analyze(SeoAuditDemoData::pages(1)), AuditReport::counts(AuditReport::groups($an->analyze(SeoAuditDemoData::pages(0)))));
        $this->assertCount(13, $g1);
        $by = array_column($g1, null, 'type');
        $this->assertSame(7, $by['broken_link']['urls']);
        $this->assertSame(2, $by['noindex_in_sitemap']['urls']);
        $this->assertLessThan(0, $by['missing_title']['delta']);
        $this->assertCount(40, SeoAuditDemoData::pages(1));
        // mức 2: noindex mới xuất hiện (xấu đi)
        $g2 = AuditReport::groups($an->analyze(SeoAuditDemoData::pages(2)), AuditReport::counts($g1));
        $this->assertGreaterThan(0, array_column($g2, null, 'type')['multiple_h1']['delta']);
    }
}
