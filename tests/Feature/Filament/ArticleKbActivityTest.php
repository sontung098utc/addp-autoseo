<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ArticleResource;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Brain;
use App\Models\KnowledgeItem;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Tab Nghiên cứu và nguồn: người dùng thấy kho vừa được nạp/đề xuất gì (kèm liên kết nguồn) và nguồn AI nêu có liên kết hay chưa. */
class ArticleKbActivityTest extends TestCase
{
    use RefreshDatabase;

    private Article $article;

    protected function setUp(): void
    {
        parent::setUp();
        $site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->article = Article::create(['website_id' => $site->id, 'primary_keyword' => 'huyết áp thấp', 'workflow_status' => 'needs_review']);
        $this->actingAs(User::create(['name' => 'a', 'email' => 'a@x.vn', 'password' => 'secret123', 'role' => 'admin', 'is_active' => true]));
    }

    private function run_(array $steps): AgentRun
    {
        $r = AgentRun::create(['website_id' => $this->article->website_id, 'article_id' => $this->article->id, 'job_type' => 'article_write', 'trigger' => 'manual', 'status' => 'ready_for_review', 'ai_mode' => 'real', 'started_at' => now()]);
        foreach ($steps as $i => [$status, $title, $data]) {
            AgentRunStep::create(['agent_run_id' => $r->id, 'seq' => $i + 1, 'stage' => 'research', 'role' => 'system', 'status' => $status, 'title' => $title, 'detail' => 'Chi tiết: '.$title, 'data' => $data]);
        }

        return $r;
    }

    public function test_hien_hoat_dong_nap_kho_voi_ten_tri_tue_nguon_co_lien_ket_va_muc_vua_nap(): void
    {
        $brain = Brain::create(['name' => 'Dinh dưỡng và chế độ ăn', 'slug' => 'dd', 'status' => 'active', 'language' => 'vi']);
        $item = KnowledgeItem::create(['website_id' => $this->article->website_id, 'brain_id' => $brain->id, 'type' => 'fact', 'title' => 'Mục vừa nạp thử', 'body' => 'b', 'status' => 'approved', 'review_mode' => 'auto', 'version' => 1, 'content_hash' => 'h1']);
        $this->run_([['ok', 'Nạp kho «Dinh dưỡng và chế độ ăn»: 1 nguồn mới', ['purpose' => 'kb_coordination', 'brain_id' => $brain->id, 'brain_name' => $brain->name, 'items_auto' => 1, 'items_proposed' => 0,
            'sources' => [['url' => 'https://www.who.int/tt', 'title' => 'Tài liệu WHO', 'domain' => 'who.int']], 'item_ids' => ['auto' => [$item->id], 'proposed' => []]]]]);
        $html = $this->get(ArticleResource::getUrl('view', ['record' => $this->article]))->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="aw-kb-activity"', $html);
        $this->assertStringContainsString('Nạp kho «Dinh dưỡng và chế độ ăn»: 1 nguồn mới', $html);
        $this->assertStringContainsString('1 mục tự nhận, 0 mục chờ duyệt', $html);
        $this->assertStringContainsString('href="https://www.who.int/tt"', $html);
        $this->assertStringContainsString('Mục vừa nạp thử', $html);
    }

    public function test_khong_co_hoat_dong_kho_thi_noi_ro_va_de_xuat_lap_tri_tue_co_duong_dan(): void
    {
        $html = $this->get(ArticleResource::getUrl('view', ['record' => $this->article]))->getContent();
        $this->assertStringContainsString('chưa nạp hay tạo gì cho kho', $html);

        $this->run_([['warn', 'Đề xuất lập Trí tuệ cho lĩnh vực «Máy dập viên»', ['purpose' => 'kb_coordination', 'suggestion' => ['type' => 'new_brain', 'field' => 'Máy dập viên']]]]);
        $html2 = $this->get(ArticleResource::getUrl('view', ['record' => $this->article]))->getContent();
        $this->assertStringContainsString('Đề xuất lập Trí tuệ cho lĩnh vực «Máy dập viên»', $html2);
        $this->assertStringContainsString('data-testid="aw-kb-suggest"', $html2);
    }

    public function test_nguon_ai_neu_co_lien_ket_khi_khop_va_canh_bao_khi_khong(): void
    {
        $this->run_([['ok', 'Tìm được 1 tài liệu', ['purpose' => 'references', 'references' => [['domain' => 'mayoclinic.org', 'url' => 'https://www.mayoclinic.org/low-bp', 'title' => 'Low blood pressure - Mayo Clinic']]]]]);
        ArticleVersion::create(['article_id' => $this->article->id, 'version' => 1, 'title' => 'T', 'slug' => 't', 'html_content' => '<p>x</p>', 'outline' => [], 'sources_cited' => ['Mayo Clinic', 'Hiệp hội không có thật'], 'status' => 'pending_review', 'seo_title' => 't', 'meta_description' => 'm', 'focus_keyword' => 'k']);
        $html = $this->get(ArticleResource::getUrl('view', ['record' => $this->article]))->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="aw-cited-sources"', $html);
        $this->assertStringContainsString('href="https://www.mayoclinic.org/low-bp"', $html);
        $this->assertStringContainsString('data-testid="aw-cited-nolink"', $html);
        $this->assertStringContainsString('chưa có liên kết xác thực', $html);
    }
}
