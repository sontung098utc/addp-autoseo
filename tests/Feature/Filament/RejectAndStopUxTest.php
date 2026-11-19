<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ArticleResource;
use App\Filament\Resources\ArticleResource\Pages\ListArticles;
use App\Filament\Resources\ArticleResource\Pages\ViewArticle;
use App\Filament\Resources\PublicationResource\Pages\ListPublications;
use App\Filament\Support\Agent\RunActivity;
use App\Filament\Support\Review\ArticleRows;
use App\Filament\Support\Review\ReviewWorkflow;
use App\Filament\Support\Review\StopGate;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Unit\QualityFixtures;

/** #56-#59 (test server 19/11): từ chối bản thảo, hiển thị phiên bị chặn, khóa nút khi dừng khẩn cấp. */
class RejectAndStopUxTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Website::create(['name' => 'Alpha', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        app(EmergencyStopService::class)->resetForTesting();
    }

    protected function tearDown(): void
    {
        app(EmergencyStopService::class)->resetForTesting();
        parent::tearDown();
    }

    private function user(string $role): User
    {
        return User::create(['name' => 'Người '.$role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function article(string $ws, int $versions = 1, string $vs = 'needs_review'): Article
    {
        $a = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'học bổng toàn phần', 'workflow_status' => $ws]);
        for ($i = 1; $i <= $versions; $i++) {
            QualityFixtures::version($a, $i, $i === $versions ? $vs : 'rejected');
        }

        return $a;
    }

    private function rejectedArticle(): Article
    {
        $a = $this->article('rejected', 1, 'rejected');
        $a->versions()->first()->update(['review_notes' => 'Thiếu số liệu mục 2', 'reviewed_by' => $this->user('approver')->id, 'reviewed_at' => now()]);

        return $a;
    }

    // ---- #57 ----

    public function test_tu_choi_giu_nguyen_enum_rejected_nhung_nhan_noi_ro_ban_va_ly_do(): void
    {
        $a = $this->article('needs_review');
        $approver = $this->user('approver');
        $this->actingAs($approver);
        Livewire::test(ViewArticle::class, ['record' => $a->getRouteKey()])
            ->callAction('reject', ['reason' => 'Thiếu số liệu mục 2'])->assertHasNoActionErrors();

        $this->assertSame('rejected', $a->fresh()->workflow_status); // không đổi enum
        [$label] = ArticleRows::statusBadge($a->fresh(), false);
        $this->assertSame('Cần sửa (đã từ chối bản v1)', $label);

        Livewire::test(ViewArticle::class, ['record' => $a->getRouteKey()])
            ->assertSeeHtml('data-testid="aw-rejection"')->assertSeeHtml('data-testid="aw-rejection-content"')
            ->assertSee('Thiếu số liệu mục 2')->assertSee('Người approver');
    }

    public function test_gui_duyet_tren_ban_vua_bi_tu_choi_phai_xac_nhan_nguyen_ban(): void
    {
        $a = $this->rejectedArticle();
        $writer = $this->user('writer');
        $v = $a->versions()->first();

        // Máy chủ: không xác nhận thì chặn, nêu lý do.
        try {
            (new ReviewWorkflow)->submitForReview($writer, $v);
            $this->fail('Phải chặn');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Bản này đã bị từ chối vì: Thiếu số liệu mục 2', $e->getMessage());
        }
        $this->assertSame('rejected', $a->fresh()->workflow_status);

        // Giao diện: nút đổi nhãn, hộp xác nhận nêu rõ lý do; bấm xác nhận = chủ động gửi lại.
        $this->actingAs($writer);
        $t = Livewire::test(ViewArticle::class, ['record' => $a->getRouteKey()]);
        $t->mountAction('submit_review')->assertSee('Bản này đã bị từ chối vì: Thiếu số liệu mục 2');
        $t->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame('needs_review', $a->fresh()->workflow_status);
    }

    public function test_co_ban_moi_hon_thi_gui_duyet_binh_thuong_khong_can_xac_nhan(): void
    {
        $a = $this->article('rejected', 2, 'needs_review'); // v1 bị từ chối, v2 là bản mới
        (new ReviewWorkflow)->submitForReview($this->user('writer'), $a->versions()->where('version', 2)->first());
        $this->assertSame('needs_review', $a->fresh()->workflow_status);
        $this->assertNull(\App\Filament\Support\Review\RejectionInfo::unrevised($a->fresh()));
    }

    public function test_o_ly_do_dung_kiem_tra_cua_filament_khong_dung_required_cua_trinh_duyet(): void
    {
        $a = $this->article('needs_review');
        $this->actingAs($this->user('approver'));
        $t = Livewire::test(ViewArticle::class, ['record' => $a->getRouteKey()]);
        $t->mountAction('reject');
        $this->assertStringContainsString('<textarea', $t->html());
        $this->assertDoesNotMatchRegularExpression('/<textarea[^>]*\srequired[\s=>]/', $t->html()); // không có thuộc tính required của trình duyệt
        $t->callMountedAction()->assertHasActionErrors(['reason' => 'required']);
        $this->assertSame('needs_review', $a->fresh()->workflow_status);

        $this->actingAs($this->user('approver'));
        $p = Livewire::test(ListPublications::class)->mountAction('pause');
        $p->callMountedAction()->assertHasActionErrors(['reason' => 'required']);
        $this->assertFalse(app(EmergencyStopService::class)->isStopped());
    }

    // ---- #58 ----

    public function test_phien_bi_chan_truoc_khi_chay_khong_che_phien_that_gan_nhat(): void
    {
        $a = $this->article('needs_review');
        $real = AgentRun::create(['website_id' => $this->site->id, 'article_id' => $a->id, 'trigger' => 'manual', 'status' => 'ready_for_review', 'started_at' => now()->subHours(2), 'finished_at' => now()->subHours(2), 'cost_vnd' => 1200, 'tokens_used' => 5000]);
        $blocked = AgentRun::create(['website_id' => $this->site->id, 'article_id' => $a->id, 'trigger' => 'manual', 'status' => 'blocked', 'started_at' => now(), 'finished_at' => now(), 'outcome_reason' => 'Đang dừng khẩn cấp.', 'cost_vnd' => 0, 'tokens_used' => 0]);
        $this->actingAs($this->user('approver'));

        $this->assertTrue(RunActivity::isGateBlocked($blocked));
        $this->assertFalse(RunActivity::isGateBlocked($real));
        $this->assertStringStartsWith('Lần giao gần nhất bị chặn: Đang dừng khẩn cấp (', (string) RunActivity::blockedNote($blocked));

        $t = Livewire::test(ViewArticle::class, ['record' => $a->getRouteKey()]);
        $t->assertSeeHtml('data-testid="aw-flow" data-run="'.$real->id.'"')->assertDontSeeHtml('data-testid="aw-flow" data-run="'.$blocked->id.'"');
        $t->assertSeeHtml('data-testid="aw-flow-blocked"')->assertSee('Lần giao gần nhất bị chặn: Đang dừng khẩn cấp');
        $t->assertSee('#'.$blocked->id); // danh sách "Các phiên chạy" vẫn liệt kê đủ
    }

    public function test_chi_co_phien_bi_chan_thi_van_hien_phien_do(): void
    {
        $a = $this->article('planned');
        $blocked = AgentRun::create(['website_id' => $this->site->id, 'article_id' => $a->id, 'trigger' => 'manual', 'status' => 'blocked', 'started_at' => now(), 'finished_at' => now(), 'outcome_reason' => 'Đang dừng khẩn cấp.']);
        $this->actingAs($this->user('approver'));
        Livewire::test(ViewArticle::class, ['record' => $a->getRouteKey()])
            ->assertSeeHtml('data-testid="aw-flow" data-run="'.$blocked->id.'"')->assertDontSeeHtml('data-testid="aw-flow-blocked"');
    }

    // ---- #56 ----

    public function test_dung_khan_cap_khoa_cac_nut_giao_dang_xep_lich_o_trang_bai(): void
    {
        app(EmergencyStopService::class)->stop($this->user('approver'), 'Sự cố CMS');
        $approver = $this->user('approver');
        $this->actingAs($approver);

        $planned = $this->article('planned');
        $t = Livewire::test(ViewArticle::class, ['record' => $planned->getRouteKey()])->assertActionDisabled('run_agent')->assertSee(StopGate::MESSAGE);

        $review = $this->article('needs_review');
        Livewire::test(ViewArticle::class, ['record' => $review->getRouteKey()])->assertActionDisabled('redo_agent');

        $approved = $this->article('approved', 1, 'approved');
        Livewire::test(ViewArticle::class, ['record' => $approved->getRouteKey()])->assertActionDisabled('schedule')->assertActionDisabled('publish_now');

        Livewire::test(ListArticles::class)->assertTableActionDisabled('run_agent', $planned)->assertTableActionDisabled('schedule', $approved)->assertTableActionDisabled('publish_now', $approved);
    }

    public function test_goi_thang_khi_dung_khan_cap_van_bi_chan_o_may_chu_va_khong_tao_phien_rac(): void
    {
        app(EmergencyStopService::class)->stop($this->user('approver'), 'Sự cố CMS');
        $this->actingAs($this->user('approver'));

        $planned = $this->article('planned');
        $this->assertNull(ArticleResource::assignToAgent($planned, background: true));
        $this->assertNull(ArticleResource::assignToAgent($planned, background: true, redoMessage: 'làm lại mục 2 cho đầy đủ'));
        $this->assertSame(0, AgentRun::where('article_id', $planned->id)->count());

        $approved = $this->article('approved', 1, 'approved');
        $at = now('Asia/Ho_Chi_Minh')->addDay()->format('Y-m-d H:i:s');
        Livewire::test(ViewArticle::class, ['record' => $approved->getRouteKey()])->callAction('schedule', ['website_id' => $this->site->id, 'scheduled_at_vn' => $at]);
        Livewire::test(ViewArticle::class, ['record' => $approved->getRouteKey()])->callAction('publish_now', ['website_id' => $this->site->id]);
        $this->assertSame(0, Publication::count());
        $this->assertSame('approved', $approved->fresh()->workflow_status);
    }

    public function test_cong_may_chu_cua_orchestrator_van_chan_luong_tu_dong(): void
    {
        // Lối tự động (chiến dịch) không đi qua StopGate: orchestrator vẫn kiểm cổng như cũ và ghi phiên "blocked".
        $src = file_get_contents(app_path('Services/Agent/Run/AgentRunOrchestrator.php'));
        $this->assertStringContainsString("\$this->stop->isStopped(\$article->website_id)", $src);
        $this->assertStringNotContainsString('StopGate', $src);
    }

    public function test_khoi_phuc_tu_cap_nhat_trang_va_nut_mo_lai(): void
    {
        app(EmergencyStopService::class)->stop($this->user('approver'), 'Sự cố CMS');
        $this->actingAs($this->user('approver'));
        Livewire::test(ListPublications::class)->callAction('resume')->assertRedirect(\App\Filament\Resources\PublicationResource::getUrl('index'));
        $this->assertFalse(app(EmergencyStopService::class)->isStopped());

        $planned = $this->article('planned');
        Livewire::test(ViewArticle::class, ['record' => $planned->getRouteKey()])->assertActionEnabled('run_agent');
    }
}
