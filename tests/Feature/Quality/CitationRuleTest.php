<?php

namespace Tests\Feature\Quality;

use App\Models\Article;
use App\Models\Brain;
use App\Models\KnowledgeItem;
use App\Models\Website;
use App\Services\Content\Quality\ContentQualityChecker;
use App\Services\Content\Quality\Rules\CitationRule;
use App\Services\Knowledge\KnowledgeDedupe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\QualityFixtures;

/** CITE01/CITE02: trích dẫn từng khẳng định với Trí tuệ "Bắt buộc nguồn". Tất định, không AI/mạng. */
class CitationRuleTest extends TestCase
{
    use RefreshDatabase;

    private int $ver = 0;

    private Brain $brain;

    private Article $article;

    protected function setUp(): void
    {
        parent::setUp();
        $w = Website::create(['name' => 'Y', 'cms_type' => 'wordpress', 'base_url' => 'https://'.uniqid().'.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->brain = Brain::create(['name' => 'Đái tháo đường', 'slug' => 'dtd-'.uniqid(), 'status' => 'active', 'settings' => ['ai_reliance' => 'must_cite', 'risk_class' => 'high']]);
        $this->brain->websites()->attach($w->id, ['priority' => 10]);
        $this->article = Article::create(['website_id' => $w->id, 'primary_keyword' => 'chỉ số đường huyết', 'workflow_status' => 'planned']);
    }

    private function item(string $status = 'approved', ?int $brainId = null): KnowledgeItem
    {
        return KnowledgeItem::create(['brain_id' => $brainId ?? $this->brain->id, 'type' => 'fact', 'title' => 'T'.uniqid(), 'body' => 'Nội dung '.uniqid(), 'status' => $status, 'version' => 1, 'content_hash' => KnowledgeDedupe::hash(uniqid(), uniqid())]);
    }

    private function codes(string $html): array
    {
        $v = QualityFixtures::version($this->article, ++$this->ver, 'needs_review', ['html_content' => $html]);
        $rep = (new ContentQualityChecker([new CitationRule]))->check($v, $this->article);

        // khóa: P0 hoặc P1 + :ok/:fail (hai kết quả cùng mã CITE01 khác mức)
        return array_column(array_map(fn ($r) => ['c' => ($r->severity === 'P0' ? 'CITE02' : 'CITE01').($r->passed ? ':ok' : ':fail'), 'r' => $r], $rep->results), 'r', 'c');
    }

    public function test_khong_chay_khi_tri_tue_khong_bat_buoc_nguon(): void
    {
        $this->brain->update(['settings' => ['ai_reliance' => 'reference', 'risk_class' => 'low']]);

        $this->assertSame([], $this->codes('<p>Theo khuyến cáo, HbA1c từ 6,5% là đái tháo đường.</p>'));
    }

    public function test_khang_dinh_co_dau_kb_hop_le_thi_dat(): void
    {
        $i = $this->item();
        $r = $this->codes("<p>Theo khuyến cáo, người lớn nên xét nghiệm lại sau mỗi 3 năm. [KB#{$i->id}]</p><p>Đoạn mở đầu không có khẳng định.</p>");

        $this->assertArrayHasKey('CITE01:ok', $r);
        $this->assertArrayHasKey('CITE02:ok', $r);
    }

    public function test_khang_dinh_thieu_dau_kb_bi_co_p1_kem_doan_mau(): void
    {
        $i = $this->item();
        $r = $this->codes("<p>Theo khuyến cáo, HbA1c từ 6,5% trở lên là đái tháo đường.</p><ul><li>Điều trị cần theo chỉ định bác sĩ. [KB#{$i->id}]</li></ul>");

        $this->assertArrayHasKey('CITE01:fail', $r);
        $this->assertSame('P1', $r['CITE01:fail']->severity);
        $this->assertStringContainsString('1/2 đoạn', $r['CITE01:fail']->message);
        $this->assertStringContainsString('HbA1c từ 6,5%', $r['CITE01:fail']->message);
        $this->assertArrayHasKey('CITE02:ok', $r);
    }

    public function test_dau_kb_gia_chua_duyet_hoac_thuoc_tri_tue_khac_bi_chan_p0(): void
    {
        $pending = $this->item('proposed');
        $other = Brain::create(['name' => 'Khác', 'slug' => 'k-'.uniqid(), 'status' => 'active']);
        $elsewhere = $this->item('approved', $other->id);
        $r = $this->codes("<p>Theo khuyến cáo, người lớn xét nghiệm lại sau 3 năm. [KB#{$pending->id}] [KB#{$elsewhere->id}] [KB#999999]</p>");

        $this->assertArrayHasKey('CITE02:fail', $r);
        $this->assertSame('P0', $r['CITE02:fail']->severity);
        $this->assertTrue($r['CITE02:fail']->isBlocking());
        foreach ([$pending->id, $elsewhere->id, 999999] as $id) {
            $this->assertStringContainsString('KB#'.$id, $r['CITE02:fail']->message);
        }
    }

    public function test_nhieu_id_trong_mot_dau_ngoac(): void
    {
        $a = $this->item();
        $b = $this->item();
        $r = $this->codes("<p>Theo khuyến cáo, nên theo dõi định kỳ. [KB#{$a->id}, KB#{$b->id}]</p>");

        $this->assertArrayHasKey('CITE02:ok', $r);
        $this->assertArrayHasKey('CITE01:ok', $r);
    }
}
