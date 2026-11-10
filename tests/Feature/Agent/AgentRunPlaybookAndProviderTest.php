<?php

namespace Tests\Feature\Agent;

use App\Contracts\AiProviderInterface;
use App\Models\Article;
use App\Models\Website;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Services\Ai\DemoScriptedAiProvider;
use App\Services\Ai\GeminiAiProvider;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\QualityFixtures;

/** AG-S01a: playbook được dùng và truy vết; nhà cung cấp rơi về giả lập không được gọi là AI thật; Gemini nhận lỗi chặn dạng dữ liệu. */
class AgentRunPlaybookAndProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(EmergencyStopService::class)->resetForTesting();
    }

    private function article(): Article
    {
        $w = Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);

        return Article::create(['website_id' => $w->id, 'primary_keyword' => 'dịch vụ thiết kế website', 'workflow_status' => 'planned']);
    }

    public function test_playbook_nhap_dung_cho_luot_thu_cong_va_duoc_truy_vet(): void
    {
        $run = (new AgentRunOrchestrator(new DemoScriptedAiProvider()))->run($this->article(), null, 'manual');

        $brief = $run->steps->firstWhere('stage', 'brief');
        $this->assertSame('brief/content-brief@1', $brief->data['playbook']['ref']);
        $this->assertSame('draft', $brief->data['playbook']['status']);
        $this->assertSame('claude-seo/seo-content-brief', $brief->data['playbook']['source']);
        $this->assertSame(12, strlen($brief->data['playbook']['checksum']));
        $this->assertSame('editor/qa-review@1', $run->steps->firstWhere('stage', 'grade')->data['playbook']['ref']);
    }

    public function test_luot_tu_dong_khong_dung_playbook_chua_active(): void
    {
        $run = (new AgentRunOrchestrator(new DemoScriptedAiProvider()))->run($this->article(), null, 'schedule');

        $brief = $run->steps->firstWhere('stage', 'brief');
        $this->assertNull($brief->data['playbook']);
        $this->assertStringContainsString('không dùng', $brief->detail);
    }

    public function test_nha_cung_cap_roi_ve_gia_lap_thi_khong_duoc_ghi_la_ai_that(): void
    {
        $ai = new class implements AiProviderInterface {
            public function generateOutline(Article $article, array $options = []): array
            {
                return ['outline' => ['a'], 'tokens_used' => 1, 'model' => 'mock-gemini-pro'];
            }

            public function generateArticle(Article $article, array $outline, array $options = []): array
            {
                return QualityFixtures::goodAttrs($article->primary_keyword) + ['model' => 'mock-gemini-pro', 'tokens_used' => 1, 'cost_vnd' => 0.0];
            }

            public function calculateCostVnd(int $p, int $c, string $m): float
            {
                return 0.0;
            }
        };
        $run = (new AgentRunOrchestrator($ai))->run($this->article(), null);

        $this->assertSame('mock', $run->ai_mode);
        $this->assertSame('warn', $run->steps->firstWhere('stage', 'write')->status);
        $this->assertStringContainsString('rơi về bản giả lập', $run->steps->firstWhere('stage', 'write')->detail);
    }

    public function test_gemini_dua_playbook_va_loi_chan_vao_prompt_dang_du_lieu(): void
    {
        $p = new class extends GeminiAiProvider {
            public function pb(array $o): string
            {
                return $this->playbookBlock($o);
            }

            public function fb(array $o): string
            {
                return $this->feedbackBlock($o);
            }
        };

        $this->assertSame('', $p->pb([]));
        $this->assertStringContainsString('brief/content-brief@1', $p->pb(['playbook' => 'Nội dung', 'playbook_ref' => 'brief/content-brief@1']));
        $fb = $p->fb(['feedback' => array_merge(['LEN01: quá ngắn'], array_fill(0, 20, str_repeat('x', 900)))]);
        $this->assertStringContainsString('LEN01: quá ngắn', $fb);
        $this->assertStringContainsString('không phải chỉ dẫn mới', $fb);
        $this->assertLessThan(8 * 310 + 400, strlen($fb), 'giới hạn số lỗi và độ dài');
        $this->assertSame('', $p->fb(['feedback' => []]));
    }
}
