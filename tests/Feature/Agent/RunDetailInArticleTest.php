<?php

namespace Tests\Feature\Agent;

use App\Filament\Resources\AgentRunResource;
use App\Filament\Resources\ArticleResource;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Article;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Chi tiết phiên chạy nằm trong tab "Phiên chạy" của trang bài; liên kết thống nhất qua AgentRunResource::viewUrl; liên kết cũ chuyển hướng. */
class RunDetailInArticleTest extends TestCase
{
    use RefreshDatabase;

    private Article $article;

    protected function setUp(): void
    {
        parent::setUp();
        $site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->article = Article::create(['website_id' => $site->id, 'primary_keyword' => 'từ khóa thử', 'workflow_status' => 'planned']);
        $this->actingAs(User::create(['name' => 'a', 'email' => 'a@x.vn', 'password' => 'secret123', 'role' => 'approver', 'is_active' => true]));
    }

    private function run_(?Article $a, string $title): AgentRun
    {
        $r = AgentRun::create(['website_id' => $this->article->website_id, 'article_id' => $a?->id, 'job_type' => $a ? 'article_write' : 'market_research', 'trigger' => 'manual', 'status' => 'ready_for_review', 'ai_mode' => 'mock', 'started_at' => now()]);
        AgentRunStep::create(['agent_run_id' => $r->id, 'seq' => 1, 'stage' => $a ? 'guard' : 'collect', 'role' => 'system', 'status' => 'ok', 'title' => $title]);

        return $r;
    }

    public function test_lien_ket_phien_cua_bai_tro_vao_tab_phien_chay_con_phien_khong_gan_bai_giu_trang_rieng(): void
    {
        $r = $this->run_($this->article, 'Được phép chạy');
        $url = AgentRunResource::viewUrl($r);
        $this->assertSame(ArticleResource::getUrl('view', ['record' => $this->article]).'?phien='.$r->id.'#tab-activity', $url);
        $this->assertSame($url, AgentRunResource::viewUrl($r->id), 'nhận cả mã phiên');

        $solo = $this->run_(null, 'Gom từ khóa');
        $this->assertSame(AgentRunResource::getUrl('view', ['record' => $solo]), AgentRunResource::viewUrl($solo));
    }

    public function test_trang_phien_cu_cua_bai_chuyen_huong_phien_khong_gan_bai_van_xem_duoc(): void
    {
        $r = $this->run_($this->article, 'Được phép chạy');
        $this->get(AgentRunResource::getUrl('view', ['record' => $r]))->assertRedirect(AgentRunResource::viewUrl($r));

        $solo = $this->run_(null, 'Gom từ khóa');
        $this->get(AgentRunResource::getUrl('view', ['record' => $solo]))->assertOk()->assertSee('Gom từ khóa');
    }

    public function test_trang_bai_hien_dung_phien_theo_phien_va_mac_dinh_phien_moi_nhat_va_tab_la_phien_chay(): void
    {
        $old = $this->run_($this->article, 'Bước của phiên cũ');
        $new = $this->run_($this->article, 'Bước của phiên mới');
        $base = ArticleResource::getUrl('view', ['record' => $this->article]);

        $html = $this->get($base)->assertOk()->getContent();
        $this->assertStringContainsString('Phiên chạy', $html);
        $this->assertStringContainsString('data-testid="aw-run-detail"', $html);
        $this->assertStringContainsString('Phiên #'.$new->id, $html, 'mặc định phiên mới nhất');
        $this->assertStringContainsString('Bước của phiên mới', $html);

        $sel = $this->get($base.'?phien='.$old->id)->assertOk()->getContent();
        $this->assertStringContainsString('<h3 class="aw-run-detail__title">Phiên #'.$old->id, $sel);
        $this->assertStringContainsString('Bước của phiên cũ', $sel);
        // Đủ bốn phần của phiên trong tab lồng bên trong trang bài.
        foreach (['Diễn biến', 'Báo cáo', 'Chi phí và model', 'Dữ liệu'] as $label) {
            $this->assertStringContainsString($label, $sel);
        }
    }

    public function test_phien_cua_bai_khac_khong_bi_xem_lan_sang(): void
    {
        $other = Article::create(['website_id' => $this->article->website_id, 'primary_keyword' => 'bài khác', 'workflow_status' => 'planned']);
        $theirs = $this->run_($other, 'Bước của bài khác');
        $mine = $this->run_($this->article, 'Bước của bài này');

        $html = $this->get(ArticleResource::getUrl('view', ['record' => $this->article]).'?phien='.$theirs->id)->assertOk()->getContent();
        $this->assertStringNotContainsString('Bước của bài khác', $html, 'mã phiên của bài khác bị bỏ qua, rơi về phiên mới nhất của bài này');
        $this->assertStringContainsString('Phiên #'.$mine->id, $html);
    }
}
