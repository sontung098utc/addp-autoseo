<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ArticleResource;
use App\Filament\Resources\ArticleResource\Pages\ListArticles;
use App\Filament\Resources\ArticleVersionResource;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Campaign;
use App\Models\User;
use App\Models\Website;
use Filament\Tables\Enums\ActionsPosition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** AG-S16: màn Bài viết gộp - tab, badge, nút chính, hiệu ứng đang chạy, liên kết cũ. */
class ArticlesMergedScreenTest extends TestCase
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

    private function article(string $status, ?string $kw = null, bool $withVersion = true): Article
    {
        $a = Article::create(['website_id' => $this->site->id, 'primary_keyword' => $kw ?? 'kw'.uniqid(), 'workflow_status' => $status]);
        if ($withVersion) {
            ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 'Tiêu đề '.$a->primary_keyword, 'slug' => 's'.$a->id, 'html_content' => '<p>x</p>', 'review_status' => in_array($status, ['approved', 'rejected', 'needs_review'], true) ? $status : 'needs_review']);
        }

        return $a;
    }

    private function mkRun(Article $a, string $status, array $extra = []): AgentRun
    {
        return AgentRun::create($extra + ['website_id' => $this->site->id, 'article_id' => $a->id, 'triggered_by' => null, 'trigger' => 'manual', 'status' => $status, 'started_at' => now()]);
    }

    public function test_tab_va_badge_dem_dung_va_loc_theo_trang_thai(): void
    {
        $this->actingAs($this->user('writer'));
        $p = $this->article('planned');
        $r1 = $this->article('needs_review');
        $r2 = $this->article('needs_review');
        $pub = $this->article('published');

        $t = Livewire::test(ListArticles::class);
        $tabs = $t->instance()->getTabs();
        $this->assertSame(['all', 'planned', 'drafting', 'needs_review', 'rejected', 'approved', 'scheduled', 'published'], array_keys($tabs));
        $this->assertSame(4, $tabs['all']->getBadge());
        $this->assertSame(2, $tabs['needs_review']->getBadge());
        $this->assertSame(0, $tabs['drafting']->getBadge());
        $this->assertSame(1, $tabs['published']->getBadge());

        $t->set('activeTab', 'needs_review')->assertCanSeeTableRecords([$r1, $r2])->assertCanNotSeeTableRecords([$p, $pub]);
    }

    public function test_tab_mac_dinh_theo_vai_tro(): void
    {
        $this->actingAs($this->user('approver'));
        $this->assertSame('all', Livewire::test(ListArticles::class)->instance()->getDefaultActiveTab());
        $this->article('needs_review');
        $this->assertSame('needs_review', Livewire::test(ListArticles::class)->instance()->getDefaultActiveTab());

        $this->actingAs($this->user('writer'));
        $this->assertSame('all', Livewire::test(ListArticles::class)->instance()->getDefaultActiveTab());
    }

    public function test_bien_tap_vien_van_thay_ca_bai_nhu_policy_hien_tai(): void
    {
        $a = $this->article('planned');
        $this->actingAs($this->user('writer'));
        Livewire::test(ListArticles::class)->assertCanSeeTableRecords([$a]);
        $this->assertTrue(ArticleResource::canViewAny());
    }

    public function test_menu_chi_con_mot_muc_va_badge_cho_duyet(): void
    {
        $this->actingAs($this->user('approver'));
        $this->assertNull(ArticleResource::getNavigationBadge());
        $this->article('needs_review');
        $this->assertSame('1', ArticleResource::getNavigationBadge());
        $this->assertFalse(ArticleVersionResource::shouldRegisterNavigation());
    }

    public function test_nut_chinh_theo_trang_thai(): void
    {
        $this->actingAs($this->user('approver'));
        $planned = $this->article('planned');
        $review = $this->article('needs_review');
        $approved = $this->article('approved');
        $published = $this->article('published');
        $plannedNoVersion = $this->article('planned', null, false);

        $this->assertSame('submit_review', ArticleResource::primaryActionFor($planned));
        $this->assertSame('approve', ArticleResource::primaryActionFor($review));
        $this->assertSame('schedule', ArticleResource::primaryActionFor($approved));
        $this->assertSame('view_version', ArticleResource::primaryActionFor($published));
        $this->assertSame('view_version', ArticleResource::primaryActionFor($plannedNoVersion));

        Livewire::test(ListArticles::class)
            ->assertTableActionVisible('submit_review', $planned)->assertTableActionHidden('approve', $planned)->assertTableActionHidden('schedule', $planned)
            ->assertTableActionVisible('approve', $review)->assertTableActionHidden('submit_review', $review)
            ->assertTableActionVisible('schedule', $approved)->assertTableActionHidden('approve', $approved)
            ->assertTableActionVisible('view_version', $published)->assertTableActionHidden('approve', $published);

        $this->actingAs($this->user('writer'));
        $this->assertSame('view_version', ArticleResource::primaryActionFor($review));
    }

    public function test_bang_gon_cot_noi_dung_rong_cot_phu_nowrap_va_nut_o_cot_cuoi(): void
    {
        $this->actingAs($this->user('approver'));
        $table = Livewire::test(ListArticles::class)->instance()->getTable();

        $main = $table->getColumn('primary_keyword');
        $this->assertSame('ss-col-main', $main->getExtraHeaderAttributes()['class']);
        $this->assertSame('ss-col-main', $main->getExtraCellAttributes()['class']);
        $css = file_get_contents(public_path('css/seo-studio.css'));
        $this->assertMatchesRegularExpression('/\.ss-col-main\s*\{[^}]*min-width:\s*12rem/', $css);
        $this->assertStringContainsString('position: sticky; right: 0', $css);
        $this->assertStringContainsString('tr.ss-sticky-row > td:last-child', $css);
        $this->assertContains('ss-sticky-row', $table->getRecordClasses(new Article));
        foreach (['campaign_name' => 16, 'last_run' => 40] as $name => $limit) {
            $this->assertSame($limit, $table->getColumn($name)->getCharacterLimit(), $name);
        }
        foreach (['campaign_name', 'last_run', 'updated_at'] as $name) {
            $this->assertNotNull($table->getColumn($name)->getVisibleFrom() ?? null, $name);
        }
        foreach (['workflow_status', 'campaign_name', 'last_run', 'updated_at'] as $name) {
            $this->assertStringContainsString('width:1%;white-space:nowrap', $table->getColumn($name)->getExtraHeaderAttributes()['style'], $name);
        }
        foreach (['website.name', 'topic_cluster', 'search_intent', 'created_at'] as $name) {
            $this->assertTrue($table->getColumn($name)->isToggledHiddenByDefault(), $name);
        }
        $this->assertSame(ActionsPosition::AfterColumns, $table->getActionsPosition());
    }

    public function test_hieu_ung_va_poll_chi_khi_co_phien_running(): void
    {
        $this->actingAs($this->user('approver'));
        $a = $this->article('drafting');

        $idle = Livewire::test(ListArticles::class);
        $this->assertSame('10s', $idle->instance()->getTable()->getPollingInterval());
        $this->assertStringNotContainsString('ss-badge-live', $idle->html());

        $run = $this->mkRun($a, 'running');
        AgentRunStep::create(['agent_run_id' => $run->id, 'seq' => 1, 'role' => 'writer', 'stage' => 'write', 'status' => 'ok', 'title' => 'Viết', 'detail' => 'x']);
        $live = Livewire::test(ListArticles::class);
        $this->assertSame('2s', $live->instance()->getTable()->getPollingInterval());
        $live->assertSee('ss-badge-live', false)->assertSee('Đang viết · Chấm');
    }

    public function test_bai_dang_chay_khong_cho_giao_doi_ai_lan_nua(): void
    {
        $this->actingAs($this->user('approver'));
        $busy = $this->article('planned');
        $free = $this->article('planned');
        $this->mkRun($busy, 'running');

        Livewire::test(ListArticles::class)
            ->assertTableActionHidden('run_agent', $busy)
            ->assertTableActionVisible('run_agent', $free);
    }

    public function test_phien_cu_bi_treo_khong_co_hieu_ung(): void
    {
        $this->actingAs($this->user('approver'));
        $a = $this->article('planned');
        $run = $this->mkRun($a, 'running');
        $t = now()->utc()->subHour()->format('Y-m-d H:i:s');
        DB::table('agent_runs')->where('id', $run->id)->update(['started_at' => $t, 'created_at' => $t]);

        Livewire::test(ListArticles::class)->assertDontSee('ss-badge-live', false)->assertTableActionVisible('run_agent', $a);
    }

    public function test_cot_chien_dich_phien_gan_nhat_va_bo_loc(): void
    {
        $this->actingAs($this->user('approver'));
        $camp = Campaign::create(['website_id' => $this->site->id, 'name' => 'Chiến dịch Xuân', 'status' => 'active', 'keywords' => []]);
        $in = $this->article('needs_review');
        $out = $this->article('needs_review');
        $in->update(['campaign_id' => $camp->id]);
        $run = $this->mkRun($in, 'ready_for_review', ['campaign_id' => $camp->id]);
        AgentRunStep::create(['agent_run_id' => $run->id, 'seq' => 1, 'role' => 'grader', 'stage' => 'grade', 'status' => 'ok', 'title' => 'Chấm', 'detail' => 'x', 'data' => ['score' => 87]]);
        $this->mkRun($out, 'running');

        Livewire::test(ListArticles::class)
            ->assertSee('Chiến dịch Xuân')->assertSee('Chờ duyệt')->assertSee('Bài lẻ')
            ->filterTable('campaign_id', $camp->id)->assertCanSeeTableRecords([$in])->assertCanNotSeeTableRecords([$out])
            ->removeTableFilters()
            ->filterTable('ai_running', true)->assertCanSeeTableRecords([$out])->assertCanNotSeeTableRecords([$in])
            ->removeTableFilters()
            ->filterTable('ai_running', false)->assertCanSeeTableRecords([$in])->assertCanNotSeeTableRecords([$out]);
    }

    public function test_tim_theo_tu_khoa_va_tieu_de(): void
    {
        $this->actingAs($this->user('writer'));
        $a = $this->article('planned', 'may loc nuoc');
        $b = $this->article('planned', 'quat dieu hoa');
        ArticleVersion::where('article_id', $b->id)->update(['title' => 'Cách chọn quạt đặc biệt']);

        Livewire::test(ListArticles::class)->searchTable('loc nuoc')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b])
            ->searchTable('đặc biệt')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a]);
    }

    public function test_duong_dan_cu_van_chay(): void
    {
        $a = $this->article('needs_review');
        $v = $a->versions()->first();
        $this->actingAs($this->user('writer'));
        $this->get('/admin/article-versions')->assertRedirect(ArticleResource::getUrl('index'));
        $this->get('/admin/article-versions?tableFilters[review_status][value]=rejected')->assertRedirect(ArticleResource::getUrl('index', ['activeTab' => 'rejected']));
        $this->get("/admin/article-versions/{$v->id}")->assertOk();
    }
}
