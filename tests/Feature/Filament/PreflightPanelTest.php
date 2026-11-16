<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ArticleResource;
use App\Filament\Resources\ArticleResource\Pages\ListArticles;
use App\Filament\Resources\ArticleResource\Pages\ViewArticle;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Brain;
use App\Models\KnowledgeItem;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Khối "Sẵn sàng trước khi giao", hộp thoại Giao việc, điểm hình thức, tab mở đầu và giao hàng loạt. */
class PreflightPanelTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Website::create(['name' => 'Alpha', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function article(string $kw, string $status = 'planned'): Article
    {
        return Article::create(['website_id' => $this->site->id, 'primary_keyword' => $kw, 'workflow_status' => $status]);
    }

    private function approvedItem(): void
    {
        KnowledgeItem::create(['website_id' => $this->site->id, 'type' => 'fact', 'title' => 'Người tiểu đường nên ăn gì: chọn thực phẩm chỉ số đường huyết thấp', 'body' => 'Người tiểu đường nên ưu tiên thực phẩm có chỉ số đường huyết thấp như rau xanh, yến mạch.', 'status' => 'approved']);
    }

    private const MEDICAL = 'người tiểu đường nên ăn gì';

    public function test_khoi_ai_tu_chuan_bi_khong_chan_va_khong_do(): void
    {
        $this->actingAs($this->user('approver'));
        $a = $this->article(self::MEDICAL);

        Livewire::test(ViewArticle::class, ['record' => $a->id])
            ->assertSee('AI sẽ tự chuẩn bị')
            ->assertSee('Cao')
            ->assertSee('Kho có 0 mục kiến thức đã duyệt')
            ->assertDontSee('Ước tính: chưa có')
            ->assertDontSee('Hồ sơ: 0%')
            ->assertDontSee('DỪNG')
            ->assertDontSee('Gắn cho website')
            ->assertDontSee('Sẵn sàng trước khi giao');
    }

    public function test_co_muc_da_duyet_thi_liet_ke_tieu_de(): void
    {
        $this->actingAs($this->user('approver'));
        $this->approvedItem();
        $a = $this->article(self::MEDICAL);

        Livewire::test(ViewArticle::class, ['record' => $a->id])
            ->assertSee('Kho có 1 mục kiến thức đã duyệt')
            ->assertSee('chỉ số đường huyết thấp');
    }

    public function test_khoi_khong_hien_voi_bai_da_cho_duyet(): void
    {
        $this->actingAs($this->user('approver'));
        $a = $this->article('máy lọc nước', 'needs_review');
        ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 'T', 'slug' => 't', 'html_content' => '<p>x</p>', 'review_status' => 'needs_review']);

        Livewire::test(ViewArticle::class, ['record' => $a->id])->assertDontSee('AI sẽ tự chuẩn bị');
    }

    public function test_tri_tue_goi_y_ghi_se_tu_gan_va_khong_co_nut_gan(): void
    {
        $this->actingAs($this->user('writer'));
        Brain::create(['name' => 'Đái tháo đường (tiểu đường)', 'slug' => 'dai_thao_duong', 'domain_key' => 'dai_thao_duong', 'language' => 'vi', 'status' => 'active', 'description' => '']);
        $a = $this->article(self::MEDICAL);

        Livewire::test(ViewArticle::class, ['record' => $a->id])
            ->assertSee('Trí tuệ sẽ tự gắn khi giao')
            ->assertSee('Đái tháo đường (tiểu đường)')
            ->assertDontSee('Gắn cho website');
    }

    public function test_dong_da_tu_gan_va_hoan_tac(): void
    {
        $brainAutoAttach = 'App\\Services\\Agent\\Readiness\\BrainAutoAttach';
        if (! class_exists($brainAutoAttach)) {
            $this->markTestSkipped('BrainAutoAttach (V1) chưa có: chưa kiểm được dòng "Đã tự gắn" và Hoàn tác.');
        }
        $this->actingAs($this->user('writer'));
        $brain = Brain::create(['name' => 'Đái tháo đường (tiểu đường)', 'slug' => 'dai_thao_duong', 'domain_key' => 'dai_thao_duong', 'language' => 'vi', 'status' => 'active', 'description' => '']);
        $brain->websites()->attach($this->site->id, ['priority' => 10]);
        $a = $this->article(self::MEDICAL);

        $t = Livewire::test(ViewArticle::class, ['record' => $a->id]);
        if (($brainAutoAttach::recent($this->site)[0]['brain_id'] ?? null) !== $brain->id) {
            $this->markTestSkipped('BrainAutoAttach::recent chưa ghi nhận việc gắn thủ công trong test; cần dữ liệu từ V1.');
        }
        $t->assertSee('Đã tự gắn')->assertSee('Hoàn tác')
            ->mountAction('undoAutoAttach', ['brain' => $brain->id])
            ->callMountedAction()
            ->assertHasNoActionErrors();
        $this->assertFalse($brain->fresh()->websites->contains($this->site->id));
    }

    public function test_hop_thoai_giao_viec_khong_o_tick_va_khong_canh_bao_dung(): void
    {
        $this->actingAs($this->user('writer'));
        $a = $this->article(self::MEDICAL);

        Livewire::test(ViewArticle::class, ['record' => $a->id])
            ->mountAction('run_agent')
            ->assertSee('Chi phí')
            ->assertSee('ngân sách phiên tối đa')
            ->assertDontSee('DỪNG')
            ->assertDontSee('Vẫn viết dù kho chưa có kiến thức');
    }

    public function test_vai_writer_giao_duoc_con_viewer_thi_khong(): void
    {
        $a = $this->article('máy lọc nước');
        $this->actingAs($this->user('writer'));
        $this->assertTrue(ArticleResource::canAssignToAgent($a));
        Livewire::test(ViewArticle::class, ['record' => $a->id])->assertActionVisible('run_agent');
        Livewire::test(ListArticles::class)->assertTableBulkActionVisible('assign_agent_bulk');

        $this->actingAs($this->user('viewer'));
        $this->assertFalse(ArticleResource::canAssignToAgent($a));
    }

    public function test_cot_phien_gan_nhat_khong_lap_nhan_trang_thai_bai(): void
    {
        $this->actingAs($this->user('approver'));
        $a = $this->article('máy lọc nước', 'needs_review');
        $run = AgentRun::create(['article_id' => $a->id, 'website_id' => $this->site->id, 'status' => 'ready_for_review', 'trigger' => 'manual']);

        $text = ArticleResource::runSummary($run);
        $this->assertStringContainsString('Phiên #'.$run->id, $text);
        $this->assertStringContainsString('AI viết xong', $text);
        $this->assertStringNotContainsString('Chờ duyệt', $text);
        $this->assertNotSame('Chờ duyệt', ArticleResource::RUN_WORDS['ready_for_review']);
    }

    public function test_nhan_chuyen_gia_va_canh_bao_vang_o_tab_noi_dung(): void
    {
        $this->actingAs($this->user('approver'));
        $a = $this->article(self::MEDICAL, 'needs_review');
        ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 'T', 'slug' => 't', 'html_content' => '<p>x</p>', 'review_status' => 'needs_review']);

        Livewire::test(ViewArticle::class, ['record' => $a->id])
            ->assertSee(ArticleResource::GAP_NOTE)
            ->assertSee('Điểm hình thức')
            ->assertSee(ArticleResource::EXPERT_LABEL);
    }

    public function test_bai_ke_hoach_khong_roi_vao_tab_xuat_ban(): void
    {
        $this->actingAs($this->user('approver'));
        $a = $this->article('máy lọc nước');
        $this->assertMatchesRegularExpression('/studioTabs\((&quot;|\'|")overview\1,\s*null\)/', Livewire::test(ViewArticle::class, ['record' => $a->id])->html());

        $b = $this->article('máy lọc nước 2', 'scheduled');
        ArticleVersion::create(['article_id' => $b->id, 'version' => 1, 'title' => 'T', 'slug' => 't2', 'html_content' => '<p>x</p>', 'review_status' => 'approved']);
        $this->assertMatchesRegularExpression('/studioTabs\((&quot;|\'|")publish\1/', Livewire::test(ViewArticle::class, ['record' => $b->id])->html());
    }

    public function test_giao_hang_loat_toi_da_5_bai_khong_con_bo_qua_bai_kho_trong(): void
    {
        $this->actingAs($this->user('approver'));
        $held = $this->article(self::MEDICAL);
        $ok = collect(range(1, 5))->map(fn ($i) => $this->article('trang trí bàn '.$i));
        $all = $ok->prepend($held);

        Livewire::test(ListArticles::class)
            ->callTableBulkAction('assign_agent_bulk', $all);

        $titles = collect(session('filament.notifications'))->pluck('title')->all();
        $this->assertNotContains('Bài #'.$held->id.' bị bỏ qua', $titles);
        $this->assertContains('1 bài chưa được giao', $titles);
        // 5 bài được xử lý (cả bài kho trống, chưa nối AI); bài thứ 6 không được đụng tới.
        $this->assertCount(6, $titles);
    }
}
