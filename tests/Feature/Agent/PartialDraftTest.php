<?php

namespace Tests\Feature\Agent;

use App\Contracts\AiProviderInterface;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\Website;
use App\Services\Agent\Run\AgentRunOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\QualityFixtures;

/** Phiên bị cắt giữa chừng mà đã có bản nháp: lưu lại, không mất công; chưa có bản nháp thì thất bại như cũ. */
class PartialDraftTest extends TestCase
{
    use RefreshDatabase;

    private function orchestrator(): AgentRunOrchestrator
    {
        $ai = new class implements AiProviderInterface
        {
            public function generateOutline(Article $article, array $options = []): array
            {
                return ['outline' => ['A'], 'tokens_used' => 1, 'model' => 'fake'];
            }

            public function generateArticle(Article $article, array $outline, array $options = []): array
            {
                return [];
            }

            public function calculateCostVnd(int $promptTokens, int $completionTokens, string $model): float
            {
                return 0.0;
            }
        };

        return new AgentRunOrchestrator($ai, sleep: fn () => null);
    }

    private function setup_(): array
    {
        $site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $article = Article::create(['website_id' => $site->id, 'primary_keyword' => 'từ khóa thử', 'workflow_status' => 'planned']);
        $run = AgentRun::create(['website_id' => $site->id, 'article_id' => $article->id, 'status' => 'running', 'trust_tier' => 'T1', 'trigger' => 'manual', 'started_at' => now(), 'ai_mode' => 'mock']);

        return [$article, $run];
    }

    private function invokePrivate(AgentRunOrchestrator $o, string $method, array $args)
    {
        $m = new \ReflectionMethod($o, $method);
        $m->setAccessible(true);

        return $m->invoke($o, ...$args);
    }

    public function test_co_ban_nhap_thi_luu_lai_va_giu_phien(): void
    {
        [$article, $run] = $this->setup_();
        $o = $this->orchestrator();
        $p = new \ReflectionProperty($o, 'bestAttrs');
        $p->setAccessible(true);
        $p->setValue($o, QualityFixtures::goodAttrs($article->primary_keyword) + ['article_id' => $article->id]);

        $r = $this->invokePrivate($o, 'finishKeepingDraft', [$run, $article, 'Hết hạn mức theo ngày. Chưa có bản nháp nào được tạo.']);

        $this->assertSame('held', $r->status);
        $this->assertNotNull($r->final_version_id);
        $this->assertSame(1, $article->versions()->count());
        $this->assertStringContainsString('Đã lưu bản nháp tốt nhất', (string) $r->outcome_reason);
        $this->assertStringNotContainsString('Chưa có bản nháp nào được tạo', (string) $r->outcome_reason);
        $this->assertTrue($r->steps->contains(fn ($s) => $s->stage === 'decision' && ($s->data['decision'] ?? '') === 'partial_saved'));
    }

    public function test_chua_co_ban_nhap_thi_that_bai_nhu_cu(): void
    {
        [$article, $run] = $this->setup_();

        $r = $this->invokePrivate($this->orchestrator(), 'finishKeepingDraft', [$run, $article, 'Hết hạn mức. Chưa có bản nháp nào được tạo.']);

        $this->assertSame('failed', $r->status);
        $this->assertNull($r->final_version_id);
        $this->assertSame(0, $article->versions()->count());
    }
}
