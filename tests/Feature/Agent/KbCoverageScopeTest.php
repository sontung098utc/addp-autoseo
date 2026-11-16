<?php

namespace Tests\Feature\Agent;

use App\Models\Article;
use App\Models\Brain;
use App\Models\KnowledgeItem;
use App\Models\Website;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Services\Ai\MockAiProvider;
use App\Services\Brain\BrainContext;
use App\Services\Knowledge\KnowledgeDedupe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "Độ đủ của kho" phải đếm trên CHÍNH Trí tuệ khớp: mục của Trí tuệ khác gắn cùng website không được làm Trí tuệ khớp (đang trống) bị coi là đủ. */
class KbCoverageScopeTest extends TestCase
{
    use RefreshDatabase;

    private function item(Brain $b, string $title, string $body): void
    {
        KnowledgeItem::create(['brain_id' => $b->id, 'type' => 'fact', 'title' => $title, 'body' => $body, 'status' => 'approved', 'version' => 1, 'created_by' => 'rule', 'content_hash' => KnowledgeDedupe::hash($title, $body)]);
    }

    public function test_dem_do_phu_sau_khi_thu_hep_ve_tri_tue_khop_khong_tinh_muc_cua_tri_tue_khac(): void
    {
        $site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $tim = Brain::create(['name' => 'Tim mạch', 'slug' => 'tim-mach', 'domain_key' => 'tim_mach_huyet_ap', 'status' => 'active']);
        $dd = Brain::create(['name' => 'Dinh dưỡng', 'slug' => 'dinh-duong', 'domain_key' => 'dinh_duong', 'status' => 'active']);
        $tim->websites()->attach($site->id, ['priority' => 30]);
        $dd->websites()->attach($site->id, ['priority' => 20]);
        foreach (['thực đơn giảm muối', 'thực đơn DASH', 'thực đơn cho người huyết áp cao', 'thực đơn dinh dưỡng tim mạch', 'chế độ ăn giảm muối'] as $t) {
            $this->item($tim, 'Dinh dưỡng '.$t, 'Thực đơn và dinh dưỡng: '.$t.' giúp kiểm soát huyết áp.');
        }
        $article = Article::create(['website_id' => $site->id, 'primary_keyword' => 'thực đơn dinh dưỡng', 'workflow_status' => 'planned']);

        $o = new AgentRunOrchestrator(new MockAiProvider);
        $rf = new \ReflectionClass($o);
        $p = $rf->getProperty('brainCtx');
        $p->setAccessible(true);
        $p->setValue($o, BrainContext::forArticle($article));
        $count = function () use ($rf, $o, $article) {
            $m = $rf->getMethod('kbForPlanner');
            $m->setAccessible(true);

            return count($m->invoke($o, $article));
        };

        $this->assertGreaterThanOrEqual(AgentRunOrchestrator::KB_ENOUGH, $count(), 'trước khi thu hẹp: mục của Tim mạch làm kho trông như đã đủ');
        $narrow = $rf->getMethod('narrowBrainScope');
        $narrow->setAccessible(true);
        $narrow->invoke($o, [$dd->id]);
        $this->assertSame(0, $count(), 'sau khi thu hẹp về Trí tuệ Dinh dưỡng (trống): không còn mục nào, phải nạp kho');

        $p->setValue($o, BrainContext::forArticle($article)); // thu hẹp chỉ đi một chiều: dựng lại ngữ cảnh đầy đủ rồi thu hẹp về Tim mạch
        $narrow->invoke($o, [$tim->id]);
        $this->assertGreaterThanOrEqual(AgentRunOrchestrator::KB_ENOUGH, $count(), 'thu hẹp về Tim mạch thì đủ');
    }
}
