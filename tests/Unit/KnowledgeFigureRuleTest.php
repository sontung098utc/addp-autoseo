<?php

namespace Tests\Unit;

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\KnowledgeItem;
use App\Models\Website;
use App\Services\Content\Quality\ContentQualityChecker;
use App\Services\Content\Quality\Rules\KnowledgeFigureRule;
use App\Services\Content\Quality\RuleResult;
use App\Services\Knowledge\KnowledgeDedupe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** AG-S21: luật KB01 — số liệu so với kho: đúng / sai (P1, không P0) / không đối chiếu được (thông tin, không phạt). Tất định, không AI. */
class KnowledgeFigureRuleTest extends TestCase
{
    use RefreshDatabase;

    private function atom(Website $w, array $a = []): KnowledgeItem
    {
        $a += ['website_id' => $w->id, 'type' => 'price', 'title' => 'Giá gói Cơ bản', 'body' => 'Gói Cơ bản có giá 8.000.000 đồng mỗi năm.', 'entity' => 'Gói Cơ bản', 'field' => 'giá', 'value' => '8000000', 'value_unit' => 'đ', 'status' => 'approved', 'version' => 1];
        $a['content_hash'] = KnowledgeDedupe::hash($a['title'].uniqid(), $a['body'].uniqid());

        return KnowledgeItem::create($a);
    }

    /** @return list<RuleResult> */
    private function kb(string $html, Website $w): array
    {
        $article = Article::create(['website_id' => $w->id, 'primary_keyword' => 'gói cơ bản', 'workflow_status' => 'planned']);
        $v = new ArticleVersion(['html_content' => $html, 'focus_keyword' => 'gói cơ bản']);
        $report = (new ContentQualityChecker([new KnowledgeFigureRule()]))->check($v, $article);

        return $report->results;
    }

    private function site(): Website
    {
        return Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://'.uniqid().'.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    public function test_so_lieu_dung_khop_atom_thi_dat(): void
    {
        $w = $this->site();
        $a = $this->atom($w);
        $r = $this->kb('<p>Hiện nay gói Cơ bản có giá 8.000.000 đồng cho một năm sử dụng.</p>', $w);
        $this->assertCount(1, $r);
        $this->assertTrue($r[0]->passed);
        $this->assertStringContainsString("[KB#{$a->id}]", $r[0]->message);

        $r2 = $this->kb('<p>Gói Cơ bản hiện có giá 8 triệu đồng.</p>', $w); // 8 triệu = 8.000.000
        $this->assertTrue($r2[0]->passed);
    }

    public function test_so_lieu_sai_thi_co_p1_kem_kb_id_va_gia_tri_dung(): void
    {
        $w = $this->site();
        $a = $this->atom($w);
        $r = $this->kb('<p>Gói Cơ bản có giá 9.500.000 đồng mỗi năm.</p>', $w);
        $bad = array_values(array_filter($r, fn ($x) => ! $x->passed));
        $this->assertCount(1, $bad);
        $this->assertSame('KB01', $bad[0]->code);
        $this->assertSame(RuleResult::P1, $bad[0]->severity);
        $this->assertFalse($bad[0]->isBlocking(), 'KB01 không bao giờ chặn (không P0)');
        $this->assertStringContainsString("[KB#{$a->id}]", $bad[0]->message);
        $this->assertStringContainsString('8000000', $bad[0]->message);
        $this->assertStringContainsString('có thể sai', mb_strtolower($bad[0]->label));
    }

    public function test_phan_tram_nam_va_thoi_gian(): void
    {
        $w = $this->site();
        $this->atom($w, ['entity' => 'Ưu đãi sớm', 'field' => 'mức giảm', 'value' => '20', 'value_unit' => '%']);
        $this->atom($w, ['entity' => 'Hoàn tiền', 'field' => 'thời hạn', 'value' => '7', 'value_unit' => 'ngày']);
        $this->atom($w, ['entity' => 'Chương trình', 'field' => 'năm bắt đầu', 'value' => '2024', 'value_unit' => null]);
        $good = $this->kb('<p>Ưu đãi sớm có mức giảm 20%. Hoàn tiền có thời hạn 7 ngày. Chương trình có năm bắt đầu 2024.</p>', $w);
        $this->assertSame([], array_values(array_filter($good, fn ($x) => ! $x->passed)));

        $bad = $this->kb('<p>Ưu đãi sớm có mức giảm 30%. Hoàn tiền có thời hạn 14 ngày. Chương trình có năm bắt đầu 2023.</p>', $w);
        $this->assertCount(3, array_filter($bad, fn ($x) => ! $x->passed));
    }

    public function test_khong_doi_chieu_duoc_chi_ghi_nhan_thong_tin_khong_phat(): void
    {
        $w = $this->site();
        $this->atom($w);
        $r = $this->kb('<p>Gói Cơ bản có giá 8.000.000 đồng. Khách hàng tăng trưởng 45% trong năm 2025 nhờ dịch vụ.</p>', $w);
        $info = array_values(array_filter($r, fn ($x) => $x->severity === RuleResult::P2));
        $this->assertCount(1, $info);
        $this->assertTrue($info[0]->passed, 'chưa kiểm chứng được ≠ lỗi');
        $this->assertStringContainsString('chưa', mb_strtolower($info[0]->label));
        $this->assertSame([], array_filter($r, fn ($x) => ! $x->passed));
    }

    public function test_khong_co_atom_hoac_atom_chua_duyet_hoac_het_han_thi_khong_doi_chieu(): void
    {
        $w = $this->site();
        $html = '<p>Gói Cơ bản có giá 9.500.000 đồng mỗi năm.</p>';
        $r = $this->kb($html, $w);
        $this->assertTrue($r[0]->passed);

        $this->atom($w, ['status' => 'proposed']);
        $this->atom($w, ['valid_until' => now()->subDay()->toDateString()]);
        $this->atom($this->site()); // website khác
        $this->assertSame([], array_filter($this->kb($html, $w), fn ($x) => ! $x->passed));
    }

    public function test_da_dang_ky_trong_bo_luat_mac_dinh_va_rubric(): void
    {
        $codes = array_map(fn ($r) => $r->code(), ContentQualityChecker::defaultRules());
        $this->assertContains('KB01', $codes);
        $e = \App\Services\Content\Quality\QualityRubric::for('KB01');
        $this->assertNotNull($e);
        $this->assertSame('internal', $e['verification']);
    }
}
