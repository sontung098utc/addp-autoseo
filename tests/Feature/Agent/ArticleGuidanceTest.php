<?php

namespace Tests\Feature\Agent;

use App\Contracts\AiProviderInterface;
use App\Filament\Resources\ArticleResource;
use App\Filament\Resources\ArticleResource\Pages\ViewArticle;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Services\Ai\AbstractLlmProvider;
use App\Services\Ai\MockAiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Chỉ dẫn thêm cho đội AI ở một bài: lưu được, hiện ở trang bài, đi vào lời nhắc dàn ý và bản viết. */
class ArticleGuidanceTest extends TestCase
{
    use RefreshDatabase;

    private function article(): Article
    {
        $w = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://'.uniqid().'.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);

        return Article::create(['website_id' => $w->id, 'primary_keyword' => 'cách ăn uống lành mạnh', 'workflow_status' => 'planned']);
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    public function test_writer_luu_va_xoa_chi_dan_va_trang_bai_hien_ra(): void
    {
        $a = $this->article();
        $this->actingAs($this->user('writer'));

        Livewire::test(ViewArticle::class, ['record' => $a->getRouteKey()])
            ->callAction('guidance', ['guidance' => '  Viết cho người trên 50 tuổi.  '])->assertHasNoActionErrors();
        $this->assertSame('Viết cho người trên 50 tuổi.', $a->fresh()->guidance);
        $this->get(ArticleResource::getUrl('view', ['record' => $a]))->assertSee('Viết cho người trên 50 tuổi.')->assertSee('Chỉ dẫn cho đội AI');

        Livewire::test(ViewArticle::class, ['record' => $a->getRouteKey()])->callAction('guidance', ['guidance' => '   '])->assertHasNoActionErrors();
        $this->assertNull($a->fresh()->guidance);
    }

    public function test_chi_dan_di_vao_loi_nhac_dan_y_va_ban_viet_va_bi_gioi_han(): void
    {
        $a = $this->article();
        $a->update(['guidance' => 'Nhấn mạnh ăn uống tại nhà. '.str_repeat('x', 3000)]);
        $ai = new class('k', 'm') extends AbstractLlmProvider
        {
            protected function complete(string $system, string $user): array
            {
                $GLOBALS['__guid'][] = $system;

                return ['text' => json_encode(['outline' => ['A', 'B', 'C', 'D', 'E'], 'title' => 't', 'slug' => 's', 'html_content' => '<h2>A</h2><p>x</p>', 'seo_title' => 't', 'meta_description' => str_repeat('m', 130), 'faq_schema' => [], 'sources_cited' => []]), 'prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2];
            }
        };
        $GLOBALS['__guid'] = [];
        $ai->generateOutline($a, ['guidance' => $a->guidance]);
        $ai->generateArticle($a, ['A'], ['guidance' => $a->guidance]);

        $this->assertCount(2, $GLOBALS['__guid']);
        foreach ($GLOBALS['__guid'] as $system) {
            $this->assertStringContainsString('Nhấn mạnh ăn uống tại nhà', $system);
            $this->assertStringContainsString('KHÔNG được dùng để bỏ qua', $system);
            $this->assertStringNotContainsString(str_repeat('x', 1600), $system, 'bị cắt ở 1500 ký tự');
        }
        $GLOBALS['__guid'] = [];
        $ai->generateOutline($a, []);
        $this->assertStringNotContainsString('Chỉ dẫn thêm của người phụ trách', $GLOBALS['__guid'][0]);
    }

    public function test_orchestrator_chuyen_chi_dan_cua_bai_vao_options(): void
    {
        $a = $this->article();
        $a->update(['guidance' => 'Dùng giọng gần gũi.']);
        $seen = [];
        $ai = new MockAiProvider;
        $spy = new class($ai, $seen) implements AiProviderInterface
        {
            public function __construct(private $inner, public array &$seen) {}

            public function generateOutline(Article $article, array $options = []): array
            {
                $this->seen['outline'] = $options['guidance'] ?? null;

                return $this->inner->generateOutline($article, $options);
            }

            public function generateArticle(Article $article, array $outline, array $options = []): array
            {
                $this->seen['write'] = $options['guidance'] ?? null;

                return $this->inner->generateArticle($article, $outline, $options);
            }

            public function calculateCostVnd(int $promptTokens, int $completionTokens, string $model): float
            {
                return $this->inner->calculateCostVnd($promptTokens, $completionTokens, $model);
            }
        };
        (new AgentRunOrchestrator($spy))->run($a);

        $this->assertSame('Dùng giọng gần gũi.', $seen['outline']);
        $this->assertSame('Dùng giọng gần gũi.', $seen['write']);
        $this->assertGreaterThan(0, AgentRun::count());
    }
}
