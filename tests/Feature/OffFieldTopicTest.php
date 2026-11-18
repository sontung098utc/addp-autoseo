<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Brain;
use App\Models\BrandProfile;
use App\Models\Website;
use App\Services\Agent\Readiness\ArticleReadiness;
use App\Services\Agent\Risk\TopicScope;
use App\Services\Brain\BrainContext;
use App\Services\Images\ArticleImageService;
use App\Services\Images\ImageDesigner;
use App\Services\Images\ImagePlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bài NGOÀI lĩnh vực website (vd. "cách viết thư xin học bổng" trên website dược) không được xử lý như bài y tế;
 * bài y tế thật (kể cả khi nghi ngờ) vẫn giữ nguyên mức nghiêm. Giả lập bằng dữ liệu mẫu, không gọi AI/ảnh thật.
 */
class OffFieldTopicTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    private ?BrandProfile $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Website::create(['name' => 'ADDP', 'cms_type' => 'wordpress', 'base_url' => 'https://addp.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->brand = BrandProfile::create(['website_id' => $this->site->id, 'business_field' => 'Dược phẩm và thiết bị y tế']);
        $brain = Brain::create(['name' => 'Y khoa', 'slug' => 'y_khoa', 'domain_key' => 'y_khoa', 'language' => 'vi', 'status' => 'active', 'description' => '', 'settings' => ['risk_class' => 'high']]);
        $this->site->brains()->attach($brain->id, ['priority' => 10]);
    }

    private function article(string $kw): Article
    {
        return Article::create(['website_id' => $this->site->id, 'primary_keyword' => $kw, 'workflow_status' => 'planned']);
    }

    private function prompts(Article $a): array
    {
        $svc = new ArticleImageService;
        $plan = ImagePlanner::plan($a, [['level' => 'H1', 'heading' => $a->primary_keyword], ['level' => 'H2', 'heading' => 'Mở đầu thư', 'purpose' => 'giới thiệu bản thân']], [
            'health' => $svc->isHealth($a, $this->brand), 'brand' => $this->brand, 'off_field' => $svc->isOffField($a, $this->brand), 'max_inline' => 1,
        ]);

        return array_column($plan['slots'], 'prompt');
    }

    public function test_scholarship_letter_image_prompt_has_no_medical_or_pharma_context(): void
    {
        $a = $this->article('cách viết thư xin học bổng');
        $svc = new ArticleImageService;
        $this->assertTrue($svc->isOffField($a, $this->brand));
        $this->assertFalse($svc->isHealth($a, $this->brand));
        foreach ($this->prompts($a) as $p) {
            $this->assertStringNotContainsStringIgnoringCase('glucose', $p);
            $this->assertStringNotContainsStringIgnoringCase('heart', $p);
            $this->assertStringNotContainsStringIgnoringCase('health', $p);
            $this->assertStringNotContainsString('Dược', $p);
            $this->assertStringNotContainsString('Business domain', $p);
        }
        // Họa viên không nhận lĩnh vực thương hiệu làm phong cách.
        $this->assertStringNotContainsString('lĩnh vực/thương hiệu', ImageDesigner::designPrompt('n', 'x', false, '', ''));
    }

    public function test_real_health_topic_keeps_health_context_and_brand_field(): void
    {
        foreach (['thực đơn cho người tiểu đường', 'mất ngủ kéo dài', 'thực đơn tuần cho người lớn tuổi'] as $kw) {
            $a = $this->article($kw);
            $svc = new ArticleImageService;
            $this->assertFalse($svc->isOffField($a, $this->brand), $kw);
            $this->assertTrue($svc->isHealth($a, $this->brand), $kw);
            $p = $this->prompts($a)[0];
            $this->assertStringContainsString('Health themes', $p, $kw);
            $this->assertStringContainsString('Business domain', $p, $kw);
        }
    }

    public function test_risk_follows_topic_not_website_industry(): void
    {
        $off = ArticleReadiness::for($this->article('cách viết thư xin học bổng'));
        $this->assertSame('low', $off['risk']['level']);
        $this->assertContains('off_topic', array_column($off['issues'], 'code')); // cảnh báo lệch lĩnh vực ở bước duyệt

        $health = ArticleReadiness::for($this->article('thực đơn cho người tiểu đường'));
        $this->assertSame('high', $health['risk']['level']);
        // Chủ đề y tế lạ chưa có trong bộ từ khóa: vẫn nâng theo Trí tuệ (mặc định nghiêm khi nghi ngờ).
        $doubt = ArticleReadiness::for($this->article('thực đơn tuần cho người lớn tuổi'));
        $this->assertSame('high', $doubt['risk']['level']);
    }

    public function test_no_comparison_data_or_sensitive_topic_is_never_off_field(): void
    {
        $ctx = BrainContext::forArticle($this->article('x'));
        $this->assertFalse(TopicScope::clearlyOffField('cách viết thư xin học bổng', null, new BrainContext([]))); // không có gì để so sánh: giữ như cũ
        $this->assertFalse(TopicScope::clearlyOffField('đầu tư chứng khoán cho người mới', $this->brand, $ctx)); // dính luật rủi ro tài chính
        $this->assertFalse(TopicScope::clearlyOffField('', $this->brand, $ctx));
        $this->brand->update(['business_field' => null]); // chưa có lĩnh vực trong hồ sơ: không đủ bằng chứng, giữ nghiêm
        $this->assertFalse(TopicScope::clearlyOffField('cách viết thư xin học bổng', $this->brand->fresh(), $ctx));
        $this->brand->update(['business_field' => 'Dược phẩm và thiết bị y tế']);
        $this->assertTrue(TopicScope::clearlyOffField('cách viết thư xin học bổng', $this->brand, $ctx));
    }

    public function test_website_level_medical_brains_do_not_raise_unrelated_article(): void
    {
                foreach ([['Đái tháo đường (tiểu đường)', 'high', 'must_cite'], ['Tim mạch và tăng huyết áp', 'high', 'must_cite'], ['Trí tuệ của ADDP', 'low', 'numbers']] as $i => [$n, $risk, $rel]) {
            $b = Brain::create(['name' => $n, 'slug' => 'b'.$i, 'domain_key' => 'k'.$i, 'language' => 'vi', 'status' => 'active', 'description' => '', 'settings' => ['risk_class' => $risk, 'ai_reliance' => $rel]]);
            $this->site->brains()->attach($b->id, ['priority' => 20 + $i]);
        }
        $off = ArticleReadiness::for($this->article('cách viết thư xin học bổng'));
        $this->assertSame('low', $off['risk']['level']);
        $this->assertSame(['Trí tuệ của ADDP'], array_column($off['brains']['attached'], 'name')); // Trí tuệ thương hiệu vẫn áp dụng, Trí tuệ y tế thì không
        $this->assertSame('high', ArticleReadiness::for($this->article('tiểu đường ăn gì'))['risk']['level']);
        $ctx = BrainContext::forArticle($this->article('x'));
        $this->assertSame('high', $ctx->riskFloor());
        $this->assertSame('low', $ctx->withoutStrict()->riskFloor());
    }
}
