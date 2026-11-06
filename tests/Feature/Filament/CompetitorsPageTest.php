<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\CompetitorsPage;
use App\Filament\Support\Competitors\CompetitorService;
use App\Filament\Support\Competitors\CostPolicy;
use App\Filament\Support\Competitors\KeywordGapSource;
use App\Filament\Support\Competitors\SourceUnavailableException;
use App\Models\Article;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class CompetitorsPageTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->site = Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://blog.company.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function user(string $role, bool $active = true): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => $active]);
    }

    private function page(string $role = 'approver', ?string $demo = null)
    {
        $this->actingAs($this->user($role));

        return Livewire::withQueryParams($demo ? ['demo' => $demo] : [])->test(CompetitorsPage::class);
    }

    public function test_writer_gets_403_on_route(): void
    {
        $this->actingAs($this->user('writer'))->get('/admin/doi-thu')->assertForbidden();
    }

    public function test_approver_opens_route(): void
    {
        $this->actingAs($this->user('approver'))->get('/admin/doi-thu')->assertOk();
    }

    public function test_inactive_user_blocked(): void
    {
        $this->actingAs($this->user('approver', false));
        $this->assertFalse(CompetitorsPage::canAccess());
        $this->get('/admin/doi-thu')->assertStatus(403);
    }

    public function test_guest_redirected(): void
    {
        $this->get('/admin/doi-thu')->assertRedirect();
    }

    public function test_writer_cannot_mutate_via_livewire(): void
    {
        $this->actingAs($this->user('writer'));
        Livewire::test(CompetitorsPage::class)->assertForbidden();
        $this->assertSame([], CompetitorService::domains($this->site));
    }

    public function test_add_and_remove_competitor(): void
    {
        $this->page()->set('newDomain', 'HTTPS://www.DoiThu.vn/bai')->call('addCompetitor')->assertSee('doithu.vn');
        $this->assertSame(['doithu.vn'], CompetitorService::domains($this->site));
        $this->page()->call('removeCompetitor', 'doithu.vn');
        $this->assertSame([], CompetitorService::domains($this->site));
    }

    public function test_invalid_competitors_rejected(): void
    {
        $p = $this->page();
        foreach (['127.0.0.1', 'localhost', 'site.test', 'blog.company.vn', 'doi thu.vn'] as $bad) {
            $p->set('newDomain', $bad)->call('addCompetitor');
        }
        $this->assertSame([], CompetitorService::domains($this->site));
        $p->set('newDomain', 'a.vn')->call('addCompetitor')->set('newDomain', 'a.vn')->call('addCompetitor');
        $this->assertSame(['a.vn'], CompetitorService::domains($this->site));
        for ($i = 0; $i < 12; $i++) {
            $p->set('newDomain', "d$i.vn")->call('addCompetitor');
        }
        $this->assertCount(10, CompetitorService::domains($this->site));
    }

    public function test_refresh_flow_estimate_confirm_and_charge(): void
    {
        $p = $this->page()->set('newDomain', 'tuyensinhhub.example')->call('addCompetitor')
            ->assertSee('ước tính')->assertSee('Chưa làm mới lần nào');
        $this->assertSame(0, CostPolicy::used());
        $p->mountAction('refresh')->assertSee('Xác nhận')->assertSee('VND');
        $this->assertSame(0, CostPolicy::used(), 'Mở hộp xác nhận chưa được tính phí');
        $p->callMountedAction();
        $this->assertSame(1000, CostPolicy::used());
        $p->assertSee('data-co-section="gaps"', false);
        $this->assertNotNull(CompetitorService::snapshot($this->site));
        $this->assertCount(1, \App\Filament\Support\Demo\DemoStore::get('competitors.log'));
    }

    public function test_over_quota_blocks_refresh(): void
    {
        $p = $this->page()->set('newDomain', 'a.example')->call('addCompetitor');
        CostPolicy::charge(CostPolicy::MONTHLY_ROW_LIMIT - 500); // còn 500 < 1000 dòng cần
        $p->assertSee('Vượt hạn mức')->assertActionDisabled('refresh');
        $p->call('doRefresh');
        $this->assertSame(CostPolicy::MONTHLY_ROW_LIMIT - 500, CostPolicy::used());
        $this->assertNull(CompetitorService::snapshot($this->site));
    }

    public function test_api_error_keeps_old_data_labelled_and_no_charge(): void
    {
        $p = $this->page()->set('newDomain', 'a.example')->call('addCompetitor')->call('doRefresh');
        $before = CompetitorService::snapshot($this->site);
        $used = CostPolicy::used();
        $this->assertNotNull($before);

        app()->instance(KeywordGapSource::class, new class implements KeywordGapSource
        {
            public function estimateRows(int $n): int { return $n * 1000; }

            public function fetch(array $domains): array { throw new SourceUnavailableException('429 hết hạn mức'); }
        });
        $p->call('doRefresh')->assertSee('Không lấy được dữ liệu')->assertSee('Dữ liệu cũ')->assertDontSee('data-co-section="gaps"', false);
        $this->assertSame($before, CompetitorService::snapshot($this->site));
        $this->assertSame($used, CostPolicy::used());
        $p->set('selected', ['x'])->call('addToPlan'); // không thêm từ bản cũ
        $this->assertSame(0, Article::count());
    }

    private function loaded()
    {
        return $this->page('approver', 'ket-noi')->call('doRefresh');
    }

    public function test_add_to_plan_creates_real_articles_and_marks_rows(): void
    {
        $p = $this->loaded();
        $open = collect($p->instance()->gaps())->filter(fn ($g) => $g['existing'] === null)->take(2)->values();
        $this->assertCount(2, $open);
        $p->set('selected', $open->pluck('norm')->all())->call('addToPlan');

        $this->assertSame(2, Article::count());
        $a = Article::where('primary_keyword', $open[0]['keyword'])->first();
        $this->assertSame('planned', $a->workflow_status);
        $this->assertSame($this->site->id, $a->website_id);
        $this->assertNotNull($a->created_by);
        $this->assertSame($open[0]['intent'], $a->search_intent);
        $p->assertSee('Đã tạo bài #'.$a->id);
        $row = collect($p->instance()->gaps())->firstWhere('norm', $open[0]['norm']);
        $this->assertSame('planned', $row['existing']['status']);
        $this->assertNull($row['score']);
    }

    public function test_duplicate_blocked_and_partial_success(): void
    {
        $p = $this->loaded();
        $open = collect($p->instance()->gaps())->filter(fn ($g) => $g['existing'] === null)->values();
        // sau khi hiển thị, có người tạo trùng ở nơi khác (đua)
        Article::create(['website_id' => $this->site->id, 'created_by' => 1, 'primary_keyword' => strtoupper($open[0]['keyword']), 'workflow_status' => 'planned']);
        $before = Article::count();
        $p->set('selected', [$open[1]['norm']])->call('addToPlan');
        $this->assertSame($before + 1, Article::count());

        $p->set('selected', [$open[0]['norm'], $open[2]['norm'], 'khong ton tai'])->call('addToPlan');
        $this->assertSame($before + 2, Article::count(), 'Từ khóa trùng bị chặn, từ khóa còn lại vẫn tạo');
        $p->assertSee('Bị chặn vì trùng')->assertSee('Không còn trong danh sách');
        $this->assertSame(1, Article::whereRaw('lower(primary_keyword) = ?', [mb_strtolower($open[0]['keyword'])])->count());
    }

    public function test_existing_article_marked_not_suggested(): void
    {
        $this->actingAs($this->user('approver'));
        $kw = \App\Filament\Support\Demo\CompetitorsDemoData::keywordPool()[0]['keyword'];
        $art = Article::create(['website_id' => $this->site->id, 'created_by' => 1, 'primary_keyword' => '  '.mb_strtoupper($kw).' ', 'workflow_status' => 'published']);
        $p = $this->loaded();
        $row = collect($p->instance()->gaps())->first(fn ($g) => $g['norm'] === $kw);
        if ($row) { // từ khóa này có thể không được đối thủ giả lập xếp hạng; nếu có thì phải bị đánh dấu
            $this->assertSame($art->id, $row['existing']['id']);
            $st = collect($p->instance()->getViewData()['gapRows'])->firstWhere('id', $kw)['status'];
            $this->assertSame('info', $st['tone']);
            $this->assertStringContainsString('#'.$art->id, $st['text']);
        }
        $p->set('selected', [$kw])->call('addToPlan');
        $this->assertSame(1, Article::count());
    }

    public function test_states(): void
    {
        $this->page('approver', 'chua-ket-noi')->assertOk();
        $this->page('approver', 'dang-tai')->assertSee('Đang lấy dữ liệu');
        $this->page('approver', 'khong-doi-thu')->assertSee('Chưa có đối thủ nào');
        $this->page('approver', 'ket-noi')->assertSee('tuyensinhhub.example');
        $e = $this->page('approver', 'loi-api')->set('newDomain', 'a.example')->call('addCompetitor')->call('doRefresh');
        $e->assertSee('Không lấy được dữ liệu')->assertSee('429');
        $this->assertSame(0, CostPolicy::used());
    }

    public function test_production_shows_unconnected_without_sample_data(): void
    {
        $this->app['env'] = 'production';
        $this->assertTrue(app()->isProduction());
        $this->page('approver', 'ket-noi')
            ->assertSee('Chưa kết nối nguồn từ khóa trả phí')
            ->assertDontSee('tuyensinhhub.example')->assertDontSee('Dữ liệu minh họa —')
            ->set('newDomain', 'a.vn')->call('addCompetitor');
        $this->assertSame([], CompetitorService::domains($this->site));
    }

    public function test_ui_has_tabs_single_primary_and_no_noise(): void
    {
        $html = $this->loaded()->html();
        foreach (['Khoảng trống', 'Đối thủ', 'Nguồn và chi phí', 'Cụm gợi ý', 'Quy tắc'] as $tab) {
            $this->assertStringContainsString($tab, $html);
        }
        $this->assertSame(1, substr_count($html, 'data-color="primary"') + substr_count($html, 'fi-color-primary'), 'Chỉ một nút primary');
        $this->assertStringNotContainsString('minh họa', $html);
        $this->assertStringNotContainsString('Luật nghiệp vụ của màn này', $html);
        $this->assertStringContainsString('ss-page', $html);
    }

    public function test_gap_table_config_rows_columns_and_selectable_model(): void
    {
        $p = $this->loaded();
        $d = $p->instance()->getViewData();
        $keys = array_column($d['gapColumns'], 'key');
        foreach (['keyword', 'intent', 'volume', 'difficulty', 'ranks', 'status', 'score'] as $k) {
            $this->assertContains($k, $keys);
        }
        $this->assertSame(count($p->instance()->gaps()), count($d['gapRows']));
        $this->assertStringContainsString('selectModel\\u0022:\\u0022selected\\u0022', $p->html());
        $this->assertStringContainsString('selectable\\u0022:true', $p->html());
        $open = collect($d['gapRows'])->first(fn ($r) => is_string($r['status']));
        $p->set('selected', [$open['id']])->call('addToPlan');
        $this->assertSame(1, Article::count());
    }

    public function test_selecting_existing_row_is_skipped(): void
    {
        $p = $this->loaded();
        $g = collect($p->instance()->gaps())->first(fn ($g) => $g['existing'] === null);
        Article::create(['website_id' => $this->site->id, 'created_by' => 1, 'primary_keyword' => $g['keyword'], 'workflow_status' => 'planned']);
        $p->set('selected', [$g['norm']])->call('addToPlan');
        $this->assertSame(1, Article::count());
    }
}
