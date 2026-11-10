<?php

namespace Tests\Feature\Planning;

use App\Filament\Pages\ContentPlanPage;
use App\Models\Article;
use App\Models\BrandProfile;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\KeywordGap;
use App\Models\User;
use App\Models\Website;
use App\Services\Planning\ContentPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class ContentPlannerTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Website::create(['name' => 'A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function kw(string $k, ?int $vol = null, ?KeywordCluster $c = null, string $status = 'new'): Keyword
    {
        return Keyword::create(['website_id' => $this->site->id, 'keyword' => $k, 'keyword_key' => Keyword::keyFor($k), 'volume' => $vol,
            'cluster_id' => $c?->id, 'status' => $status, 'source' => 'tay']);
    }

    private function cluster(string $n): KeywordCluster
    {
        return KeywordCluster::create(['website_id' => $this->site->id, 'name' => $n, 'intent' => 'informational']);
    }

    private function names(array $rows): array
    {
        return array_column($rows, 'keyword');
    }

    public function test_bo_tu_khoa_da_co_bai_va_xep_theo_volume_null_cuoi(): void
    {
        $this->kw('thấp', 10);
        $this->kw('cao', 500);
        $this->kw('không số liệu');
        $this->kw('đã viết', 900);
        Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'Đã  Viết', 'workflow_status' => 'planned', 'kind' => 'new']);
        $rows = (new ContentPlanner)->propose($this->site);
        $this->assertSame(['cao', 'thấp', 'không số liệu'], $this->names($rows));
        $this->assertNull($rows[2]['volume']);
        $this->assertContains('chưa có số liệu lượt tìm', $rows[2]['reasons']);
        $this->assertSame(0, Article::where('origin', 'plan')->count());
    }

    public function test_cum_da_nhieu_bai_xep_thap_hon_va_khoang_trong_cong_diem(): void
    {
        $full = $this->cluster('đầy');
        $empty = $this->cluster('trống');
        foreach (['a', 'b', 'c'] as $x) {
            Article::create(['website_id' => $this->site->id, 'primary_keyword' => "bài $x", 'workflow_status' => 'planned', 'kind' => 'new', 'cluster_id' => $full->id]);
        }
        $this->kw('trong đầy', 100, $full);
        $this->kw('trong trống', 100, $empty);
        $rows = (new ContentPlanner)->propose($this->site);
        $this->assertSame(['trong trống', 'trong đầy'], $this->names($rows));
        KeywordGap::create(['website_id' => $this->site->id, 'keyword' => 'trong đầy', 'priority_score' => 100, 'status' => 'moi']);
        $after = (new ContentPlanner)->propose($this->site);
        $this->assertSame(20, collect($after)->firstWhere('keyword', 'trong đầy')['priority'] - collect($rows)->firstWhere('keyword', 'trong đầy')['priority']);
        $this->assertContains('khoảng trống đối thủ (điểm 100)', collect($after)->firstWhere('keyword', 'trong đầy')['reasons']);
    }

    public function test_lech_chu_de_canh_bao_xuong_cuoi_va_create_bo_qua(): void
    {
        BrandProfile::create(['website_id' => $this->site->id, 'business_field' => 'tư vấn du học']);
        $this->kw('du học nhật', 10);
        $this->kw('công thức nấu phở', 9999);
        $rows = (new ContentPlanner)->propose($this->site);
        $this->assertSame('công thức nấu phở', $rows[1]['keyword']);
        $this->assertTrue($rows[1]['off_topic']);
        $r = (new ContentPlanner)->create($this->user('writer'), $this->site, array_column($rows, 'keyword_id'));
        $this->assertSame(1, $r['created']);
        $this->assertCount(1, $r['skipped']);
        $this->assertStringContainsString('lệch', $r['skipped'][0]);
    }

    public function test_create_tao_bai_planned_co_noi_ket_va_trung_thi_bo(): void
    {
        $c = $this->cluster('du học');
        $a = $this->kw('du học úc', 300, $c);
        $b = $this->kw('du học mỹ', 100, $c);
        $r = (new ContentPlanner)->create($this->user('writer'), $this->site, [$a->id, $b->id]);
        $this->assertSame(2, $r['created']);
        $art = Article::where('keyword_id', $a->id)->first();
        $this->assertSame('planned', $art->workflow_status);
        $this->assertSame($c->id, $art->cluster_id);
        $this->assertSame('plan', $art->origin);
        $this->assertGreaterThan(Article::where('keyword_id', $b->id)->value('priority'), $art->priority);
        $this->assertSame('planned', $a->fresh()->status);

        $again = (new ContentPlanner)->create($this->user('writer'), $this->site, [$a->id]);
        $this->assertSame(0, $again['created']);
        $this->assertNotEmpty($again['skipped']);
    }

    public function test_viewer_bi_chan(): void
    {
        $k = $this->kw('x', 1);
        $this->expectException(InvalidArgumentException::class);
        (new ContentPlanner)->create($this->user('viewer'), $this->site, [$k->id]);
    }

    public function test_trang_hien_hop_de_xuat_va_nut_them(): void
    {
        $k = $this->kw('du học úc', 300);
        $this->actingAs($this->user('writer'));
        Livewire::test(ContentPlanPage::class)
            ->assertSee('Đề xuất kế hoạch (theo luật)')
            ->assertSee('Xếp theo luật, không dùng AI')
            ->assertSee('du học úc')
            ->set('planPick', [$k->id])
            ->call('addProposed');
        $this->assertSame('plan', Article::where('keyword_id', $k->id)->value('origin'));
        $this->assertSame('planned', Article::where('keyword_id', $k->id)->value('workflow_status'));
    }
}
