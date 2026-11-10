<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ArticleResource;
use App\Filament\Resources\ArticleResource\Pages\ListArticles;
use App\Filament\Resources\ArticleResource\Pages\ViewArticle;
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

    public function test_bai_y_te_kho_trong_hien_canh_bao_do(): void
    {
        $this->actingAs($this->user('approver'));
        $a = $this->article(self::MEDICAL);

        Livewire::test(ViewArticle::class, ['record' => $a->id])
            ->assertSee('Sẵn sàng trước khi giao')
            ->assertSee('Cao')
            ->assertSee('Kho có 0 mục kiến thức đã duyệt')
            ->assertSee(ArticleResource::HOLD_WARNING)
            ->assertSee('Chưa có số liệu để ước lượng')
            ->assertSee('Hồ sơ doanh nghiệp');
    }

    public function test_co_muc_da_duyet_thi_khong_canh_bao_va_liet_ke_tieu_de(): void
    {
        $this->actingAs($this->user('approver'));
        $this->approvedItem();
        $a = $this->article(self::MEDICAL);

        Livewire::test(ViewArticle::class, ['record' => $a->id])
            ->assertSee('Kho có 1 mục kiến thức đã duyệt')
            ->assertSee('chỉ số đường huyết thấp')
            ->assertDontSee(ArticleResource::HOLD_WARNING);
    }

    public function test_khoi_khong_hien_voi_bai_da_cho_duyet(): void
    {
        $this->actingAs($this->user('approver'));
        $a = $this->article('máy lọc nước', 'needs_review');
        ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 'T', 'slug' => 't', 'html_content' => '<p>x</p>', 'review_status' => 'needs_review']);

        Livewire::test(ViewArticle::class, ['record' => $a->id])->assertDontSee('Sẵn sàng trước khi giao');
    }

    public function test_goi_y_tri_tue_va_bam_gan_thi_website_co_tri_tue(): void
    {
        $this->actingAs($this->user('approver'));
        $brain = Brain::create(['name' => 'Đái tháo đường (tiểu đường)', 'slug' => 'dai_thao_duong', 'domain_key' => 'dai_thao_duong', 'language' => 'vi', 'status' => 'active', 'description' => '']);
        $a = $this->article(self::MEDICAL);

        Livewire::test(ViewArticle::class, ['record' => $a->id])
            ->assertSee('Đái tháo đường (tiểu đường)')
            ->assertSee('Gắn cho website')
            ->mountAction('attachBrain', ['brain' => $brain->id])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertSee('Trí tuệ đang gắn');

        $this->assertTrue($brain->fresh()->websites->contains($this->site->id));
    }

    public function test_bien_tap_vien_khong_gan_duoc_tri_tue(): void
    {
        $this->actingAs($this->user('writer'));
        $brain = Brain::create(['name' => 'Đái tháo đường (tiểu đường)', 'slug' => 'dai_thao_duong', 'domain_key' => 'dai_thao_duong', 'language' => 'vi', 'status' => 'active', 'description' => '']);
        $a = $this->article(self::MEDICAL);

        Livewire::test(ViewArticle::class, ['record' => $a->id])->assertDontSee('Gắn cho website');
        $this->assertFalse($brain->fresh()->websites->contains($this->site->id));
    }

    public function test_hop_thoai_giao_viec_co_chi_phi_va_o_tick_khi_bi_dung(): void
    {
        $this->actingAs($this->user('approver'));
        $a = $this->article(self::MEDICAL);

        Livewire::test(ViewArticle::class, ['record' => $a->id])
            ->mountAction('run_agent')
            ->assertSee('Chi phí')
            ->assertSee('ngân sách phiên tối đa')
            ->assertSee(ArticleResource::HOLD_WARNING)
            ->assertSee('Vẫn viết dù kho chưa có kiến thức');

        $b = $this->article('cách trang trí bàn làm việc');
        Livewire::test(ViewArticle::class, ['record' => $b->id])
            ->mountAction('run_agent')
            ->assertSee('Chi phí')
            ->assertDontSee(ArticleResource::HOLD_WARNING)
            ->assertDontSee('Vẫn viết dù kho chưa có kiến thức');
    }

    public function test_diem_hinh_thuc_co_chu_thich_va_canh_bao_chua_co_can_cu(): void
    {
        $this->actingAs($this->user('approver'));
        $a = $this->article(self::MEDICAL, 'needs_review');
        ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 'T', 'slug' => 't', 'html_content' => '<p>x</p>', 'review_status' => 'needs_review']);

        Livewire::test(ViewArticle::class, ['record' => $a->id])
            ->assertSee('Điểm hình thức')
            ->assertSee(ArticleResource::SCORE_NOTE)
            ->assertSee(ArticleResource::GAP_NOTE);
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

    public function test_giao_hang_loat_toi_da_5_bai_va_bo_qua_bai_kho_trong(): void
    {
        $this->actingAs($this->user('approver'));
        $held = $this->article(self::MEDICAL);
        $ok = collect(range(1, 5))->map(fn ($i) => $this->article('trang trí bàn '.$i));
        $all = $ok->prepend($held);

        Livewire::test(ListArticles::class)
            ->callTableBulkAction('assign_agent_bulk', $all, data: ['allow_without_knowledge' => false]);

        $titles = collect(session('filament.notifications'))->pluck('title')->all();
        $this->assertContains('Bài #'.$held->id.' bị bỏ qua', $titles);
        $this->assertContains('1 bài chưa được giao', $titles);
        // 5 bài được xử lý (1 bỏ qua + 4 giao, chưa nối AI); bài thứ 6 không được đụng tới.
        $this->assertCount(6, $titles);
    }

    public function test_giao_hang_loat_chi_danh_cho_nguoi_duyet(): void
    {
        $this->actingAs($this->user('writer'));
        $a = $this->article('máy lọc nước');

        Livewire::test(ListArticles::class)->assertTableBulkActionHidden('assign_agent_bulk');
    }
}
