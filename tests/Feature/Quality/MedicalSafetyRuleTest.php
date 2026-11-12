<?php

namespace Tests\Feature\Quality;

use App\Models\Article;
use App\Models\Brain;
use App\Models\Website;
use App\Services\Content\Quality\ContentQualityChecker;
use App\Services\Content\Quality\Rules\MedicalSafetyRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\QualityFixtures;

/** MED01: bài y tế phải hướng người đọc tới bác sĩ và nêu rõ là thông tin tham khảo. Tất định. */
class MedicalSafetyRuleTest extends TestCase
{
    use RefreshDatabase;

    private Article $article;

    private Brain $brain;

    protected function setUp(): void
    {
        parent::setUp();
        $w = Website::create(['name' => 'Y', 'cms_type' => 'wordpress', 'base_url' => 'https://'.uniqid().'.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->brain = Brain::create(['name' => 'ĐTĐ', 'slug' => 'm-'.uniqid(), 'status' => 'active', 'settings' => ['ai_reliance' => 'must_cite', 'risk_class' => 'high']]);
        $this->brain->websites()->attach($w->id, ['priority' => 10]);
        $this->article = Article::create(['website_id' => $w->id, 'primary_keyword' => 'hba1c', 'workflow_status' => 'planned']);
    }

    private function results(string $html): array
    {
        $v = QualityFixtures::version($this->article, 1, 'needs_review', ['html_content' => $html]);

        return (new ContentQualityChecker([new MedicalSafetyRule]))->check($v, $this->article)->results;
    }

    public function test_du_hai_y_thi_dat(): void
    {
        $r = $this->results('<h2>Khi nào cần gặp bác sĩ?</h2><p>Bạn nên đi khám khi có triệu chứng bất thường.</p><p>Bài viết chỉ mang tính tham khảo, không thay thế tư vấn của bác sĩ.</p>');

        $this->assertCount(2, $r);
        $this->assertTrue($r[0]->passed && $r[1]->passed);
    }

    public function test_thieu_thi_co_p1_khong_chan(): void
    {
        $r = $this->results('<p>HbA1c là xét nghiệm máu cho biết đường huyết trung bình.</p>');

        $this->assertFalse($r[0]->passed);
        $this->assertFalse($r[1]->passed);
        $this->assertSame('P1', $r[0]->severity);
        $this->assertFalse($r[0]->isBlocking());
    }

    public function test_khong_chay_voi_tri_tue_thuong(): void
    {
        $this->brain->update(['settings' => ['ai_reliance' => 'reference', 'risk_class' => 'low']]);

        $this->assertSame([], $this->results('<p>Một bài bình thường.</p>'));
    }
}
