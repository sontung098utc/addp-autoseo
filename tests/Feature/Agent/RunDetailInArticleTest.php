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
        $this->assertMatchesRegularExpression('/aw-run-detail__title">.*?Đang xem<\/span> Phiên #'.$old->id.' /s', $sel);
        $this->assertSame(1, substr_count($sel, 'data-testid="aw-run-viewing"'), 'đúng MỘT phiên được đánh dấu Đang xem trong bảng chọn phiên');
        $this->assertSame(1, substr_count($sel, 'data-testid="aw-run-open"'), 'phiên còn lại có nút Xem phiên này');
        $this->assertStringContainsString('Chi tiết của phiên đang xem', $sel);
        $this->assertStringContainsString('Bước của phiên cũ', $sel);
        // Ba phần của phiên (Báo cáo và Dữ liệu đã gộp một tab), không còn nút mở bài/xem phiên đã tạo trong báo cáo.
        foreach (['Diễn biến', 'Báo cáo và dữ liệu', 'Chi phí và model'] as $label) {
            $this->assertStringContainsString($label, $sel);
        }
        $detail = substr($sel, strpos($sel, 'data-testid="aw-run-detail"'));
        $this->assertStringNotContainsString('data-key="du-lieu"', $detail);
        foreach (['xem bài', 'Xem bản nháp', 'Mở bài viết', 'Xem phiên đã tạo'] as $gone) {
            $this->assertStringNotContainsString($gone, $detail, $gone);
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
