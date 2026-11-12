<?php

namespace Tests\Feature\Knowledge;

use App\Models\Article;
use App\Models\Brain;
use App\Models\KnowledgeItem;
use App\Models\Website;
use App\Services\Knowledge\KnowledgeContext;
use App\Services\Knowledge\KnowledgeDedupe;
use App\Services\Knowledge\KnowledgeRetriever;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Truy xuất cho nội dung bắt buộc nguồn: trọng số IDF, hạ điểm mục kiểm lại không khớp, truy vấn theo từng mục dàn ý, nhiều mục hơn. */
class RetrievalQualityTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    private Brain $brain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Website::create(['name' => 'Y', 'cms_type' => 'wordpress', 'base_url' => 'https://y.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->brain = Brain::create(['name' => 'ĐTĐ', 'slug' => 'q-'.uniqid(), 'status' => 'active', 'settings' => ['ai_reliance' => 'must_cite', 'risk_class' => 'high']]);
        $this->brain->websites()->attach($this->site->id, ['priority' => 10]);
    }

    private function item(string $title, string $body, array $tags = []): KnowledgeItem
    {
        return KnowledgeItem::create(['brain_id' => $this->brain->id, 'type' => 'fact', 'title' => $title, 'body' => $body, 'status' => 'approved', 'tags' => $tags, 'version' => 1, 'content_hash' => KnowledgeDedupe::hash($title, $body.uniqid())]);
    }

    public function test_tu_hiem_dac_hieu_hon_tu_co_o_moi_muc(): void
    {
        // "bệnh" có ở mọi mục; "nhịn" chỉ có ở một mục: truy vấn nhắc cả hai thì mục chứa từ hiếm phải lên đầu dù các mục kia khớp "bệnh" nhiều hơn.
        foreach (range(1, 19) as $i) {
            $this->item('Bệnh chung '.$i, 'Bệnh này là bệnh mạn tính, bệnh cần theo dõi bệnh lâu dài.');
        }
        $rare = $this->item('Chuẩn bị xét nghiệm', 'Cần nhịn ăn trước khi lấy máu.');

        $r = (new KnowledgeRetriever)->search($this->site, 'bệnh nhịn', 3, [$this->brain->id]);
        // Không có IDF: mục chung khớp "bệnh" ở cả tiêu đề và nội dung (3 điểm) thắng mục hiếm chỉ khớp "nhịn" ở nội dung (1 điểm).

        $this->assertSame($rare->id, $r[0]['id']);
    }

    public function test_muc_kiem_lai_lech_nghia_bi_ha_diem_va_xuong_sau(): void
    {
        $bad = $this->item('Ngưỡng HbA1c', 'Chẩn đoán khi HbA1c từ 6,5% trở lên ở người không mang thai.', ['kiểm lại: lệch nghĩa']);
        $good = $this->item('Ngưỡng HbA1c theo nguồn khác', 'Chẩn đoán khi HbA1c từ 6,5% trở lên ở người không mang thai.', ['kiểm lại: khớp']);

        $r = (new KnowledgeRetriever)->search($this->site, 'HbA1c 6,5% chẩn đoán', 5, [$this->brain->id]);

        $this->assertSame([$good->id, $bad->id], array_column($r, 'id'));
    }

    public function test_ngu_canh_bai_truy_xuat_theo_tung_muc_dan_y_va_nhan_nhieu_muc_hon_cho_linh_vuc_bat_buoc_nguon(): void
    {
        foreach (range(1, 10) as $i) {
            $this->item('Chung '.$i, 'Đái tháo đường là bệnh mạn tính số '.$i.'.');
        }
        $foot = $this->item('Chăm sóc bàn chân', 'Kiểm tra bàn chân mỗi ngày để phát hiện vết loét sớm.');
        $eye = $this->item('Khám mắt', 'Khám võng mạc định kỳ để phát hiện tổn thương mắt.');
        $a = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'đái tháo đường', 'workflow_status' => 'planned']);
        $outline = [['level' => 'H1', 'heading' => 'Đái tháo đường'], ['level' => 'H2', 'heading' => 'Chăm sóc bàn chân'], ['level' => 'H2', 'heading' => 'Khám mắt định kỳ'], ['level' => 'H2', 'heading' => 'Câu hỏi thường gặp']];

        $ctx = (new KnowledgeContext)->scopeToBrains([$this->brain->id]);
        $ctx->load($a, $outline);
        $ids = array_column($ctx->options()['knowledge'], 'id');

        $this->assertLessThanOrEqual(KnowledgeContext::LIMIT_STRICT, count($ids));
        $this->assertGreaterThan(KnowledgeContext::LIMIT, count($ids), 'Trí tuệ bắt buộc nguồn nhận nhiều mục hơn mức thường');
        $this->assertContains($foot->id, $ids, 'mục dàn ý "bàn chân" kéo được mục riêng của nó dù từ khóa chính chỉ nhắc bệnh chung');
        $this->assertContains($eye->id, $ids);
    }

    public function test_tri_tue_thuong_van_gioi_han_nam_muc(): void
    {
        $this->brain->update(['settings' => ['ai_reliance' => 'reference', 'risk_class' => 'low']]);
        foreach (range(1, 10) as $i) {
            $this->item('Học bổng '.$i, 'Học bổng toàn phần số '.$i.'.');
        }
        $a = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'học bổng', 'workflow_status' => 'planned']);

        $ctx = (new KnowledgeContext)->scopeToBrains([$this->brain->id]);
        $ctx->load($a, []);

        $this->assertCount(KnowledgeContext::LIMIT, $ctx->options()['knowledge']);
    }
}
