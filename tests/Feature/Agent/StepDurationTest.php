<?php

namespace Tests\Feature\Agent;

use App\Contracts\AiProviderInterface;
use App\Filament\Support\Agent\RunFlow;
use App\Filament\Support\Agent\StepIo;
use App\Models\Article;
use App\Models\Website;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Services\Agent\Run\RunPolicy;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\QualityFixtures;

/** Mỗi bước ghi thời lượng ĐO THẬT (mili giây) và phần chờ AI; giao diện dùng số đó. AI GIẢ có độ trễ cố định. */
class StepDurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_buoc_ai_ghi_duration_ms_va_ai_ms_con_buoc_luat_la_gan_0(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        app(EmergencyStopService::class)->resetForTesting();
        $w = Website::create(['name' => 'Y', 'cms_type' => 'wordpress', 'base_url' => 'https://y.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $a = Article::create(['website_id' => $w->id, 'primary_keyword' => 'học bổng toàn phần', 'workflow_status' => 'planned']);

        $ai = new class implements AiProviderInterface
        {
            public function generateOutline(Article $article, array $options = []): array
            {
                usleep(300_000);

                return ['outline' => ['Mục một'], 'tokens_used' => 1, 'model' => 'fake'];
            }

            public function generateArticle(Article $article, array $outline, array $options = []): array
            {
                usleep(400_000);

                return QualityFixtures::goodAttrs('học bổng toàn phần') + ['tokens_used' => 1, 'cost_vnd' => 1.0, 'model' => 'fake', 'prompt_version' => 't'];
            }

            public function calculateCostVnd(int $promptTokens, int $completionTokens, string $model): float
            {
                return 0.0;
            }
        };
        $run = (new AgentRunOrchestrator($ai, policy: new RunPolicy))->run($a, null);

        $by = fn (string $stage) => $run->steps->firstWhere('stage', $stage);
        $brief = (array) $by('brief')->data;
        $write = (array) $by('write')->data;
        $this->assertGreaterThanOrEqual(300, $brief['ai_ms']);
        $this->assertGreaterThanOrEqual($brief['ai_ms'], $brief['duration_ms']);
        $this->assertGreaterThanOrEqual(400, $write['ai_ms']);
        $this->assertLessThan(150, ((array) $by('guard')->data)['duration_ms'], 'bước luật tức thời');
        $this->assertArrayNotHasKey('ai_ms', ((array) $by('guard')->data));

        $d = RunFlow::build($run, $run->steps, false, false, null)['durations'];
        $this->assertSame(round($write['duration_ms'] / 1000, 1), $d[$by('write')->id]);

        $out = StepIo::for($by('write'), $run)['out'];
        $line = collect($out)->firstWhere('label', 'Thời gian đo thật');
        $this->assertNotNull($line);
        $this->assertStringContainsString('chờ AI', $line['value']);
    }
}
