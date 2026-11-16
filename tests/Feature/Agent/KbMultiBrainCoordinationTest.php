<?php

namespace Tests\Feature\Agent;

use App\Contracts\StructuredAiInterface;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\Brain;
use App\Models\KnowledgeGap;
use App\Models\KnowledgeTopic;
use App\Models\Website;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Services\Ai\MockAiProvider;
use App\Services\Brain\BrainContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Điều phối kho nhiều Trí tuệ: xét cả Trí tuệ chưa gắn, mỗi kho lo phần của nó, không đề xuất kho rộng trùm lên kho chuyên biệt có sẵn. AI phân loại là bộ giả. */
class KbMultiBrainCoordinationTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    private Brain $dd;

    private Brain $dn;

    private Brain $trial;

    private Article $article;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->dd = Brain::create(['name' => 'Đái tháo đường (tiểu đường)', 'slug' => 'dd', 'domain_key' => 'dai_thao_duong', 'status' => 'active', 'description' => 'Kho đái tháo đường']);
        $this->trial = Brain::create(['name' => 'Đái tháo đường (thử quy trình)', 'slug' => 'dd-thu', 'domain_key' => 'dai_thao_duong', 'status' => 'active']);
        $this->dn = Brain::create(['name' => 'Dinh dưỡng và chế độ ăn', 'slug' => 'dn', 'domain_key' => 'dinh_duong', 'status' => 'active']);
        $this->dn->websites()->attach($this->site->id, ['priority' => 20]); // dd CHƯA gắn website
        KnowledgeTopic::create(['brain_id' => $this->dd->id, 'name' => 'Các thể bệnh']);
        $this->article = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'thực đơn cho người tiểu đường', 'workflow_status' => 'planned']);
    }

    private function ai(array $answer): StructuredAiInterface
    {
        return new class($answer) extends MockAiProvider implements StructuredAiInterface
        {
            public static string $lastSystem = '';

            public static string $lastUser = '';

            public function __construct(private array $answer) {}

            public function generateStructured(string $system, string $user): array
            {
                self::$lastSystem = $system;
                self::$lastUser = $user;

                return ['data' => $this->answer, 'tokens_used' => 10, 'cost_vnd' => 5.0, 'model' => 'fake'];
            }
        };
    }

    /** @return array{0:AgentRunOrchestrator,1:AgentRun,2:\ReflectionClass} */
    private function harness(StructuredAiInterface $ai): array
    {
        $o = new AgentRunOrchestrator($ai);
        $rf = new \ReflectionClass($o);
        $p = $rf->getProperty('brainCtx');
        $p->setAccessible(true);
        $p->setValue($o, BrainContext::forArticle($this->article));
        $run = AgentRun::create(['article_id' => $this->article->id, 'website_id' => $this->site->id, 'status' => 'running', 'trigger' => 'manual', 'started_at' => now(), 'ai_mode' => 'real']);

        return [$o, $run, $rf];
    }

    private function coordinate(AgentRunOrchestrator $o, \ReflectionClass $rf, AgentRun $run): void
    {
        $m = $rf->getMethod('coordinateKnowledge');
        $m->setAccessible(true);
        $m->invoke($o, $run, $this->article, 'high');
    }

    private function prop(AgentRunOrchestrator $o, \ReflectionClass $rf, string $n): mixed
    {
        $p = $rf->getProperty($n);
        $p->setAccessible(true);

        return $p->getValue($o);
    }

    public function test_xet_ca_tri_tue_chua_gan_va_chi_mot_ban_goc_moi_linh_vuc_trong_danh_sach(): void
    {
        $ai = $this->ai(['matches' => [['brain_id' => $this->dd->id, 'facet' => 'tiểu đường']], 'brain_id' => $this->dd->id, 'uncovered' => false, 'recurring' => false, 'reason' => 'x']);
        [$o, $run, $rf] = $this->harness($ai);
        $this->coordinate($o, $rf, $run);

        $user = $ai::$lastUser;
        $this->assertStringContainsString('Đái tháo đường (tiểu đường)', $user, 'Trí tuệ chưa gắn website vẫn được điều phối xét');
        $this->assertStringContainsString('CHƯA gắn', $user);
        $this->assertStringContainsString('Các thể bệnh', $user, 'kèm các mảng chủ đề để đánh giá độ rộng');
        $this->assertStringNotContainsString('thử quy trình', $user, 'bản thử trùng lĩnh vực không được đưa vào (một lĩnh vực một Trí tuệ, lấy bản gốc)');
        $this->assertStringContainsString('ĐỘ RỘNG', $ai::$lastSystem);
        $this->assertStringContainsString('CHUYÊN BIỆT NHẤT', $ai::$lastSystem);
    }

    public function test_tri_tue_khop_chua_gan_thi_duoc_tu_gan_va_chi_dung_kien_thuc_cua_no(): void
    {
        $ai = $this->ai(['matches' => [['brain_id' => $this->dd->id, 'facet' => 'tiểu đường']], 'brain_id' => $this->dd->id, 'uncovered' => false, 'recurring' => false, 'reason' => 'x']);
        [$o, $run, $rf] = $this->harness($ai);
        $this->assertFalse($this->dd->websites()->where('websites.id', $this->site->id)->exists());
        $this->coordinate($o, $rf, $run);

        $this->assertTrue($this->dd->websites()->where('websites.id', $this->site->id)->exists(), 'đã gắn vào website');
        $this->assertSame([$this->dd->id], $this->prop($o, $rf, 'brainCtx')->ids(), 'phạm vi thu hẹp về Trí tuệ khớp (Dinh dưỡng không lọt vào)');
        $this->assertSame($this->dd->id, $this->prop($o, $rf, 'matchedBrainId'));
        $titles = $run->steps()->pluck('title')->implode(' | ');
        $this->assertStringContainsString('Gắn Trí tuệ «Đái tháo đường (tiểu đường)» vào website', $titles);
        $this->assertSame(0, KnowledgeGap::where('query', 'like', \App\Services\Knowledge\Suggest\KnowledgeSuggestions::BRAIN_PREFIX.'%')->count(), 'không đề xuất lập Trí tuệ mới khi kho có sẵn đã bao quát');
        $this->assertNotEmpty(Brain::find($this->dd->id)->settings['auto_attached'][(string) $this->site->id]['reason'], 'có dấu vết tự gắn để hoàn tác');
    }

    public function test_chu_de_cham_hai_linh_vuc_thi_moi_tri_tue_lo_phan_cua_no(): void
    {
        $ai = $this->ai(['matches' => [['brain_id' => $this->dd->id, 'facet' => 'tiểu đường'], ['brain_id' => $this->dn->id, 'facet' => 'thực đơn']], 'brain_id' => $this->dd->id, 'uncovered' => false, 'recurring' => false, 'reason' => 'x']);
        [$o, $run, $rf] = $this->harness($ai);
        $this->coordinate($o, $rf, $run);

        $this->assertEqualsCanonicalizing([$this->dd->id, $this->dn->id], $this->prop($o, $rf, 'brainCtx')->ids(), 'cả hai kho được dùng');
        $steps = $run->steps()->where('stage', 'research')->get();
        $perBrain = $steps->filter(fn ($s) => ($s->data['purpose'] ?? null) === 'kb_coordination' && isset($s->data['facet']));
        $this->assertCount(2, $perBrain, 'mỗi Trí tuệ có bước điều phối riêng (kho trống nên đều cần nạp)');
        $facets = $perBrain->pluck('data.facet')->all();
        $this->assertEqualsCanonicalizing(['tiểu đường', 'thực đơn'], $facets, 'mỗi kho lo đúng phần của nó');
        $this->assertContains($this->dd->id, $perBrain->pluck('data.brain_id')->all());
        $this->assertContains($this->dn->id, $perBrain->pluck('data.brain_id')->all());
    }

    public function test_de_xuat_kho_moi_chi_cho_phan_chua_ai_bao_quat(): void
    {
        $ai = $this->ai(['matches' => [['brain_id' => $this->dd->id, 'facet' => 'tiểu đường']], 'brain_id' => $this->dd->id, 'uncovered' => true, 'field' => 'Bệnh tuyến giáp', 'covers' => ['Cường giáp', 'Suy giáp'], 'sensitive' => true, 'recurring' => true, 'reason' => 'x']);
        [$o, $run, $rf] = $this->harness($ai);
        $this->coordinate($o, $rf, $run);

        $sg = $run->steps()->get()->first(fn ($s) => isset($s->data['suggestion']));
        $this->assertNotNull($sg);
        $this->assertSame('Bệnh tuyến giáp', $sg->data['suggestion']['field']);
        $this->assertStringContainsString('chỉ bao quát một phần', $sg->detail);
        $this->assertSame(1, KnowledgeGap::where('query', 'like', '%Bệnh tuyến giáp')->count());
        $this->assertSame([$this->dd->id], $this->prop($o, $rf, 'brainCtx')->ids(), 'vẫn dùng kho Đái tháo đường cho phần đã có');
    }

    public function test_khong_khop_va_khong_lap_lai_thi_la_chu_de_le(): void
    {
        $ai = $this->ai(['matches' => [], 'brain_id' => null, 'uncovered' => true, 'field' => 'Du lịch', 'recurring' => false, 'reason' => 'x']);
        [$o, $run, $rf] = $this->harness($ai);
        $this->coordinate($o, $rf, $run);
        $this->assertSame([], $this->prop($o, $rf, 'brainCtx')->ids(), 'không Trí tuệ nào khớp thì không dùng kho nào');
        $this->assertStringContainsString('Chủ đề lẻ', $run->steps()->pluck('title')->implode('|'));
        $this->assertFalse($this->dd->websites()->where('websites.id', $this->site->id)->exists(), 'không gắn Trí tuệ khi không khớp');
    }
}
