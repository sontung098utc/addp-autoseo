<?php

namespace Tests\Unit;

use App\Models\ArticleVersion;
use App\Services\Content\Quality\ContentQualityChecker;
use App\Services\Content\Quality\FileBannedTerms;
use App\Services\Content\Quality\Rules\BannedTermsRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** AG-K13: từ cấm được kiểm bằng luật (P0), website là mặc định, chiến dịch chỉ thêm. */
class BannedTermsRuleTest extends TestCase
{
    use RefreshDatabase;

    private const KW = 'học bổng toàn phần';

    private function report(array $banned, array $attrs = [], array $overrides = []): array
    {
        $v = new ArticleVersion(array_merge(QualityFixtures::goodAttrs(self::KW), $attrs));
        $checker = new ContentQualityChecker([new BannedTermsRule(new FileBannedTerms($banned, $overrides))]);
        $r = $checker->check($v, null);

        return [$r, $r->results[0]];
    }

    public function test_khong_co_tu_cam_thi_dat(): void
    {
        [, $x] = $this->report(['doi_thu' => ['CongTyZ']]);

        $this->assertTrue($x->passed);
        $this->assertSame('BAN01', $x->code);
    }

    public function test_tu_cam_trong_noi_dung_la_p0_va_chan_bai(): void
    {
        [$r, $x] = $this->report(['doi_thu' => ['học bổng']]);

        $this->assertFalse($x->passed);
        $this->assertSame('P0', $x->severity);
        $this->assertTrue($x->isBlocking());
        $this->assertStringContainsString('học bổng (doi_thu)', (string) $x->excerpt);
        $this->assertTrue($r->hasBlocking());
    }

    public function test_kiem_ca_tieu_de_va_meta_khong_phan_biet_hoa_thuong(): void
    {
        [, $a] = $this->report(['x' => ['CAM KẾT']], ['title' => 'Cam kết đỗ 100%']);
        [, $b] = $this->report(['x' => ['giảm sốc']], ['meta_description' => 'Ưu đãi Giảm Sốc hôm nay']);

        $this->assertFalse($a->passed);
        $this->assertFalse($b->passed);
    }

    public function test_khop_nguyen_tu(): void
    {
        [, $x] = $this->report(['x' => ['học']], ['title' => 'Học bổng']);
        // "học" đứng riêng trong "Học bổng" nên khớp; "học" trong "đại học" cũng là từ riêng. Kiểm cụm là tiền tố của từ:
        [, $y] = $this->report(['x' => ['bổ']], ['title' => 'Học bổng']);

        $this->assertFalse($x->passed);
        $this->assertTrue($y->passed, '"bổ" không được khớp nửa từ "bổng"');
    }

    public function test_chien_dich_chi_them_khong_go_bo_tu_cam_cua_website(): void
    {
        $base = ['doi_thu' => ['học bổng']];
        [, $x] = $this->report($base, [], [['doi_thu' => ['abc']]]);
        [, $y] = $this->report([], ['title' => 'Bài abc xyz'], [['moi' => ['abc xyz']]]);

        $this->assertFalse($x->passed, 'từ cấm của website vẫn còn dù chiến dịch thêm nhóm');
        $this->assertFalse($y->passed, 'từ cấm do chiến dịch thêm có hiệu lực');
    }

    public function test_danh_sach_rong_mac_dinh_khong_chan_gi(): void
    {
        $v = new ArticleVersion(QualityFixtures::goodAttrs(self::KW));
        $r = (new ContentQualityChecker([new BannedTermsRule()]))->check($v, null);

        $this->assertTrue($r->results[0]->passed);
    }

    public function test_co_trong_bo_luat_mac_dinh(): void
    {
        $codes = array_map(fn ($r) => $r->code(), ContentQualityChecker::defaultRules());

        $this->assertContains('BAN01', $codes);
    }
}
