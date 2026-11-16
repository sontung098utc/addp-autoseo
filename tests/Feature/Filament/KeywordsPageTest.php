<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\KeywordsPage;
use App\Filament\Support\Keywords\KeywordService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class KeywordsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function user(string $role, bool $active = true): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => $active]);
    }

    public function test_quyen_truy_cap(): void
    {
        $this->actingAs($this->user('writer'));
        $this->assertTrue(KeywordsPage::canAccess());
        $this->actingAs($this->user('writer', false));
        $this->assertFalse(KeywordsPage::canAccess());
        auth()->logout();
        $this->assertFalse(KeywordsPage::canAccess());
        $this->get('/admin/tu-khoa')->assertRedirect();
    }

    public function test_writer_mo_trang_thay_hai_tab_dung_khoa_va_khong_banner(): void
    {
        $html = $this->actingAs($this->user('writer'))->get('/admin/tu-khoa')->assertOk()->assertDontSee('Dữ liệu minh họa')->getContent();
        preg_match_all('/role="tab"[^>]*data-key="([^"]+)"/', $html, $m);
        $this->assertSame(['tu-khoa', 'nhap-them'], $m[1]);
        $this->assertStringNotContainsString('data-key="propose"', $html);
        $this->assertStringContainsString('Nhập thêm', $html);
    }

    public function test_khong_con_chain_context_va_chi_mot_dau_i(): void
    {
        $html = $this->actingAs($this->user('writer'))->get('/admin/tu-khoa')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-testid="chain-context"', $html);
        $this->assertSame(1, substr_count($html, 'data-testid="help"'));
        $this->assertStringContainsString('Kho nguyên liệu để chọn bài nào viết trước', $html);
    }

    public function test_dai_4_buoc_co_so_that_va_buoc_ke_tiep_duoc_nhan_manh(): void
    {
        $this->actingAs($this->user('writer'));
        $t = Livewire::test(KeywordsPage::class);
        $rows = $t->viewData('kwRows');
        $total = count($rows);
        $volReal = count(array_filter($rows, fn ($r) => $r['volumeReal']));
        $clustered = count(array_filter($rows, fn ($r) => $r['cluster'] !== 'Chưa gom cụm'));
        $used = count(array_filter($rows, fn ($r) => $r['usage'] !== 'unused'));
        $this->assertGreaterThan(0, $volReal);
        $this->assertLessThan($total, $volReal);
        $this->assertGreaterThan(0, $clustered);
        $this->assertLessThan($total, $clustered);
        $this->assertGreaterThan(0, $used);
        $steps = $t->viewData('steps');
        $this->assertSame([(string) $total, "$volReal/$total", "$clustered/$total", (string) $used], array_column($steps['items'], 'value'));
        // Bản xem thử chưa nối nguồn số liệu thật nên ② không bấm được; bước kế tiếp là ③ Gom cụm.
        $this->assertSame('cluster', $steps['next']);
        $t->assertSeeHtml('data-next="cluster"')->assertSeeHtml('data-testid="kw-step-cluster"')->assertSee("$clustered/$total");
        $html = $t->html();
        $this->assertSame(1, substr_count($html, 'kw-step is-next'));
        $this->assertMatchesRegularExpression('/kw-step is-next[^"]*"\s+data-step="cluster"/', $html);
        $this->assertStringContainsString('Gom cụm '.($total - $clustered).' từ khóa', $html);
        $this->assertStringContainsString('Mở Kế hoạch', $html);
    }

    public function test_bo_mau_addp_co_du_trang_thai(): void
    {
        $this->actingAs($this->user('writer'));
        $rows = Livewire::test(KeywordsPage::class)->viewData('kwRows');
        $kws = implode(' ', array_column($rows, 'keyword'));
        $this->assertStringContainsString('đường huyết', $kws);
        $this->assertStringNotContainsString('học bổng', $kws);
        $this->assertSame(['article', 'planned', 'unused'], array_values(array_unique(array_merge(['article', 'planned', 'unused'], array_column($rows, 'usage')))));
        $this->assertContains('Đã có bài', array_column(array_column($rows, 'status'), 'text'));
        $this->assertContains('Trong kế hoạch', array_column(array_column($rows, 'status'), 'text'));
        $this->assertContains('AI đề xuất', array_column($rows, 'source'));
        $this->assertContains('DataForSEO', array_column($rows, 'volumeSource'));
        $this->assertContains('Chưa có số liệu', array_column($rows, 'volumeSource'));
        $this->assertContains('Chưa gom cụm', array_column($rows, 'cluster'));
    }

    public function test_them_vao_ke_hoach_ban_xem_thu_danh_dau_va_khong_trung(): void
    {
        $this->actingAs($this->user('writer'));
        $row = collect(KeywordService::make()->repo()->all())->first(fn ($r) => ($r['plan'] ?? null) === null);
        $t = Livewire::test(KeywordsPage::class)->call('addToPlan', $row['id'])->assertActionMounted('addToPlanConfirm');
        $t->callMountedAction()->assertHasNoActionErrors();
        $after = collect(KeywordService::make()->repo()->all())->firstWhere('id', $row['id']);
        $this->assertSame('planned', $after['plan']);
        $t->call('addToPlanNow', $row['id'])->assertNotified('Từ khóa đã nằm trong kế hoạch');
    }

    public function test_bang_chua_dung_dong_va_cot(): void
    {
        $this->actingAs($this->user('writer'));
        $d = Livewire::test(KeywordsPage::class)->viewData('kwRows');
        $this->assertContains('chỉ số đường huyết bình thường', array_column($d, 'keyword'));
        $this->assertSame(['id', 'keyword', 'label', 'intent', 'cluster', 'volume', 'volumeSource', 'volumeReal', 'source', 'usage', 'status'], array_keys($d[0]));
        $cols = collect(Livewire::test(KeywordsPage::class)->viewData('kwColumns'));
        // Cột mặc định gọn: Từ khóa | Lượt tìm | Cụm chủ đề | Trạng thái | hành động; Ý định, Nguồn số liệu, Nguồn ẩn nhưng bật được và vẫn lọc được.
        $shown = $cols->filter(fn ($c) => ($c['visible'] ?? true) !== false)->pluck('key')->all();
        $this->assertSame(['label', 'volume', 'cluster', 'status', 'act'], $shown);
        foreach (['intent', 'volumeSource', 'source'] as $k) {
            $c = $cols->firstWhere('key', $k);
            $this->assertFalse($c['visible']);
            $this->assertTrue($c['filter']);
        }
        $this->assertTrue($cols->firstWhere('key', 'cluster')['filter']);
        $acts = $cols->firstWhere('type', 'actions')['actions'];
        $this->assertSame('addToPlan', $acts[0]['method']);
        $this->assertSame('Thêm vào kế hoạch', $acts[0]['label']);
        // Gói B #13: nút Xóa chỉ mở hộp xác nhận và dùng tông xám, không còn đỏ gạch chân ở mọi dòng.
        $this->assertSame('confirmDeleteKeyword', $acts[1]['method']);
        $this->assertSame('muted', $acts[1]['tone']);
        $ai = collect($d)->first(fn ($r) => $r['source'] === 'AI đề xuất');
        $this->assertStringContainsString('AI đề xuất', $ai['label']);
        $this->assertIsArray(Livewire::test(KeywordsPage::class)->viewData('clusterRows'));
    }

    public function test_xem_truoc_khong_luu_den_khi_xac_nhan(): void
    {
        $this->actingAs($this->user('writer'));
        $before = count(KeywordService::make()->repo()->all());
        $t = Livewire::test(KeywordsPage::class)
            ->set('csvText', "keyword,volume,intent\nmột từ khóa mới <b>,12,commercial\nxấu,x,commercial\n")
            ->call('previewCsv')
            ->assertSee('Xem trước')->assertSee('Dòng 3')->assertSee('<b>')->assertDontSee('<b>', false);
        $this->assertSame($before, count(KeywordService::make()->repo()->all()));
        $t->call('confirmImport');
        $this->assertSame($before + 1, count(KeywordService::make()->repo()->all()));
        $this->assertNull($t->get('preview'));
    }

    public function test_tai_tep_len(): void
    {
        $this->actingAs($this->user('writer'));
        $f = UploadedFile::fake()->createWithContent('k.csv', "\xEF\xBB\xBFkeyword\ntừ trong tệp\n");
        Livewire::test(KeywordsPage::class)->set('csvFile', $f)->assertSet('preview.rows.0.keyword', 'từ trong tệp');
    }

    public function test_them_tay_va_trung_bi_bao(): void
    {
        $this->actingAs($this->user('writer'));
        Livewire::test(KeywordsPage::class)->set('newKeyword', 'từ tay mới')->call('addKeyword')->assertSet('newKeyword', '')
            ->set('newKeyword', 'TỪ TAY MỚI')->call('addKeyword')->assertNotified('Không thêm được từ khóa');
    }

    public function test_luong_gom_cum_qua_giao_dien(): void
    {
        $this->actingAs($this->user('writer'));
        $t = Livewire::test(KeywordsPage::class)->call('propose');
        $svc = KeywordService::make();
        $p = $svc->repo()->proposals();
        $this->assertNotEmpty($p);
        $before = count($svc->repo()->clusters());
        $t->set('proposalNames.'.$p[0]['id'], 'Cụm X')->call('acceptProposal', $p[0]['id']);
        $this->assertSame('Cụm X', $svc->repo()->clusters()[$before]['name']);
        $t->call('startRename', 'Cụm X')->set('renameTo', 'Tên khác')->call('saveRename');
        $this->assertSame('Tên khác', $svc->repo()->clusters()[$before]['name']);
        $this->assertNotEmpty(array_filter($svc->repo()->all(), fn ($r) => $r['cluster'] === 'Tên khác'));
        $p2 = $svc->repo()->proposals();
        $t->call('startMerge', $p2[1]['id'])->call('mergeInto', $p2[0]['id']);
        $this->assertCount(count($p2) - 1, $svc->repo()->proposals());
    }

    public function test_xoa_tu_khoa(): void
    {
        $this->actingAs($this->user('writer'));
        $first = KeywordService::make()->repo()->all()[0];
        Livewire::test(KeywordsPage::class)->call('deleteKeyword', $first['id']);
        $this->assertNull(collect(KeywordService::make()->repo()->all())->firstWhere('id', $first['id']));
    }

    public function test_xoa_phai_qua_hop_xac_nhan(): void
    {
        $this->actingAs($this->user('writer'));
        $first = KeywordService::make()->repo()->all()[0];
        $t = Livewire::test(KeywordsPage::class)->call('confirmDeleteKeyword', $first['id']);
        // Mới mở hộp xác nhận: chưa xóa.
        $this->assertNotNull(collect(KeywordService::make()->repo()->all())->firstWhere('id', $first['id']));
        $t->assertSee('Xóa từ khóa này?')->assertActionMounted('deleteKeywordConfirm');
        $t->callMountedAction();
        $this->assertNull(collect(KeywordService::make()->repo()->all())->firstWhere('id', $first['id']));
    }

    public function test_huy_hop_xac_nhan_khong_xoa(): void
    {
        $this->actingAs($this->user('writer'));
        $first = KeywordService::make()->repo()->all()[0];
        Livewire::test(KeywordsPage::class)->call('confirmDeleteKeyword', $first['id'])->call('unmountAction');
        $this->assertNotNull(collect(KeywordService::make()->repo()->all())->firstWhere('id', $first['id']));
    }

    public function test_user_inactive_bi_chan_o_may_chu(): void
    {
        $u = $this->user('writer');
        $this->actingAs($u);
        $t = Livewire::test(KeywordsPage::class);
        $u->is_active = false;
        $before = count(KeywordService::make()->repo()->all());
        try {
            $t->set('newKeyword', 'lén thêm')->call('addKeyword');
        } catch (\Throwable) {
            // máy chủ từ chối (403); Livewire test không đọc được phản hồi lỗi
        }
        $this->assertSame($before, count(KeywordService::make()->repo()->all()));
        $this->actingAs($this->user('writer', false));
        $this->get('/admin/tu-khoa')->assertForbidden();
    }

    public function test_production_khong_du_lieu_mau(): void
    {
        $this->actingAs($this->user('writer'));
        $this->app['env'] = 'production';
        Livewire::test(KeywordsPage::class)->assertSee('Chưa có từ khóa')->assertDontSee('Dữ liệu minh họa')->assertViewHas('kwRows', []);
    }
}
