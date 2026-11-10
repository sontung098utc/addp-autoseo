<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\KeywordsPage;
use App\Filament\Support\Keywords\KeywordService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_writer_mo_trang_thay_du_tab_va_khong_banner(): void
    {
        $r = $this->actingAs($this->user('writer'))->get('/admin/tu-khoa')->assertOk()->assertDontSee('Dữ liệu minh họa');
        foreach (['Danh sách', 'Nhập từ khóa', 'Gom cụm', 'Cụm đã nhận', 'Quy tắc'] as $tab) {
            $r->assertSee($tab);
        }
    }

    public function test_bang_chua_dung_dong_va_cot(): void
    {
        $this->actingAs($this->user('writer'));
        $d = Livewire::test(KeywordsPage::class)->viewData('kwRows');
        $this->assertContains('học bổng du học Nhật Bản', array_column($d, 'keyword'));
        $this->assertSame(['article', 'id', 'keyword', 'intent', 'cluster', 'volume', 'volumeSource', 'source'], array_keys($d[0]));
        $cols = Livewire::test(KeywordsPage::class)->viewData('kwColumns');
        // Gói B #13: nút Xóa chỉ mở hộp xác nhận và dùng tông xám, không còn đỏ gạch chân ở mọi dòng.
        $act = collect($cols)->firstWhere('type', 'actions')['actions'][0];
        $this->assertSame('confirmDeleteKeyword', $act['method']);
        $this->assertSame('muted', $act['tone']);
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
        $f = \Illuminate\Http\UploadedFile::fake()->createWithContent('k.csv', "\xEF\xBB\xBFkeyword\ntừ trong tệp\n");
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
        $t->set('proposalNames.'.$p[0]['id'], 'Học bổng X')->call('acceptProposal', $p[0]['id']);
        $this->assertSame('Học bổng X', $svc->repo()->clusters()[0]['name']);
        $t->call('startRename', 'Học bổng X')->set('renameTo', 'Tên khác')->call('saveRename');
        $this->assertSame('Tên khác', $svc->repo()->clusters()[0]['name']);
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
