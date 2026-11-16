<?php

namespace Tests\Feature\Knowledge;

use App\Models\KnowledgeItem;
use App\Models\Website;
use App\Services\Knowledge\KnowledgeDedupe;
use App\Services\Knowledge\KnowledgeGlossary;
use App\Services\Knowledge\KnowledgeRetriever;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Truy xuất song ngữ: truy vấn tiếng Việt vẫn tìm được mục còn là tiếng Anh (từ điển Việt→Anh), không phạt mục tiếng Việt. */
class BilingualRetrievalTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Website::create(['name' => 'A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function item(string $title, string $body): KnowledgeItem
    {
        return KnowledgeItem::create(['website_id' => $this->site->id, 'type' => 'fact', 'title' => $title, 'body' => $body, 'status' => 'approved', 'version' => 1, 'content_hash' => KnowledgeDedupe::hash($title, $body)]);
    }

    public function test_tu_dien_mo_rong_cum_tieng_viet_sang_tieng_anh(): void
    {
        $en = KnowledgeGlossary::expand('Chỉ số đường huyết bình thường và tiền tiểu đường');

        foreach (['blood glucose', 'normal', 'prediabetes', 'diabetes', 'range'] as $w) {
            $this->assertContains($w, $en);
        }
        $this->assertSame([], KnowledgeGlossary::expand('học bổng toàn phần du học'), 'không khớp cụm nào thì không thêm gì');
        $this->assertNotContains('thận', KnowledgeGlossary::expand('thanh toán'), 'khớp theo cả từ, không khớp một phần từ');
    }

    public function test_truy_van_tieng_viet_tim_duoc_muc_tieng_anh(): void
    {
        $en = $this->item('Diabetes test results for non-pregnant individuals', 'Diabetes thresholds are 6.5% or above for A1C and 126 mg/dL or above for fasting plasma glucose.');
        $this->item('Học bổng toàn phần', 'Học bổng toàn phần miễn 100% học phí cho sinh viên xuất sắc.');

        $ids = array_column((new KnowledgeRetriever)->search($this->site, 'ngưỡng chẩn đoán tiểu đường HbA1c', 5), 'id');

        $this->assertSame([$en->id], $ids, 'chỉ mục tiếng Anh liên quan, không kéo mục lạc đề');
    }

    public function test_muc_tieng_viet_khong_bi_phat_boi_tu_dien(): void
    {
        $vi = $this->item('Chẩn đoán đái tháo đường', 'Chẩn đoán đái tháo đường dựa vào đường huyết lúc đói và HbA1c.');
        $r = (new KnowledgeRetriever)->search($this->site, 'chẩn đoán đái tháo đường', 5);

        $this->assertSame($vi->id, $r[0]['id']);
        $this->assertGreaterThan(0.3, $r[0]['score']);
    }
}
