<?php

namespace Tests\Feature\Agent;

use App\Contracts\AiProviderInterface;
use App\Contracts\StructuredAiInterface;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Roles\AgentProfiles;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Services\Agent\Run\RunPolicy;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\QualityFixtures;

/**
 * HUẤN LUYỆN CÓ THẬT SỰ ĐƯỢC ÁP DỤNG? Mỗi vai trò được gán một bài học mang DẤU RIÊNG; chạy phiên thật (AI giả ghi lại mọi prompt) rồi kiểm dấu của
 * vai trò nào đi vào prompt của bước nào, và dấu của vai trò này KHÔNG lọt sang bước của vai trò khác. AgentProfiles::APPLIED_AT chỉ được khai các bước đã được chứng minh ở đây.
 */
class AgentTrainingAppliedTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $roles = ['writer', 'strategist', 'editor', 'designer', 'manager', 'researcher', 'file_analyst'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->detectEnvironment(fn () => 'local');
        app(EmergencyStopService::class)->resetForTesting();
    }

    private function train(): void
    {
        $admin = User::create(['name' => 'a', 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => 'admin', 'is_active' => true]);
        foreach ($this->roles as $r) {
            AgentProfiles::addLesson($r, 'DAU-'.strtoupper($r).' luôn làm đúng theo tiêu chí đã dạy.', null, $admin);
        }
    }

    private function recorder(\stdClass $log): AiProviderInterface
    {
        return new class($log) implements AiProviderInterface, StructuredAiInterface
        {
            public function __construct(private \stdClass $log) {}

            public function generateOutline(Article $article, array $options = []): array
            {
                $this->log->outline[] = (string) ($options['role_profile'] ?? '');

                return ['outline' => ['Điều kiện nhận', 'Hồ sơ cần chuẩn bị'], 'tokens_used' => 1, 'model' => 'fake'];
            }

            public function generateArticle(Article $article, array $outline, array $options = []): array
            {
                $this->log->write[] = (string) ($options['role_profile'] ?? '');

                return QualityFixtures::goodAttrs((string) $article->primary_keyword) + ['tokens_used' => 1, 'cost_vnd' => 1.0, 'model' => 'fake', 'prompt_version' => 't'];
            }

            public function calculateCostVnd(int $promptTokens, int $completionTokens, string $model): float
            {
                return 0.0;
            }

            public function generateStructured(string $system, string $user): array
            {
                $this->log->structured[] = $system;

                return ['data' => ['scope' => ['write'], 'reuse_outline' => true, 'summary' => 'x', 'verdict' => 'tot', 'issues' => [], 'missing' => [], 'images' => [], 'designs' => []], 'tokens_used' => 1, 'cost_vnd' => 0.0, 'model' => 'fake'];
            }
        };
    }

    /** @return list<string> các prompt hệ thống có chứa cụm nhận diện bước */
    private function stepPrompts(\stdClass $log, string $phrase): array
    {
        return array_values(array_filter($log->structured ?? [], fn ($p) => str_contains($p, $phrase)));
    }

    private function article(): Article
    {
        $w = Website::create(['name' => 'Y', 'cms_type' => 'wordpress', 'base_url' => 'https://y.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $a = Article::create(['website_id' => $w->id, 'primary_keyword' => 'người tiểu đường nên ăn gì', 'workflow_status' => 'needs_review']);
        ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 'Bản cũ', 'slug' => 'b', 'outline' => ['A', 'B'], 'html_content' => '<h2>A</h2><p>x</p>', 'review_status' => 'needs_review']);

        return $a;
    }

    public function test_viet_va_dan_y_nhan_ho_so_cua_dung_vai_tro_khong_lan_sang_nhau(): void
    {
        $this->train();
        $log = new \stdClass;
        (new AgentRunOrchestrator($this->recorder($log), policy: new RunPolicy))->run($this->article(), null, 'manual', null, null, false, []);

        $this->assertNotEmpty($log->write, 'có lượt viết');
        foreach ($log->write as $p) {
            $this->assertStringContainsString('DAU-WRITER', $p, 'Người viết (viết và sửa) nhận bài học của mình');
            $this->assertStringNotContainsString('DAU-EDITOR', $p);
            $this->assertStringNotContainsString('DAU-STRATEGIST', $p);
        }
        $this->assertNotEmpty($log->outline, 'có lượt lập dàn ý');
        foreach ($log->outline as $p) {
            $this->assertStringContainsString('DAU-STRATEGIST', $p, 'Chiến lược lập dàn ý nhận bài học của mình');
            $this->assertStringNotContainsString('DAU-WRITER', $p);
        }
    }

    public function test_bien_tap_nhan_ho_so_o_ca_hai_buoc_ai_cua_minh(): void
    {
        $this->train();
        $log = new \stdClass;
        (new AgentRunOrchestrator($this->recorder($log), policy: new RunPolicy))->run($this->article(), null, 'manual', null, null, false, []);

        $edit = $this->stepPrompts($log, 'tổng biên tập khó tính');
        $this->assertNotEmpty($edit, 'bước biên tập viên có chạy');
        foreach ($edit as $p) {
            $this->assertStringContainsString('DAU-EDITOR', $p);
            $this->assertStringNotContainsString('DAU-WRITER', $p);
        }
    }

    public function test_quan_ly_nhan_ho_so_khi_doc_loi_nhan_lam_lai(): void
    {
        $this->train();
        $log = new \stdClass;
        (new AgentRunOrchestrator($this->recorder($log), policy: new RunPolicy))->run($this->article(), null, 'manual', null, null, false, [], ['message' => 'Chỉ tạo lại ảnh bìa, giữ nguyên bài viết.']);

        $mgr = $this->stepPrompts($log, 'Bạn là điều phối của đội AI viết bài SEO');
        $this->assertNotEmpty($mgr, 'bước điều phối có chạy');
        foreach ($mgr as $p) {
            $this->assertStringContainsString('DAU-MANAGER', $p);
            $this->assertStringNotContainsString('DAU-WRITER', $p);
        }
    }

    public function test_hoa_vien_nhan_ho_so_khi_thiet_ke_anh(): void
    {
        $this->train();
        $log = new \stdClass;
        (new AgentRunOrchestrator($this->recorder($log), policy: new RunPolicy))->run($this->article(), null, 'manual', null, null, false, [], ['message' => 'Chỉ tạo lại ảnh bìa, giữ nguyên bài viết.']);

        $design = array_merge($this->stepPrompts($log, 'đặt yêu cầu ảnh'), $this->stepPrompts($log, 'họa viên thiết kế'));
        $this->assertNotEmpty($design, 'bước thiết kế ảnh có chạy');
        foreach ($design as $p) {
            $this->assertStringContainsString('DAU-DESIGNER', $p);
            $this->assertStringNotContainsString('DAU-WRITER', $p);
        }
    }

    public function test_nguoi_nghien_cuu_va_phan_tich_tep_nhan_ho_so_qua_jsonAi_va_mapper(): void
    {
        $this->train();
        // Người nghiên cứu: mọi lượt AI dạng JSON của bước điều phối kho đi qua jsonAi('researcher').
        $o = new AgentRunOrchestrator($this->recorder(new \stdClass), policy: new RunPolicy);
        $m = new \ReflectionMethod($o, 'jsonAi');
        $m->setAccessible(true);
        $box = new \stdClass;
        $ai = $m->invoke($o, $this->recorder($box), 'researcher', 0);
        $ai->call('Phân loại chủ đề.', 'dữ liệu');
        $this->assertStringContainsString('DAU-RESEARCHER', $box->structured[0]);
        $this->assertStringNotContainsString('DAU-WRITER', $box->structured[0]);

        // Người phân tích tệp: bộ nhận diện cột của nhập file kế hoạch.
        $box2 = new \stdClass;
        (new \App\Services\Planning\Import\PlanFileMapper($this->recorder($box2)))->map([['name' => 'S', 'rows' => [['Từ khóa', 'Ngày'], ['a b c', '1/1/2026']], 'total' => 2, 'truncated' => false]]);
        $this->assertNotEmpty($box2->structured ?? [], 'AI nhận diện cột có được gọi');
        $this->assertStringContainsString('DAU-FILE_ANALYST', $box2->structured[0]);
    }

    public function test_ho_so_duoc_khung_va_nhac_khong_ghi_de_quy_tac_an_toan(): void
    {
        $this->train();
        $log = new \stdClass;
        (new AgentRunOrchestrator($this->recorder($log), policy: new RunPolicy))->run($this->article(), null, 'manual', null, null, false, []);
        $p = $this->stepPrompts($log, 'tổng biên tập khó tính')[0];
        $this->assertStringContainsString('HỒ SƠ VAI TRÒ', $p);
        $this->assertStringContainsString('KHÔNG được ghi đè quy tắc an toàn', $p);
    }

    public function test_vai_tro_chua_tung_dao_tao_thi_khong_them_gi_vao_prompt(): void
    {
        $log = new \stdClass;
        (new AgentRunOrchestrator($this->recorder($log), policy: new RunPolicy))->run($this->article(), null, 'manual', null, null, false, []);
        foreach ($log->structured ?? [] as $p) {
            $this->assertStringNotContainsString('HỒ SƠ VAI TRÒ', $p, 'chưa dạy gì thì không có khối hồ sơ');
        }
    }

    public function test_danh_sach_applied_at_chi_gom_vai_tro_da_duoc_chung_minh(): void
    {
        $this->assertEqualsCanonicalizing($this->roles, array_keys(AgentProfiles::APPLIED_AT));
    }
}
