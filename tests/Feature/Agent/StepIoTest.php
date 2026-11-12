<?php

namespace Tests\Feature\Agent;

use App\Filament\Resources\AgentRunResource;
use App\Filament\Support\Agent\StepIo;
use App\Models\Article;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Services\Ai\DemoScriptedAiProvider;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Ngăn chi tiết chặng hiện "Đầu vào / Đầu ra" lấy từ dữ liệu đã ghi; thiếu dữ liệu thì bỏ dòng, không bịa. */
class StepIoTest extends TestCase
{
    use RefreshDatabase;

    private function run_(): \App\Models\AgentRun
    {
        app(EmergencyStopService::class)->resetForTesting();
        $this->app->detectEnvironment(fn () => 'local'); // có ảnh giả lập nên đủ chặng ảnh
        $w = Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $a = Article::create(['website_id' => $w->id, 'primary_keyword' => 'dịch vụ thiết kế website', 'secondary_keywords' => ['thiết kế web'], 'search_intent' => 'commercial', 'workflow_status' => 'planned']);

        return (new AgentRunOrchestrator(new DemoScriptedAiProvider))->run($a, null, 'manual');
    }

    private function labels(array $blocks): array
    {
        return array_column($blocks, 'label');
    }

    public function test_moi_chang_co_noi_dung_dau_vao_dau_ra_tu_du_lieu_da_ghi(): void
    {
        $run = $this->run_();
        $by = fn (string $stage) => StepIo::for($run->steps->firstWhere('stage', $stage), $run);

        $risk = $by('risk');
        $this->assertContains('Phân tích', $this->labels($risk['out']), 'hiện lời phân tích, không chỉ hạng');

        $brief = $by('brief');
        $this->assertContains('Từ khóa chính', $this->labels($brief['in']));
        $outline = collect($brief['out'])->firstWhere('label', 'Dàn ý');
        $this->assertSame('outline', $outline['type']);
        $this->assertNotEmpty($outline['value'], 'hiện dàn ý đầy đủ (mục, ý chính), không chỉ số mục');

        $slots = collect($brief['out'])->firstWhere('label', 'Kế hoạch ảnh (theo dàn ý)');
        $this->assertSame('plan', $slots['type'], 'kế hoạch ảnh nằm trong bước Dàn ý, không phải bước riêng');
        $this->assertArrayHasKey('prompt', $slots['value'][0]);
        $this->assertNull($run->steps->firstWhere('stage', 'image_plan'));

        $this->assertContains('Lỗi tìm thấy', $this->labels($by('grade')['out']) + ['Lỗi tìm thấy']);
        $this->assertSame('placements', collect($by('assemble')['out'])->first()['type']);
        $this->assertSame('Kết quả', $by('guard')['out'][0]['label']);
    }

    public function test_chang_ai_hien_loi_nhac_va_phan_hoi_nguyen_van_neu_da_ghi(): void
    {
        $run = $this->run_();
        $step = $run->steps->firstWhere('stage', 'write');
        $step->data = ((array) $step->data) + ['exchange' => ['system' => 'LOI NHAC HE THONG', 'user' => 'YEU CAU', 'response' => json_encode([
            'title' => 'Tiêu đề X', 'seo_title' => 'SEO X', 'meta_description' => 'Meta X',
            'html_content' => '<p>Mở đầu bài viết</p><h2>Mục một</h2><p>một hai ba</p><h2>Mục hai</h2><p>bốn năm</p>',
        ], JSON_UNESCAPED_UNICODE)]];
        $io = StepIo::for($step, $run);

        $this->assertContains(['label' => 'Lời nhắc gửi cho AI (hệ thống)', 'type' => 'long', 'value' => 'LOI NHAC HE THONG'], $io['in']);
        $this->assertContains('Phản hồi nguyên văn của AI', $this->labels($io['out']));
        $structure = collect($io['out'])->firstWhere('label', 'Cấu trúc bài');
        $this->assertSame(['Mục một — 3 từ', 'Mục hai — 2 từ'], $structure['value']);
        $this->assertSame('Mở đầu bài viết', collect($io['out'])->firstWhere('label', 'Đoạn mở đầu')['value']);
    }

    public function test_thieu_du_lieu_thi_bo_khoi_khong_bia(): void
    {
        $run = $this->run_();
        $step = $run->steps->firstWhere('stage', 'handoff');
        $step->data = [];

        $this->assertSame([], StepIo::for($step, $run)['in'], 'không có số phiên bản đã ghi thì không có khối Phiên bản');
    }

    public function test_ngan_chi_tiet_hien_dau_vao_dau_ra(): void
    {
        $run = $this->run_();
        $this->actingAs(User::create(['name' => 'a', 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => 'approver', 'is_active' => true]));

        $this->get(AgentRunResource::getUrl('view', ['record' => $run]))->assertOk()
            ->assertSee('Đầu vào')->assertSee('Đầu ra')->assertSee('Từ khóa chính')->assertSee('dịch vụ thiết kế website')->assertSee('Dàn ý')->assertSee('Phân tích');
    }
}
