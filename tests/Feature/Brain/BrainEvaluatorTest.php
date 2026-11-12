<?php

namespace Tests\Feature\Brain;

use App\Models\Brain;
use App\Models\KnowledgeItem;
use App\Models\Website;
use App\Services\Brain\BrainEvaluator;
use App\Services\Knowledge\KnowledgeDedupe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Đo độ phủ truy xuất bằng bộ câu hỏi vàng: tất định, không AI; chế độ "tiềm năng" không ghi CSDL. */
class BrainEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    private Brain $brain;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Website::create(['name' => 'A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->brain = Brain::create(['name' => 'ĐTĐ', 'slug' => 'dtd', 'domain_key' => 'dtd_test', 'status' => 'active']);
        $this->brain->websites()->attach($this->site->id, ['priority' => 10]);
    }

    private function item(string $title, string $body, string $status): KnowledgeItem
    {
        return KnowledgeItem::create(['brain_id' => $this->brain->id, 'type' => 'fact', 'title' => $title, 'body' => $body, 'status' => $status, 'version' => 1, 'content_hash' => KnowledgeDedupe::hash($title, $body)]);
    }

    private function spec(): array
    {
        return ['status' => 'ai_draft', 'questions' => [
            ['id' => 'q1', 'topic' => 'A', 'q' => 'HbA1c bao nhiêu thì chẩn đoán đái tháo đường?', 'must' => [['hba1c', 'a1c'], ['6,5', '6.5']]],
            ['id' => 'q2', 'topic' => 'B', 'q' => 'Vận động như thế nào cho an toàn?', 'must' => [['vận động', 'exercise']]],
        ]];
    }

    public function test_chi_tinh_muc_da_duyet_va_ghi_ro_phan_thieu(): void
    {
        $this->item('Ngưỡng HbA1c', 'Đái tháo đường được chẩn đoán khi HbA1c từ 6,5% trở lên.', 'approved');
        $r = (new BrainEvaluator)->run($this->brain, $this->site, false, 8, $this->spec());

        $this->assertSame(2, $r['total']);
        $this->assertSame(1, $r['passed']);
        $this->assertSame(1, $r['failed']);
        $by = array_column($r['rows'], null, 'id');
        $this->assertSame(1.0, $by['q1']['coverage']);
        $this->assertSame(['vận động'], $by['q2']['missing']);
    }

    public function test_che_do_tiem_nang_tinh_ca_muc_cho_duyet_va_khong_ghi_csdl(): void
    {
        $i = $this->item('Ngưỡng HbA1c', 'Đái tháo đường được chẩn đoán khi HbA1c từ 6,5% trở lên.', 'proposed');

        $now = (new BrainEvaluator)->run($this->brain, $this->site, false, 8, $this->spec());
        $pot = (new BrainEvaluator)->run($this->brain, $this->site, true, 8, $this->spec());

        $this->assertSame(0, $now['passed'], 'mục chờ duyệt không dùng được ngay');
        $this->assertSame(1, $pot['passed']);
        $this->assertSame('proposed', $i->fresh()->status, 'hoàn lại: không đổi trạng thái thật');
    }

    public function test_cum_dai_khop_khi_du_phan_lon_tu_noi_dung(): void
    {
        $this->item('Tập thể dục', 'Vận động thường xuyên giúp hạ huyết áp ở người lớn.', 'approved');
        $spec = ['status' => 'ai_draft', 'questions' => [
            ['id' => 'a', 'topic' => 'T', 'q' => 'Tập thể dục tác động thế nào đến huyết áp?', 'must' => [['tập thể dục giúp hạ huyết áp ở người lớn', 'exercise']]],
            ['id' => 'b', 'topic' => 'T', 'q' => 'Hút thuốc?', 'must' => [['hút thuốc lá làm tăng nguy cơ ung thư phổi']]],
        ]];

        $by = array_column((new BrainEvaluator)->run($this->brain, $this->site, false, 8, $spec)['rows'], null, 'id');

        $this->assertSame(1.0, $by['a']['coverage'], 'cụm dài khớp khi đủ từ nội dung (≥75%) có trong mục');
        $this->assertSame(0.0, $by['b']['coverage'], 'cụm không liên quan thì không khớp');
        $c = (new BrainEvaluator)->run($this->brain, $this->site, false, 8, ['questions' => [['id' => 'c', 'topic' => 'T', 'q' => 'Tập thể dục?', 'must' => [['tác động của tập thể dục đến huyết áp']]]]])['rows'][0];
        $this->assertSame(0.0, $c['coverage'], 'dưới 75% từ nội dung (4/8) thì không khớp: ngưỡng không quá dễ dãi');
    }

    public function test_tri_tue_chua_co_bo_cau_hoi_thi_tra_null_va_bo_cau_hoi_dai_thao_duong_hop_le(): void
    {
        $this->assertNull((new BrainEvaluator)->run($this->brain, $this->site));

        $spec = (array) include resource_path('brains/evals/dai_thao_duong.php');
        $this->assertSame('ai_draft', $spec['status'], 'bộ câu hỏi do AI soạn phải ghi rõ chờ chuyên gia');
        $this->assertGreaterThanOrEqual(20, count($spec['questions']));
        foreach ($spec['questions'] as $q) {
            $this->assertNotSame('', $q['q']);
            $this->assertNotEmpty($q['must']);
        }
        $this->assertCount(count($spec['questions']), array_unique(array_column($spec['questions'], 'id')));
    }
}
