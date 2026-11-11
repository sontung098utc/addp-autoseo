<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ContentPlanPage;
use App\Filament\Pages\KeywordsPage;
use App\Models\Article;
use App\Models\Campaign;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/** Dải 4 bước và "Thêm vào kế hoạch" trên kho từ khóa THẬT (bảng keywords). */
class KeywordsStepsRealTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['studio.keywords_real' => true, 'keywords.dataforseo.login' => 'u@x.vn', 'keywords.dataforseo.password' => 'S3cretPass']);
        $this->site = Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->actingAs(User::create(['name' => 'w', 'email' => 'w@x.vn', 'password' => 'secret123', 'role' => 'writer', 'is_active' => true]));
    }

    private function kw(string $k, array $extra = []): Keyword
    {
        return Keyword::create($extra + ['website_id' => $this->site->id, 'keyword' => $k, 'keyword_key' => Keyword::keyFor($k), 'intent' => 'informational', 'source' => 'csv', 'status' => 'new']);
    }

    public function test_kho_rong_buoc_dau_la_nhap_tu_khoa(): void
    {
        $t = Livewire::test(KeywordsPage::class);
        $s = $t->viewData('steps');
        $this->assertSame('add', $s['next']);
        $this->assertSame(['0', '0/0', '0/0', '0'], array_column($s['items'], 'value'));
        $t->assertSee('Chưa có từ khóa')->assertSee('Nhập từ khóa');
    }

    public function test_dem_dung_va_chi_so_lieu_that_moi_tinh_o_buoc_2(): void
    {
        $c = KeywordCluster::create(['website_id' => $this->site->id, 'name' => 'CX', 'intent' => 'informational']);
        $this->kw('a dfs', ['volume' => 900, 'volume_source' => 'dataforseo', 'cluster_id' => $c->id]);
        $this->kw('b csv', ['volume' => 50, 'volume_source' => 'csv']);
        $this->kw('c giả lập', ['volume' => 77, 'volume_source' => 'mock']);   // không phải số thật
        $this->kw('d không rõ', ['volume' => 5]);                                // không rõ nguồn
        $this->kw('e trống');
        Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'A DFS', 'workflow_status' => 'published']);
        $t = Livewire::test(KeywordsPage::class);
        $s = $t->viewData('steps');
        $this->assertSame(['5', '2/5', '1/5', '1'], array_column($s['items'], 'value'));
        // Còn thiếu lượt tìm thật và nguồn bấm được -> bước ② được nhấn mạnh.
        $this->assertSame('metrics', $s['next']);
        $html = $t->html();
        $this->assertMatchesRegularExpression('/kw-step is-next[^"]*"\s+data-step="metrics"/', $html);
        $this->assertSame(1, substr_count($html, 'kw-step is-next'));
    }

    public function test_thieu_nguon_so_lieu_thi_nhan_manh_buoc_gom_cum_va_noi_ly_do(): void
    {
        config(['keywords.dataforseo.login' => null]);
        $this->kw('a');
        $t = Livewire::test(KeywordsPage::class);
        $this->assertSame('cluster', $t->viewData('steps')['next']);
        $t->assertSee('DATAFORSEO_LOGIN / DATAFORSEO_PASSWORD');
    }

    public function test_het_cum_thi_buoc_ke_tiep_la_mo_ke_hoach(): void
    {
        $c = KeywordCluster::create(['website_id' => $this->site->id, 'name' => 'CX', 'intent' => 'informational']);
        $this->kw('a', ['volume' => 9, 'volume_source' => 'dataforseo', 'cluster_id' => $c->id]);
        $t = Livewire::test(KeywordsPage::class);
        $this->assertSame('plan', $t->viewData('steps')['next']);
        $t->assertSee(ContentPlanPage::getUrl());
    }

    public function test_them_vao_ke_hoach_tao_bai_planned_bai_le(): void
    {
        $c = KeywordCluster::create(['website_id' => $this->site->id, 'name' => 'CX', 'intent' => 'commercial']);
        $k = $this->kw('máy đo đường huyết', ['intent' => 'commercial', 'cluster_id' => $c->id]);
        Livewire::test(KeywordsPage::class)->call('addToPlan', $k->id)->assertActionMounted('addToPlanConfirm')
            ->setActionData(['campaign' => 'solo'])->callMountedAction()->assertNotified('Đã thêm vào kế hoạch');
        $a = Article::where('primary_keyword', 'máy đo đường huyết')->firstOrFail();
        $this->assertSame('planned', $a->workflow_status);
        $this->assertNull($a->campaign_id);
        $this->assertSame($k->id, $a->keyword_id);
        $this->assertSame('CX', $a->topic_cluster);
        $this->assertSame('commercial', $a->search_intent);
        $this->assertSame('planned', $k->fresh()->status);
        $row = collect(Livewire::test(KeywordsPage::class)->viewData('kwRows'))->firstWhere('keyword', 'máy đo đường huyết');
        $this->assertSame('Trong kế hoạch', $row['status']['text']);
    }

    public function test_them_vao_ke_hoach_gan_chien_dich_va_chan_chien_dich_la(): void
    {
        $camp = Campaign::create(['website_id' => $this->site->id, 'name' => 'CD', 'status' => 'active', 'daily_limit' => 2, 'source' => 'plan', 'keywords' => []]);
        $other = Website::create(['name' => 'Site B', 'cms_type' => 'wordpress', 'base_url' => 'https://b.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $foreign = Campaign::create(['website_id' => $other->id, 'name' => 'CD khác', 'status' => 'active', 'daily_limit' => 2, 'source' => 'plan', 'keywords' => []]);
        $k = $this->kw('que thử đường huyết');
        $k2 = $this->kw('huyết áp cao');
        $t = Livewire::test(KeywordsPage::class);
        $t->call('addToPlanNow', $k->id, (string) $camp->id);
        $this->assertSame($camp->id, Article::where('primary_keyword', 'que thử đường huyết')->value('campaign_id'));
        $t->call('addToPlanNow', $k2->id, (string) $foreign->id)->assertNotified('Không thêm được');
        $this->assertSame(0, Article::where('primary_keyword', 'huyết áp cao')->count());
    }

    public function test_them_vao_ke_hoach_khong_tao_bai_trung(): void
    {
        $k = $this->kw('đã có bài');
        Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'ĐÃ CÓ  BÀI', 'workflow_status' => 'drafting']);
        Livewire::test(KeywordsPage::class)->call('addToPlanNow', $k->id)->assertNotified('Từ khóa đã có bài');
        $this->assertSame(1, Article::where('website_id', $this->site->id)->count());
    }

    public function test_viewer_khong_them_vao_ke_hoach_duoc(): void
    {
        $k = $this->kw('x');
        $this->actingAs(User::create(['name' => 'v', 'email' => 'v@x.vn', 'password' => 'secret123', 'role' => 'viewer', 'is_active' => true]));
        $this->assertFalse(KeywordsPage::canAccess());
        try {
            Livewire::test(KeywordsPage::class)->call('addToPlanNow', $k->id);
        } catch (\Throwable) {
        }
        $this->assertSame(0, Article::count());
    }
}
