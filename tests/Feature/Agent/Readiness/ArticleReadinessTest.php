<?php

namespace Tests\Feature\Agent\Readiness;

use App\Models\Article;
use App\Models\Brain;
use App\Models\BrandProfile;
use App\Models\KnowledgeItem;
use App\Models\Website;
use App\Services\Agent\Readiness\ArticleReadiness;
use App\Services\Agent\Readiness\BrainSuggester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArticleReadinessTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Website
    {
        return Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://'.uniqid().'.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function article(Website $w, string $kw): Article
    {
        return Article::create(['website_id' => $w->id, 'primary_keyword' => $kw, 'workflow_status' => 'planned']);
    }

    private function brain(string $name, string $slug): Brain
    {
        return Brain::create(['name' => $name, 'slug' => $slug, 'domain_key' => $slug, 'language' => 'vi', 'status' => 'active', 'description' => '']);
    }

    public function test_chu_de_y_te_kho_trong_khong_chan_ma_can_chuyen_gia(): void
    {
        $a = $this->article($this->site(), 'người tiểu đường nên ăn gì');
        $r = ArticleReadiness::for($a);

        $this->assertSame('high', $r['risk']['level']);
        $this->assertSame(0, $r['knowledge']['count']);
        $this->assertFalse($r['holds_writing']);
        $this->assertTrue($r['needs_expert']);
        $this->assertNotContains('block', array_column($r['issues'], 'severity'));
        $this->assertContains('Viết xong sẽ gắn nhãn cần chuyên gia xem lại (chủ đề rủi ro cao)', $r['auto_actions']);
        $this->assertNull($r['cost']['estimate_vnd']);
        $this->assertSame(0, $r['brand']['percent']);
    }

    public function test_chu_de_thuong_kho_trong_chi_canh_bao_khong_chan(): void
    {
        $r = ArticleReadiness::for($this->article($this->site(), 'cách trang trí bàn làm việc'));

        $this->assertNotSame('high', $r['risk']['level']);
        $this->assertFalse($r['holds_writing']);
        $this->assertFalse($r['needs_expert']);
        $this->assertSame([], $r['auto_actions']);
        $this->assertContains('kb_empty', array_column($r['issues'], 'code'));
    }

    public function test_co_muc_kien_thuc_da_duyet_thi_khong_chan(): void
    {
        $w = $this->site();
        KnowledgeItem::create(['website_id' => $w->id, 'type' => 'fact', 'title' => 'Người tiểu đường nên ăn gì: chọn thực phẩm chỉ số đường huyết thấp', 'body' => 'Người tiểu đường nên ưu tiên thực phẩm có chỉ số đường huyết thấp như rau xanh, yến mạch.', 'status' => 'approved']);
        $r = ArticleReadiness::for($this->article($w, 'người tiểu đường nên ăn gì'));

        $this->assertSame(1, $r['knowledge']['count']);
        $this->assertFalse($r['holds_writing']);
        $this->assertFalse($r['needs_expert']);
    }

    public function test_auto_actions_noi_se_tu_gan_tri_tue(): void
    {
        $w = $this->site();
        $this->brain('Đái tháo đường (tiểu đường)', 'dai_thao_duong');
        $r = ArticleReadiness::for($this->article($w, 'người tiểu đường nên ăn gì'));

        $this->assertStringContainsString('Tự gắn Trí tuệ «Đái tháo đường (tiểu đường)»', $r['auto_actions'][0]);
    }

    public function test_cum_hiem_xep_truoc_cum_pho_bien(): void
    {
        $w = $this->site();
        $this->brain('Thực phẩm bảo vệ sức khỏe', 'tpbvsk');
        $this->brain('Dinh dưỡng thực phẩm cho trẻ', 'dinh_duong');
        $this->brain('Đái tháo đường (tiểu đường)', 'dai_thao_duong');
        $s = BrainSuggester::forArticle($this->article($w, 'thực phẩm tốt cho người tiểu đường'));

        $this->assertSame('Đái tháo đường (tiểu đường)', $s[0]['name']);
    }

    public function test_hai_tu_roi_chung_chung_khong_keo_nham_tri_tue(): void
    {
        $w = $this->site();
        // "đường" và "thường" đều xuất hiện trong mô tả nhưng KHÔNG thành cụm liền nhau của chủ đề bài.
        Brain::create(['name' => 'Sức khỏe trẻ em', 'slug' => 'tre_em', 'domain_key' => 'tre_em', 'language' => 'vi', 'status' => 'active', 'description' => 'Các bệnh thường gặp, đường hô hấp và đường tiêu hóa ở trẻ']);
        $s = BrainSuggester::forArticle($this->article($w, 'chỉ số đường huyết bình thường'));

        $this->assertSame([], $s);
    }

    public function test_goi_y_tri_tue_khop_chu_de_nhung_chua_gan(): void
    {
        $w = $this->site();
        $this->brain('Đái tháo đường (tiểu đường)', 'dai_thao_duong');
        $this->brain('Da liễu và chăm sóc da', 'da_lieu');
        $a = $this->article($w, 'người tiểu đường nên ăn gì');

        $s = BrainSuggester::forArticle($a);
        $this->assertCount(1, $s);
        $this->assertSame('Đái tháo đường (tiểu đường)', $s[0]['name']);
        $this->assertStringContainsString('tiểu đường', $s[0]['reason'], 'lý do hiện chữ có dấu');
        $this->assertContains('no_brain', array_column(ArticleReadiness::for($a)['issues'], 'code'));
        // Đã gắn thì không gợi ý nữa.
        $this->assertSame([], BrainSuggester::forArticle($a, [$s[0]['id']]));
    }

    public function test_chu_de_lech_linh_vuc_website_duoc_canh_bao_khi_co_ho_so(): void
    {
        $w = $this->site();
        BrandProfile::create(['website_id' => $w->id, 'business_field' => 'Thực phẩm chức năng cho người tiểu đường']);

        $off = ArticleReadiness::for($this->article($w, 'cách viết thư xin học bổng'));
        $on = ArticleReadiness::for($this->article($w, 'thực phẩm cho người tiểu đường'));

        $this->assertContains('off_topic', array_column($off['issues'], 'code'));
        $this->assertNotContains('off_topic', array_column($on['issues'], 'code'));
    }
}
