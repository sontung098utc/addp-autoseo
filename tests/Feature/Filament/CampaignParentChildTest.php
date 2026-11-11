<?php

namespace Tests\Feature\Filament;

use App\Filament\Clusters\AgentTeam;
use App\Filament\Pages\ContentPlanPage;
use App\Filament\Resources\ArticleResource\Pages\CreateArticle;
use App\Filament\Resources\ArticleResource\Pages\ListArticles;
use App\Filament\Resources\CampaignResource\Pages\CreateCampaign;
use App\Filament\Resources\CampaignResource\Pages\ViewCampaign;
use App\Models\Article;
use App\Models\Campaign;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\KeywordGap;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Run\CampaignRunner;
use App\Services\Planning\ContentPlanner;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Chiến dịch là cha, bài là con (hoặc bài lẻ); kế hoạch nằm trong chiến dịch; đề xuất hiện nguồn. */
class CampaignParentChildTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    private Campaign $camp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Website::create(['name' => 'Alpha', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->camp = Campaign::create(['website_id' => $this->site->id, 'name' => 'Chiến dịch Một', 'status' => 'active', 'keywords' => [], 'daily_limit' => 3]);
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function kw(string $k, ?int $vol = null, ?KeywordCluster $c = null, string $source = 'tay'): Keyword
    {
        return Keyword::create(['website_id' => $this->site->id, 'keyword' => $k, 'keyword_key' => Keyword::keyFor($k), 'volume' => $vol, 'cluster_id' => $c?->id, 'status' => 'new', 'source' => $source]);
    }

    public function test_them_de_xuat_vao_chien_dich_gan_campaign_id(): void
    {
        $k = $this->kw('du học úc', 300);
        $r = (new ContentPlanner)->create($this->user('writer'), $this->site, [$k->id], $this->camp->id);
        $this->assertSame(1, $r['created']);
        $this->assertSame($this->camp->id, Article::where('keyword_id', $k->id)->value('campaign_id'));
    }

    public function test_bai_le_de_trong_campaign_id_va_chien_dich_website_khac_bi_chan(): void
    {
        $k = $this->kw('học bổng', 100);
        (new ContentPlanner)->create($this->user('writer'), $this->site, [$k->id], null);
        $this->assertNull(Article::where('keyword_id', $k->id)->value('campaign_id'));

        $other = Website::create(['name' => 'B', 'cms_type' => 'wordpress', 'base_url' => 'https://b.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $oc = Campaign::create(['website_id' => $other->id, 'name' => 'Khác', 'status' => 'paused', 'keywords' => [], 'daily_limit' => 1]);
        $k2 = $this->kw('xin visa', 50);
        $this->expectException(\InvalidArgumentException::class);
        (new ContentPlanner)->create($this->user('writer'), $this->site, [$k2->id], $oc->id);
    }

    public function test_trang_ke_hoach_tat_ca_phai_chon_noi_nhan_va_gan_theo_lua_chon(): void
    {
        $k = $this->kw('du học nhật', 200);
        Livewire::actingAs($this->user('writer'))->test(ContentPlanPage::class)
            ->set('planPick', [$k->id])->call('addProposed');
        $this->assertSame(0, Article::count(), 'Chưa chọn nơi nhận thì không tạo bài');

        Livewire::actingAs($this->user('writer'))->test(ContentPlanPage::class)
            ->set('planPick', [$k->id])->set('planTarget', (string) $this->camp->id)->call('addProposed');
        $this->assertSame($this->camp->id, Article::first()->campaign_id);
    }

    public function test_trang_ke_hoach_loc_theo_chien_dich_thi_gan_dung_chien_dich(): void
    {
        $k = $this->kw('du học đức', 200);
        Livewire::actingAs($this->user('writer'))->test(ContentPlanPage::class)
            ->set('campaignId', (string) $this->camp->id)->set('planPick', [$k->id])->call('addProposed');
        $this->assertSame($this->camp->id, Article::first()->campaign_id);

        $k2 = $this->kw('du học pháp', 200);
        Livewire::actingAs($this->user('writer'))->test(ContentPlanPage::class)
            ->set('campaignId', 'solo')->set('planPick', [$k2->id])->call('addProposed');
        $this->assertNull(Article::where('keyword_id', $k2->id)->value('campaign_id'));
    }

    public function test_moi_de_xuat_co_chip_nguon_dung(): void
    {
        $c = KeywordCluster::create(['website_id' => $this->site->id, 'name' => 'Tiểu đường', 'intent' => 'informational']);
        $this->kw('đường huyết', 1900, $c);
        $this->kw('đối thủ có', null, null, 'competitor');
        KeywordGap::create(['website_id' => $this->site->id, 'keyword' => 'gap kw', 'priority_score' => 60, 'status' => 'moi']);
        $this->kw('gap kw', 10);

        $rows = collect((new ContentPlanner)->propose($this->site))->keyBy('keyword');
        $this->assertSame('keyword', $rows['đường huyết']['sources'][0]['type']);
        $this->assertSame('Từ khóa kho · 1.900 lượt/tháng · cụm Tiểu đường', $rows['đường huyết']['sources'][0]['label']);
        $this->assertContains('competitor', array_column($rows['đối thủ có']['sources'], 'type'));
        $this->assertContains('competitor', array_column($rows['gap kw']['sources'], 'type'));
        $this->assertNotContains('competitor', array_column($rows['đường huyết']['sources'], 'type'));

        Livewire::actingAs($this->user('writer'))->test(ContentPlanPage::class)
            ->assertSee('Từ khóa kho · 1.900 lượt/tháng · cụm Tiểu đường')->assertSee('Khoảng trống đối thủ')
            ->assertDontSee('Xếp theo luật, không dùng AI', escape: false)->assertSeeHtml('data-source="keyword"');
    }

    public function test_menu_khong_con_muc_ke_hoach_rieng_va_tab_nam_trong_cum(): void
    {
        $this->assertSame(AgentTeam::class, ContentPlanPage::getCluster());
        $this->actingAs($this->user('admin'));
        Filament::setCurrentPanel(Filament::getDefaultPanel());
        $topLabels = collect(Filament::getNavigation())->flatMap(fn ($g) => $g->getItems())->map(fn ($i) => $i->getLabel())->all();
        $this->assertNotContains('Kế hoạch nội dung', $topLabels);
        $this->assertNotContains('Kế hoạch', $topLabels);
        $tabs = collect(Filament::getPages()) // trang trong cụm
            ->filter(fn ($p) => $p === ContentPlanPage::class);
        $this->assertCount(1, $tabs);
        $this->get('/admin/doi-ai/ke-hoach')->assertOk()->assertSee('Chiến dịch')->assertSee('Kế hoạch');
    }

    public function test_url_cu_chuyen_huong_va_bien_tap_vien_van_vao_tab_ke_hoach(): void
    {
        $this->get('/admin/ke-hoach-noi-dung')->assertRedirect('/admin/doi-ai/ke-hoach');
        $this->actingAs($this->user('writer'))->get('/admin/doi-ai/ke-hoach')->assertOk();
        $this->assertNotSame(200, $this->actingAs($this->user('writer'))->get('/admin/doi-ai/chien-dich')->status());
    }

    public function test_tao_bai_tu_chien_dich_gan_campaign_id_va_bai_le_de_trong(): void
    {
        $admin = $this->user('admin');
        Livewire::actingAs($admin)->test(ViewCampaign::class, ['record' => $this->camp->id])
            ->callAction('addArticle', ['keyword' => 'từ khóa con', 'intent' => 'informational'])
            ->assertHasNoActionErrors();
        $a = Article::where('primary_keyword', 'từ khóa con')->first();
        $this->assertSame($this->camp->id, $a->campaign_id);
        $this->assertSame('planned', $a->workflow_status);

        Livewire::actingAs($admin)->test(CreateArticle::class)
            ->fillForm(['website_id' => $this->site->id, 'primary_keyword' => 'bài lẻ', 'search_intent' => 'informational'])
            ->call('create')->assertHasNoFormErrors();
        $this->assertNull(Article::where('primary_keyword', 'bài lẻ')->value('campaign_id'));

        Livewire::actingAs($admin)->test(CreateArticle::class)
            ->fillForm(['website_id' => $this->site->id, 'primary_keyword' => 'bài trong cd', 'search_intent' => 'informational', 'campaign_id' => $this->camp->id])
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame($this->camp->id, Article::where('primary_keyword', 'bài trong cd')->value('campaign_id'));
    }

    public function test_danh_sach_bai_co_cot_va_loc_chien_dich(): void
    {
        $in = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'trong cd', 'workflow_status' => 'planned', 'campaign_id' => $this->camp->id]);
        $solo = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'ngoài cd', 'workflow_status' => 'planned']);
        $t = Livewire::actingAs($this->user('admin'))->test(ListArticles::class)
            ->assertSee('Tạo bài lẻ')->assertSee('Chiến dịch Một')->assertSee('Bài lẻ');
        $t->filterTable('campaign_id', (string) $this->camp->id)->assertCanSeeTableRecords([$in])->assertCanNotSeeTableRecords([$solo]);
        $t->filterTable('campaign_id', 'solo')->assertCanSeeTableRecords([$solo])->assertCanNotSeeTableRecords([$in]);
    }

    public function test_trang_chien_dich_cho_thay_ngay_trang_thai_che_do_tien_do(): void
    {
        Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'a', 'workflow_status' => 'planned', 'campaign_id' => $this->camp->id]);
        Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'b', 'workflow_status' => 'needs_review', 'campaign_id' => $this->camp->id]);
        Livewire::actingAs($this->user('admin'))->test(ViewCampaign::class, ['record' => $this->camp->id])
            ->assertSee('Đang chạy')->assertSee('Duyệt trước · cân bằng')->assertSee('Kế hoạch')->assertSee('Chờ duyệt')
            ->assertSee('Chạy một nhịp')->assertSee('Thêm bài')->assertSee('Bài viết');
    }

    public function test_form_chien_dich_luu_checkpoints(): void
    {
        Livewire::actingAs($this->user('admin'))->test(CreateCampaign::class)
            ->fillForm(['name' => 'CD mới', 'website_id' => $this->site->id, 'keywords' => ['x'], 'daily_limit' => 3, 'status' => 'paused', 'mode' => 'balanced', 'source' => 'keywords',
                'daily_cost_cap_vnd' => 1000, 'daily_draft_cap' => 2, 'checkpoints' => ['plan', 'publish']])
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame(['plan', 'publish'], Campaign::where('name', 'CD mới')->first()->checkpoints);
    }

    public function test_bai_cua_chien_dich_khac_khong_bi_chien_dich_nay_lay(): void
    {
        $other = Campaign::create(['website_id' => $this->site->id, 'name' => 'Khác', 'status' => 'active', 'keywords' => [], 'daily_limit' => 3]);
        Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'của khác', 'workflow_status' => 'planned', 'campaign_id' => $other->id]);
        $mine = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'của mình', 'workflow_status' => 'planned', 'campaign_id' => $this->camp->id]);
        $this->assertSame($mine->id, (new CampaignRunner(fn () => null))->nextPlanned($this->camp)?->id);
    }
}
