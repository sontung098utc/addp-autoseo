<?php

namespace Tests\Feature\Brain;

use App\Contracts\StructuredAiInterface;
use App\Models\Brain;
use App\Models\BrainProfile;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Brain\Build\DomainBrainBuilder;
use App\Services\Knowledge\Build\SafePageFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Knowledge\Build\FakeSafety;
use Tests\TestCase;

/** Quy trình Xây kho chuyên ngành: AI GIẢ theo kỹ năng, trang GIẢ (Http::fake); kiểm các cổng an toàn, không phải chất lượng nội dung y khoa. */
class DomainBrainBuilderTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    private User $by;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->site = Website::create(['name' => 'Y', 'cms_type' => 'wordpress', 'base_url' => 'https://y.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->by = User::create(['name' => 'a', 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => 'approver', 'is_active' => true]);
    }

    private function brain(array $settings): Brain
    {
        $b = Brain::create(['name' => 'Đái tháo đường', 'slug' => 'dtd-'.uniqid(), 'domain_key' => 'dtd_t', 'description' => 'Kiến thức về tiểu đường', 'status' => 'active', 'settings' => $settings]);
        $b->websites()->attach($this->site->id, ['priority' => 10]);
        $p = KnowledgeTopic::create(['brain_id' => $b->id, 'name' => 'Hiểu về bệnh']);
        KnowledgeTopic::create(['brain_id' => $b->id, 'parent_id' => $p->id, 'name' => 'Các thể bệnh']);

        return $b;
    }

    private function page(string $para): string
    {
        return '<html><head><meta charset="utf-8"><title>Trang</title></head><body><main><h1>Trang</h1><p>'.$para.'</p><p>'.str_repeat('Thông tin tham khảo dành cho cộng đồng. ', 8).'</p></main></body></html>';
    }

    /** AI giả: nhận biết bước qua nội dung chỉ dẫn hệ thống (kỹ năng hoặc công cụ). */
    private function ai(array $cfg = []): object
    {
        return new class($cfg) implements StructuredAiInterface
        {
            public array $systems = [];

            public function __construct(private array $cfg) {}

            public function generateStructured(string $system, string $user): array
            {
                $this->systems[] = $system;
                $d = match (true) {
                    str_contains($system, 'brain-source-plan') => ['sources' => $this->cfg['sources'] ?? []],
                    str_contains($system, 'brain-gap-fill') => ['sources' => $this->cfg['gap_sources'] ?? [], 'unfillable' => []],
                    str_contains($system, 'công cụ trích xuất kiến thức') => ['items' => $this->cfg['items'] ?? []],
                    str_contains($system, 'công cụ kiểm chứng nội dung') => ['verdicts' => $this->verdicts($user)],
                    str_contains($system, 'công cụ phân loại kiến thức') => ['assignments' => $this->assign($user)],
                    str_contains($system, 'brain-eval-questions') => ['questions' => $this->cfg['questions'] ?? []],
                    str_contains($system, 'brain-domain-spec') => $this->cfg['spec'] ?? [],
                    default => [],
                };

                return ['data' => $d, 'tokens_used' => 10, 'cost_vnd' => 1.0, 'model' => 'fake'];
            }

            private function verdicts(string $user): array
            {
                $out = [];
                preg_match_all('/^(\d+)\. Tiêu đề: (.*)$/m', $user, $m, PREG_SET_ORDER);
                foreach ($m as $x) {
                    $out[] = ['n' => (int) $x[1], 'verdict' => str_contains($x[2], 'LỆCH') ? 'unsupported' : 'faithful', 'reason' => 'kiểm'];
                }

                return $out;
            }

            private function assign(string $user): array
            {
                preg_match_all('/^(\d+)\. /m', $user, $m);

                return array_map(fn ($n) => ['n' => (int) $n, 'topic' => 'Hiểu về bệnh > Các thể bệnh'], $m[1]);
            }
        };
    }

    private function item(string $title, string $body, string $span): array
    {
        return ['title' => $title, 'body' => $body, 'type' => 'fact', 'topic' => 'Hiểu về bệnh', 'tags' => [], 'confidence' => 90, 'source_span' => $span];
    }

    private function builder(object $ai): DomainBrainBuilder
    {
        return new DomainBrainBuilder($ai, 'AI giả', null, fn () => null, new SafePageFetcher(new FakeSafety, 0));
    }

    public function test_quy_trinh_day_du_chan_nguon_chan_giu_tang_c_cho_chuyen_gia_va_ghi_ai_draft(): void
    {
        Http::fake([
            'who.int/robots.txt' => Http::response('', 404),
            'who.int/g1' => Http::response($this->page('Đái tháo đường được chẩn đoán khi HbA1c từ 6,5% trở lên ở người không mang thai.'), 200, ['Content-Type' => 'text/html']),
        ]);
        $brain = $this->brain(['ai_reliance' => 'must_cite', 'risk_class' => 'high']);
        $ai = $this->ai([
            'sources' => [['title' => 'WHO', 'url' => 'https://www.who.int/g1', 'tier' => '1'], ['title' => 'FB', 'url' => 'https://facebook.com/x', 'tier' => '3'], ['title' => 'sai', 'url' => 'không phải url']],
            'items' => [$this->item('Ngưỡng HbA1c', 'Chẩn đoán khi HbA1c từ 6,5% trở lên ở người không mang thai.', 'HbA1c từ 6,5% trở lên ở người không mang thai')],
            'questions' => [['topic' => 'Các thể bệnh', 'q' => 'HbA1c bao nhiêu thì chẩn đoán?', 'must' => [['hba1c'], ['6,5']]]],
            'spec' => ['voice' => ['tone' => 'Ấm áp, dựa trên bằng chứng', 'formality' => 'Trang trọng vừa phải', 'pronouns' => 'bạn', 'do' => ['Nêu nguồn'], 'dont' => ['Hứa khỏi hẳn']],
                'terms' => [['term' => 'HbA1c', 'definition' => 'Xét nghiệm đường huyết trung bình', 'support_ids' => [], 'avoid' => []]], 'reasoning_rules' => ['Mọi con số có nguồn'], 'needs_expert' => ['Xác nhận định nghĩa']],
        ]);

        $r = $this->builder($ai)->run($brain, $this->by, ['rounds' => 0]);

        $this->assertArrayNotHasKey('error', $r);
        $run = \App\Models\AgentRun::where('job_type', 'brain_build')->latest('id')->first();
        $this->assertSame('ready_for_review', $run->status);
        $stages = $run->steps->pluck('stage')->all();
        foreach (['scope', 'sources', 'ingest', 'verify', 'topics', 'eval', 'profile', 'report'] as $s) {
            $this->assertContains($s, $stages, "thiếu bước {$s}");
        }
        $src = $run->steps->firstWhere('stage', 'sources');
        $this->assertCount(1, $src->data['accepted'], 'chỉ nguồn who.int qua kiểm tên miền');
        $this->assertSame(['blocked', 'url_invalid'], collect($src->data['dropped'])->pluck('reason')->sort()->values()->all());
        $this->assertSame('strategist/brain-source-plan@1', $src->data['skill']['ref'], 'bước ghi kỹ năng đã dùng (cùng dạng với bước của bài viết)');

        $item = KnowledgeItem::first();
        $this->assertSame('proposed', $item->status, 'tầng C không bao giờ tự nhận');
        $this->assertSame(0, KnowledgeItem::where('status', 'approved')->count());
        $this->assertContains('kiểm lại: khớp', $item->tags);
        $this->assertNotNull($item->topic_id, 'đã gán chủ đề');
        $this->assertTrue(collect($ai->systems)->contains(fn ($s) => str_contains($s, 'HƯỚNG DẪN CHUYÊN NGÀNH BỔ SUNG')), 'rút mục có nạp kỹ năng claim-extract');

        $fresh = $brain->fresh();
        $this->assertSame('ai_draft', $fresh->settings['eval']['status']);
        $this->assertSame('ai_draft', $fresh->settings['profile_review']['status']);
        $this->assertNull(BrainProfile::where('brain_id', $brain->id)->first()->updated_by, 'nháp AI không ghi tên người');
        $terms = BrainProfile::where('brain_id', $brain->id)->first()->terminology['preferred'];
        $this->assertSame('', $terms[0]['definition'], 'định nghĩa không có mục kho hỗ trợ bị bỏ');
        $this->assertNotEmpty($fresh->settings['build_report']['todo_for_experts']);
        $this->actingAs($this->by);
        $this->get(\App\Filament\Resources\AgentRunResource::getUrl('view', ['record' => $run]))->assertOk()
            ->assertSee('Kho có 1 mục')->assertSee('Việc cho chuyên gia'); // hồi quy lỗi 500: dữ kiện bước hợp với trang phiên chạy; báo cáo hiện số đo thật, không phải "0 mục"
        $this->assertSame(1, $fresh->settings['build_report']['metrics']['verified_faithful']);
    }

    public function test_muc_tu_nhan_bi_kiem_lai_lech_nghia_duoc_hoan_ve_cho_duyet(): void
    {
        Http::fake([
            'suckhoe.gov.vn/robots.txt' => Http::response('', 404),
            'suckhoe.gov.vn/g1' => Http::response($this->page('Thư viện mở cửa từ 8 giờ sáng mỗi ngày. Phòng đọc mở cả cuối tuần.'), 200, ['Content-Type' => 'text/html']),
        ]);
        $brain = $this->brain(['ai_reliance' => 'reference', 'risk_class' => 'low']);
        $ai = $this->ai([
            'sources' => [['title' => 'Cổng', 'url' => 'https://suckhoe.gov.vn/g1', 'tier' => '1']],
            'items' => [$this->item('Giờ mở cửa', 'Thư viện mở cửa từ 8 giờ sáng mỗi ngày.', 'Thư viện mở cửa từ 8 giờ sáng mỗi ngày'),
                $this->item('Phòng đọc LỆCH', 'Phòng đọc mở cả cuối tuần.', 'Phòng đọc mở cả cuối tuần')],
        ]);

        $this->builder($ai)->run($brain, $this->by, ['rounds' => 0]);

        $ok = KnowledgeItem::where('title', 'Giờ mở cửa')->first();
        $bad = KnowledgeItem::where('title', 'Phòng đọc LỆCH')->first();
        $this->assertSame('approved', $ok->status, 'không phải tầng C, nguồn tin cậy, kiểm lại khớp: được tự nhận');
        $this->assertSame('proposed', $bad->status, 'kiểm lại lệch nghĩa thì hoàn về chờ duyệt');
        $this->assertNull($bad->review_mode);
        $this->assertContains('kiểm lại: lệch nghĩa', $bad->tags);
    }

    public function test_trang_chu_luc_cua_linh_vuc_duoc_nap_truoc_va_url_ai_sai_bi_loai(): void
    {
        Http::fake([
            'seed.example.gov.vn/robots.txt' => Http::response('', 404),
            'seed.example.gov.vn/chu-luc' => Http::response($this->page('Thư viện mở cửa từ 8 giờ sáng mỗi ngày.'), 200, ['Content-Type' => 'text/html']),
            'ai-doan.example.gov.vn/*' => Http::response('', 404),
        ]);
        $dir = sys_get_temp_dir().'/seeds_'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/dtd_t.php', "<?php return [['url' => 'https://seed.example.gov.vn/chu-luc', 'title' => 'Trang chủ lực', 'tier' => '1'], ['url' => 'http://khong-https.example/x']];");
        \App\Services\Brain\BrainSeeds::$dir = $dir;
        $brain = $this->brain(['ai_reliance' => 'reference', 'risk_class' => 'low']);
        $ai = $this->ai(['sources' => [['title' => 'AI đoán', 'url' => 'https://ai-doan.example.gov.vn/trang-khong-co', 'tier' => '1']],
            'items' => [$this->item('Giờ mở cửa', 'Thư viện mở cửa từ 8 giờ sáng mỗi ngày.', 'Thư viện mở cửa từ 8 giờ sáng mỗi ngày')]]);

        $this->builder($ai)->run($brain, $this->by, ['rounds' => 0]);
        \App\Services\Brain\BrainSeeds::$dir = null;
        @unlink($dir.'/dtd_t.php');
        @rmdir($dir);

        $run = \App\Models\AgentRun::where('job_type', 'brain_build')->latest('id')->first();
        $accepted = collect($run->steps->firstWhere('stage', 'sources')->data['accepted'])->pluck('url')->all();
        $this->assertSame('https://seed.example.gov.vn/chu-luc', $accepted[0], 'trang chủ lực đứng trước URL AI đề xuất');
        $this->assertNotContains('http://khong-https.example/x', $accepted, 'seed không phải https bị loại');
        $this->assertSame(1, KnowledgeItem::where('title', 'Giờ mở cửa')->count(), 'nạp được từ trang chủ lực; URL AI đoán sai không tải được nên không có mục');
        $failed = $run->steps->where('stage', 'source')->where('status', 'failed')->count();
        $this->assertSame(1, $failed, 'URL AI đoán sai được ghi là tải thất bại');
    }

    public function test_ten_mien_da_tu_choi_may_chu_khong_bi_de_xuat_lai(): void
    {
        $b = $this->builder($this->ai());
        $brain = $this->brain([]);
        $rp = new \ReflectionProperty($b, 'blockedHosts');
        $rp->setAccessible(true);
        $rp->setValue($b, ['heart.org']);
        $m = new \ReflectionMethod($b, 'validCandidates');
        $m->setAccessible(true);

        [$ok, $drop] = $m->invoke($b, [['url' => 'https://www.heart.org/en/a'], ['url' => 'https://www.who.int/b']], $brain, 8);

        $this->assertSame(['https://www.who.int/b'], array_column($ok, 'url'));
        $this->assertSame('host_blocks_us', $drop[0]['reason']);
    }

    public function test_trang_phien_chay_xay_kho_mo_duoc_ke_ca_khi_du_kien_buoc_co_khoa_failed_la_so(): void
    {
        // Hồi quy lỗi 500: bước đo độ phủ từng ghi 'failed' => số, trong khi trang hiểu 'failed' là danh sách luật lỗi của bước chấm.
        $run = \App\Models\AgentRun::create(['website_id' => $this->site->id, 'job_type' => 'brain_build', 'trigger' => 'manual', 'status' => 'ready_for_review', 'trust_tier' => 'T1', 'started_at' => now()]);
        foreach ([['eval', ['passed' => 2, 'partial' => 11, 'failed' => 2, 'total' => 15]], ['report', ['metrics' => ['items' => 3], 'todo_for_experts' => ['Duyệt']]], ['verify', ['checked' => 3, 'faithful' => 2, 'partial' => 1, 'unsupported' => 0]]] as $i => [$stage, $data]) {
            \App\Models\AgentRunStep::create(['agent_run_id' => $run->id, 'seq' => $i + 1, 'stage' => $stage, 'role' => 'editor', 'status' => 'warn', 'title' => 'Bước '.$stage, 'detail' => 'chi tiết', 'data' => $data, 'started_at' => now(), 'finished_at' => now()]);
        }
        $this->actingAs($this->by);

        $this->get(\App\Filament\Resources\AgentRunResource::getUrl('view', ['record' => $run]))->assertOk();
    }

    public function test_khong_co_website_hoac_khong_co_chu_de_thi_dung_voi_ly_do_ro(): void
    {
        $b = Brain::create(['name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active']);
        try {
            $this->builder($this->ai())->run($b, $this->by);
            $this->fail('phải ném lỗi khi Trí tuệ chưa gắn website');
        } catch (\App\Services\Knowledge\KnowledgeError $e) {
            $this->assertStringContainsString('gắn một website', $e->getMessage());
        }

        $b->websites()->attach($this->site->id, ['priority' => 10]);
        $r = $this->builder($this->ai())->run($b, $this->by);
        $this->assertStringContainsString('cây chủ đề', $r['error']);
    }

    public function test_nguoi_khong_du_quyen_khong_xay_duoc_kho(): void
    {
        $viewer = User::create(['name' => 'v', 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => 'viewer', 'is_active' => true]);

        $this->expectException(\App\Services\Knowledge\KnowledgeError::class);
        $this->builder($this->ai())->run($this->brain([]), $viewer);
    }

    public function test_khong_ghi_de_ho_so_do_nguoi_da_chinh(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $brain = $this->brain(['ai_reliance' => 'must_cite', 'risk_class' => 'high']);
        $item = KnowledgeItem::create(['brain_id' => $brain->id, 'type' => 'fact', 'title' => 'Có sẵn', 'body' => 'Nội dung có sẵn đủ dài.', 'status' => 'proposed', 'version' => 1, 'content_hash' => 'h1']);
        (new \App\Services\Brain\BrainService)->saveProfile($brain, ['voice' => ['tone' => 'Giọng do chuyên gia đặt', 'formality' => '', 'pronouns' => '', 'do' => [], 'dont' => []]], $this->by);
        $ai = $this->ai(['sources' => [], 'questions' => [], 'spec' => ['voice' => ['tone' => 'AI muốn đổi giọng', 'formality' => 'Trang trọng vừa phải', 'pronouns' => '', 'do' => [], 'dont' => []], 'terms' => [['term' => 'Insulin', 'definition' => 'Hormone tuyến tụy', 'support_ids' => [$item->id], 'avoid' => []]]]]);

        $this->builder($ai)->run($brain, $this->by, ['rounds' => 0]);

        $p = BrainProfile::where('brain_id', $brain->id)->first();
        $this->assertSame('Giọng do chuyên gia đặt', $p->voice['tone'], 'trường đã có người điền không bị ghi đè');
        $this->assertSame('Trang trọng vừa phải', $p->voice['formality'], 'trường trống được bổ sung');
        $this->assertSame($this->by->id, $p->updated_by, 'hồ sơ đã có người sửa: giữ tên người');
        $this->assertSame('Hormone tuyến tụy', collect($p->terminology['preferred'])->firstWhere('term', 'Insulin')['definition'], 'định nghĩa có mục kho hỗ trợ được giữ');
    }
}
