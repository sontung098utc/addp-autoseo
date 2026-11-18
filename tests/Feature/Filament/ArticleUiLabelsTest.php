<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ArticleResource;
use App\Filament\Resources\ArticleResource\Pages\ListArticles;
use App\Filament\Resources\ArticleResource\Pages\ViewArticle;
use App\Filament\Support\Review\ReviewActions;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Giao diện Bài viết: số đếm tab, nhãn lỗi dễ hiểu, hộp Duyệt, tab mặc định, nút Xóa. */
class ArticleUiLabelsTest extends TestCase
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

    private function article(string $status, string $kw = 'kw'): Article
    {
        $a = Article::create(['website_id' => $this->site->id, 'primary_keyword' => $kw.uniqid(), 'workflow_status' => $status]);
        ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 'Tiêu đề '.$a->primary_keyword, 'slug' => 's'.$a->id, 'html_content' => '<p>x</p>', 'review_status' => 'needs_review']);

        return $a;
    }

    private function publication(Article $a, string $status): Publication
    {
        return Publication::create(['website_id' => $this->site->id, 'article_version_id' => $a->versions()->first()->id, 'publication_key' => 'k-'.uniqid(), 'scheduled_at_utc' => now()->subHour(), 'timezone' => 'Asia/Ho_Chi_Minh', 'status' => $status]);
    }

    public function test_tab_da_len_lich_khong_tinh_bai_da_dang_va_tab_da_dang_co_so(): void
    {
        $this->actingAs($this->user('admin'));
        $queued = $this->article('scheduled');
        $this->publication($queued, 'scheduled');
        $done = $this->article('scheduled'); // đường đăng thật chỉ đổi lượt đăng, bài vẫn ghi 'scheduled'
        $this->publication($done, 'published');
        $legacy = $this->article('published');
        $this->article('planned');

        $counts = ArticleResource::tabCounts();
        $this->assertSame(4, $counts['all']);
        $this->assertSame(1, $counts['scheduled']);
        $this->assertSame(2, $counts['published']);
        $this->assertSame(1, $counts['planned']);

        $t = Livewire::test(ListArticles::class);
        $tabs = $t->instance()->getTabs();
        $this->assertSame(1, $tabs['scheduled']->getBadge());
        $this->assertSame(2, $tabs['published']->getBadge());
        $this->assertSame('Chờ viết', $tabs['planned']->getLabel());
        $t->set('activeTab', 'scheduled')->assertCanSeeTableRecords([$queued])->assertCanNotSeeTableRecords([$done, $legacy]);
        $t->set('activeTab', 'published')->assertCanSeeTableRecords([$done, $legacy])->assertCanNotSeeTableRecords([$queued]);
    }

    public function test_tong_cac_tab_trang_thai_bang_tab_tat_ca(): void
    {
        $this->actingAs($this->user('admin'));
        foreach (['planned', 'drafting', 'needs_review', 'rejected', 'approved', 'scheduled', 'published'] as $s) {
            $this->article($s);
        }
        $this->publication($this->article('scheduled'), 'published');

        $c = ArticleResource::tabCounts();
        $this->assertSame($c['all'], array_sum(array_intersect_key($c, array_flip(ArticleResource::TAB_STATUSES))));
    }

    public function test_nhan_loi_de_hieu_thay_cho_p0_p1_p2(): void
    {
        $this->assertSame('Lỗi nặng 0 · Cần sửa 1 · Gợi ý 0', ArticleResource::severityText(['P0' => 0, 'P1' => 1, 'P2' => 0]));
        $this->assertSame('Lỗi nặng 0 · Cần sửa 0 · Gợi ý 0', ArticleResource::severityText([]));
    }

    public function test_hop_duyet_neu_ten_bai_rui_ro_va_so_loi_va_giu_chinh_sach_ghi_chu(): void
    {
        $a = $this->article('needs_review', 'tieu-duong');
        AgentRun::create(['website_id' => $this->site->id, 'article_id' => $a->id, 'triggered_by' => null, 'trigger' => 'manual', 'status' => 'ready_for_review', 'risk_level' => 'high', 'started_at' => now()]);
        $summary = ArticleResource::approveSummary($a->fresh());
        $this->assertSame('Cao', $summary['risk']);
        $this->assertStringContainsString('Tiêu đề tieu-duong', $summary['title']);
        $this->assertIsArray($summary['counts']);

        $html = (string) ReviewActions::summaryHtml($summary);
        $this->assertStringContainsString('Rủi ro chủ đề: Cao', $html);
        $this->assertStringContainsString('Lỗi nặng', $html);
        $this->assertStringNotContainsString('P0', $html);

        // Ghi chú chỉ bắt buộc khi người duyệt là người tạo bài (chính sách tự duyệt không đổi).
        $self = collect(ReviewActions::approveForm(true, null, $summary))->first(fn ($f) => $f->getName() === 'note');
        $other = collect(ReviewActions::approveForm(false, null, $summary))->first(fn ($f) => $f->getName() === 'note');
        $this->assertTrue($self->isRequired());
        $this->assertFalse($other->isRequired());
        $this->assertSame('approve_summary', ReviewActions::approveForm(false, null, $summary)[0]->getName());
    }

    public function test_menu_dong_co_xem_bai_va_nut_duyet_ten_duyet(): void
    {
        $this->actingAs($this->user('admin'));
        $a = $this->article('needs_review');
        Livewire::test(ListArticles::class)->set('activeTab', 'needs_review')
            ->assertTableActionExists('open_article', null, $a)
            ->assertTableActionHasUrl('open_article', ArticleResource::getUrl('view', ['record' => $a]), $a);
    }

    public function test_bai_cho_duyet_khong_nho_tab_cu_va_xoa_nam_trong_menu(): void
    {
        $this->actingAs($this->user('admin'));
        $a = $this->article('needs_review');
        $html = Livewire::test(ViewArticle::class, ['record' => $a->id])->html();
        $this->assertMatchesRegularExpression('/studioTabs\([\'"]content[\'"],\s*null\)/', html_entity_decode($html));

        $b = $this->article('approved');
        $html = html_entity_decode(Livewire::test(ViewArticle::class, ['record' => $b->id])->html());
        $this->assertStringContainsString('bai-viet-v4-'.$b->id, $html);
        $this->assertStringContainsString('Lỗi chưa đạt', html_entity_decode(Livewire::test(ViewArticle::class, ['record' => $a->id])->html()));
    }
}
