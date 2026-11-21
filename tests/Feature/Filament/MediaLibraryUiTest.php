<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\MediaAssetResource\Pages\ListMediaAssets;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\MediaAsset;
use App\Models\MediaCollection;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class MediaLibraryUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->actingAs(User::create(['name' => 'a', 'email' => 'a@x.vn', 'password' => 'secret123', 'role' => 'admin', 'is_active' => true]));
    }

    private function asset(string $sha, array $o = []): MediaAsset
    {
        return MediaAsset::create($o + ['disk' => 'public', 'file_path' => "media/$sha.webp", 'file_name' => "$sha.webp", 'original_name' => 'o.jpg', 'mime_type' => 'image/webp', 'file_size' => 10, 'sha256' => hash('sha256', $sha), 'title' => $sha]);
    }

    public function test_danh_sach_hien_thi_dang_luoi_va_dang_bang(): void
    {
        $a = $this->asset('a');
        Livewire::test(ListMediaAssets::class)->assertSuccessful()->assertCanSeeTableRecords([$a]);
        Livewire::test(ListMediaAssets::class)->callAction('toggleView')->assertSet('viewMode', 'list')->assertCanSeeTableRecords([$a]);
    }

    public function test_loc_theo_thu_muc_bao_gom_thu_muc_con_loai_chua_dung_va_thieu_alt(): void
    {
        $root = MediaCollection::create(['name' => 'Nội thất', 'slug' => 'noi-that']);
        $child = MediaCollection::create(['name' => 'Sofa', 'slug' => 'sofa', 'parent_id' => $root->id]);
        $inChild = $this->asset('c', ['folder_id' => $child->id, 'alt_text' => 'có alt']);
        $inRoot = $this->asset('r', ['folder_id' => $root->id, 'alt_text' => '']);
        $none = $this->asset('n', ['kind' => 'logo_brand']);

        Livewire::test(ListMediaAssets::class)->set('viewMode', 'list')->set('folder', 'all')
            ->filterTable('folder_id', $root->id)->assertCanSeeTableRecords([$inChild, $inRoot])->assertCanNotSeeTableRecords([$none]);
        session()->flush(); // bộ lọc được nhớ theo phiên
        Livewire::test(ListMediaAssets::class)->set('viewMode', 'list')->set('folder', 'all')
            ->filterTable('kind', 'logo_brand')->assertCanSeeTableRecords([$none])->assertCanNotSeeTableRecords([$inChild]);
        session()->flush();
        Livewire::test(ListMediaAssets::class)->set('viewMode', 'list')->set('folder', 'all')
            ->filterTable('missing_alt')->assertCanSeeTableRecords([$inRoot, $none])->assertCanNotSeeTableRecords([$inChild]);
    }

    public function test_tai_nhieu_anh_qua_media_service_bao_anh_da_co_va_vao_hop_cho_phan_loai(): void
    {
        $folder = MediaCollection::create(['name' => 'Chung', 'slug' => 'chung']);
        $mk = function (string $name, int $r) {
            $p = tempnam(sys_get_temp_dir(), 'up').'.jpg';
            $im = imagecreatetruecolor(320, 200);
            imagefilledrectangle($im, 0, 0, 320, 200, imagecolorallocate($im, $r, 80, 120));
            imagejpeg($im, $p, 90);

            return UploadedFile::fake()->createWithContent($name, file_get_contents($p));
        };
        $files = [$mk('a.jpg', 10), $mk('b.jpg', 200), $mk('a-lai.jpg', 10)];

        Livewire::test(ListMediaAssets::class)
            ->callAction('upload', ['uploads' => $files, 'kind' => 'product', 'folder_id' => $folder->id])
            ->assertHasNoActionErrors();

        $this->assertSame(2, MediaAsset::count()); // ảnh thứ 3 trùng nội dung ảnh 1
        foreach (MediaAsset::all() as $a) {
            $this->assertSame('inbox', $a->status);
            $this->assertSame('product', $a->kind);
            $this->assertSame($folder->id, $a->folder_id);
            $this->assertStringStartsWith('media/cas/', $a->file_path);
            $this->assertTrue(Storage::disk('public')->exists($a->file_path));
        }
    }

    public function test_tai_len_khong_chon_anh_bao_loi_tieng_viet_va_file_gia_bi_tu_choi(): void
    {
        Livewire::test(ListMediaAssets::class)->callAction('upload', ['kind' => 'photo'])->assertHasActionErrors(['uploads' => 'required']);
        Livewire::test(ListMediaAssets::class)
            ->callAction('upload', ['uploads' => [UploadedFile::fake()->createWithContent('x.jpg', '<?php echo 1;')], 'kind' => 'photo']);
        $this->assertSame(0, MediaAsset::count());
    }

    public function test_phan_loai_nhanh_hang_loat_gan_loai_thu_muc_va_the(): void
    {
        $f = MediaCollection::create(['name' => 'F', 'slug' => 'f']);
        $a = $this->asset('a', ['status' => 'inbox']);
        $b = $this->asset('b', ['status' => 'inbox']);

        Livewire::test(ListMediaAssets::class)->set('viewMode', 'list')->set('folder', 'all')
            ->callTableBulkAction('classify', [$a, $b], ['kind' => 'infographic', 'folder_id' => $f->id, 'status' => 'ready', 'tags' => ['Sofa', 'Phòng khách']]);

        foreach ([$a, $b] as $x) {
            $x->refresh();
            $this->assertSame(['infographic', $f->id, 'ready'], [$x->kind, $x->folder_id, $x->status]);
            $this->assertSame(2, $x->tagModels()->count());
        }
    }

    public function test_khong_xoa_anh_dang_gan_vao_bai_da_dang_nhung_xoa_duoc_anh_chua_dung(): void
    {
        $site = Website::create(['name' => 'S', 'cms_type' => 'magento2', 'base_url' => 'https://m.example.com', 'store_scope' => 'default', 'status' => 'active', 'encrypted_credentials' => ['k' => 't']]);
        $art = Article::create(['website_id' => $site->id, 'primary_keyword' => 'k', 'workflow_status' => 'published']);
        $ver = ArticleVersion::create(['article_id' => $art->id, 'version' => 1, 'title' => 't', 'slug' => 's', 'html_content' => '<p>x</p>', 'review_status' => 'approved']);
        Publication::forceCreate(['article_version_id' => $ver->id, 'website_id' => $site->id, 'publication_key' => 'k1', 'scheduled_at_utc' => now(), 'status' => 'published']);
        $used = $this->asset('used');
        $used->articleVersions()->attach($ver->id);
        $free = $this->asset('free');
        $this->assertTrue($used->isUsedInPublication());
        $this->assertFalse($free->isUsedInPublication());

        Livewire::test(ListMediaAssets::class)->set('viewMode', 'list')->set('folder', 'all')
            ->callTableBulkAction('safe_delete', [$used, $free]);

        $this->assertNotNull(MediaAsset::find($used->id));
        $this->assertNull(MediaAsset::find($free->id));
        // Khung xem: ảnh đã đăng không xóa được
        Livewire::test(ListMediaAssets::class)->call('openViewer', $used->id)->call('viewerDelete');
        $this->assertNotNull(MediaAsset::find($used->id));
    }

    public function test_tab_trang_thai_va_hop_thoai_tai_anh_len(): void
    {
        $this->asset('i', ['status' => 'inbox']);
        $this->asset('r', ['status' => 'ready']);
        $this->asset('z', ['status' => 'archived']);

        $page = Livewire::test(ListMediaAssets::class);
        $tabs = $page->instance()->getTabs();
        $this->assertSame([3, 1, 1, 1], array_map(fn ($t) => $t->getBadge(), array_values($tabs)));
        $page->assertSee('3 ảnh');

        $p = tempnam(sys_get_temp_dir(), 'up').'.jpg';
        $im = imagecreatetruecolor(300, 200);
        imagefilledrectangle($im, 0, 0, 300, 200, imagecolorallocate($im, 9, 99, 199));
        imagejpeg($im, $p, 90);
        Livewire::test(ListMediaAssets::class)
            ->callAction('upload', ['uploads' => [UploadedFile::fake()->createWithContent('n.jpg', file_get_contents($p))], 'kind' => 'logo_brand'])
            ->assertHasNoActionErrors();
        $new = MediaAsset::latest('id')->first();
        $this->assertSame(['logo_brand', 'inbox'], [$new->kind, $new->status]);
        $this->assertStringStartsWith('media/cas/', $new->file_path);
    }

    public function test_menu_thao_tac_the_anh_xem_doi_ten_doi_muc_dich_tai_ve(): void
    {
        Storage::disk('public')->put('media/x.webp', 'WEBPDATA');
        $a = $this->asset('x', ['file_path' => 'media/x.webp', 'title' => 'Cũ']);

        $page = Livewire::test(ListMediaAssets::class)->set('viewMode', 'list')->set('folder', 'all');
        $page->callTableAction('rename', $a, ['title' => 'Tên mới'])->assertHasNoTableActionErrors();
        $page->callTableAction('change_kind', $a, ['kind' => 'infographic'])->assertHasNoTableActionErrors();
        $page->callTableAction('open', $a)->assertHasNoTableActionErrors()->assertSet('viewing', $a->id);
        $page->callTableAction('download', $a)->assertHasNoTableActionErrors();
        $a->refresh();
        $this->assertSame(['Tên mới', 'infographic'], [$a->title, $a->kind]);

        Livewire::test(ListMediaAssets::class)->assertSuccessful(); // chế độ lưới (ô ảnh tùy chỉnh) hiển thị được
    }

    public function test_cay_thu_muc_nhieu_cap_dieu_huong_nhu_trinh_quan_ly_tep(): void
    {
        $a = MediaCollection::create(['name' => 'Nội thất', 'slug' => 'noi-that']);
        $b = MediaCollection::create(['name' => 'Sofa', 'slug' => 'sofa', 'parent_id' => $a->id]);
        $c = MediaCollection::create(['name' => 'Da thật', 'slug' => 'da-that', 'parent_id' => $b->id]);
        $d = MediaCollection::create(['name' => 'Màu nâu', 'slug' => 'mau-nau', 'parent_id' => $c->id]);
        $loose = $this->asset('l');
        $inA = $this->asset('a1', ['folder_id' => $a->id]);
        $inD = $this->asset('d1', ['folder_id' => $d->id]);

        // Gốc: thư mục cấp 1 + ảnh chưa xếp thư mục
        $p = Livewire::test(ListMediaAssets::class)->set('viewMode', 'list')
            ->assertCanSeeTableRecords([$loose])->assertCanNotSeeTableRecords([$inA, $inD]);
        $this->assertSame(['Nội thất'], $p->instance()->visibleFolders()->pluck('name')->all());
        $this->assertSame(2, $p->instance()->visibleFolders()->first()['total']); // gồm ảnh của thư mục con cháu

        // Đi sâu 4 cấp: breadcrumb đầy đủ, thấy thư mục con và ảnh của đúng thư mục
        $p->call('openFolder', (string) $d->id)->assertCanSeeTableRecords([$inD])->assertCanNotSeeTableRecords([$loose, $inA]);
        $this->assertSame(['Nội thất', 'Sofa', 'Da thật', 'Màu nâu'], array_column($p->instance()->crumbs(), 'name'));
        $p->call('openFolder', (string) $b->id);
        $this->assertSame(['Da thật'], $p->instance()->visibleFolders()->pluck('name')->all());

        // Tất cả ảnh / tìm kiếm xem toàn bộ
        $p->call('openFolder', 'all')->assertCanSeeTableRecords([$loose, $inA, $inD]);
        $p->call('openFolder', (string) $a->id)->set('tableSearch', 'l')->assertCanSeeTableRecords([$loose]);

        // Lọc theo thư mục gồm cả con cháu
        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id, $d->id], MediaCollection::withDescendantIds($a->id));
        $this->assertSame('Nội thất › Sofa › Da thật', MediaCollection::find($c->id)->path_label);
        $this->assertSame([$a->id => 'Nội thất', $b->id => 'Nội thất › Sofa', $c->id => 'Nội thất › Sofa › Da thật', $d->id => 'Nội thất › Sofa › Da thật › Màu nâu'], MediaCollection::treeOptions());
    }

    public function test_di_chuyen_thu_muc_khong_tao_vong_va_gioi_han_do_sau(): void
    {
        $a = MediaCollection::create(['name' => 'A', 'slug' => 'a']);
        $b = MediaCollection::create(['name' => 'B', 'slug' => 'b', 'parent_id' => $a->id]);
        $x = MediaCollection::create(['name' => 'X', 'slug' => 'x']);
        $p = Livewire::test(ListMediaAssets::class);

        $p->call('dropOn', 'folder:'.$a->id, $b->id); // vào chính con cháu: bị từ chối
        $this->assertNull($a->fresh()->parent_id);
        $p->call('moveFolder', $a->id, $a->id);
        $this->assertNull($a->fresh()->parent_id);

        $p->call('dropOn', 'folder:'.$x->id, $b->id); // hợp lệ: X vào B (kéo-thả)
        $this->assertSame($b->id, $x->fresh()->parent_id);
        $p->call('dropOn', 'folder:'.$x->id, null); // ra gốc
        $this->assertNull($x->fresh()->parent_id);

        $parent = null;
        for ($i = 1; $i <= MediaCollection::MAX_DEPTH; $i++) {
            $parent = MediaCollection::create(['name' => "L$i", 'slug' => "l$i", 'parent_id' => $parent?->id]);
        }
        $p->callAction('newFolder', ['name' => 'Quá sâu'], ['parent' => $parent->id]);
        $this->assertNull(MediaCollection::firstWhere('name', 'Quá sâu'));
    }

    public function test_tao_doi_ten_xoa_thu_muc_va_keo_tha_anh(): void
    {
        $p = Livewire::test(ListMediaAssets::class)->callAction('newFolder', ['name' => 'Ẩm thực'])->assertHasNoActionErrors();
        $f = MediaCollection::firstWhere('slug', 'am-thuc');
        $this->assertNotNull($f);

        $a = $this->asset('a');
        $p->call('moveAsset', $a->id, $f->id);
        $this->assertSame($f->id, $a->fresh()->folder_id);
        $p->call('moveAsset', $a->id, null);
        $this->assertNull($a->fresh()->folder_id);
        $p->call('moveAsset', $a->id, 9999); // thư mục không tồn tại: bỏ qua
        $this->assertNull($a->fresh()->folder_id);

        $p->callAction('renameFolder', ['name' => 'Món ăn'], ['id' => $f->id]);
        $p->callAction('moveFolder', ['parent' => null], ['id' => $f->id]);
        $this->assertSame('Món ăn', $f->fresh()->name);

        $sub = MediaCollection::create(['name' => 'Con', 'slug' => 'con', 'parent_id' => $f->id]);
        $p->call('moveAsset', $a->id, $f->id)->callAction('deleteFolder', [], ['id' => $f->id]);
        $this->assertNull(MediaCollection::find($f->id));
        $this->assertNull($a->fresh()->folder_id); // ảnh không bị xóa, lên thư mục cha (gốc)
        $this->assertNotNull(MediaAsset::find($a->id));
        $this->assertNull($sub->fresh()->parent_id); // thư mục con không mất, lên thư mục cha
    }

    public function test_sap_xep_theo_ten_va_dung_luong(): void
    {
        $b = $this->asset('b', ['title' => 'B', 'file_size' => 5]);
        $a = $this->asset('a', ['title' => 'A', 'file_size' => 900]);
        $p = Livewire::test(ListMediaAssets::class)->set('viewMode', 'list')->set('sortMode', 'name');
        $this->assertSame(['A', 'B'], $p->instance()->getTableRecords()->pluck('title')->all());
        $p->set('sortMode', 'size');
        $this->assertSame(['A', 'B'], $p->instance()->getTableRecords()->pluck('title')->all());
    }

    public function test_khung_xem_ben_phai_sua_chi_tiet_chuyen_anh_va_xoa(): void
    {
        $f = MediaCollection::create(['name' => 'F', 'slug' => 'f']);
        $a = $this->asset('a', ['title' => 'A', 'created_at' => now()->subMinutes(3)]);
        $b = $this->asset('b', ['title' => 'B', 'created_at' => now()->subMinutes(2)]);
        $c = $this->asset('c', ['title' => 'C', 'created_at' => now()->subMinutes(1)]);

        $p = Livewire::test(ListMediaAssets::class)->set('viewMode', 'list')->call('openViewer', $a->id)->assertSet('viewing', $a->id);
        $p->assertSet('edit.title', 'A')->assertSee('Lưu thay đổi');

        // Sửa ngay trong khung, không qua trang khác
        $p->set('edit.title', 'Tiêu đề mới')->set('edit.alt_text', 'Mô tả')->set('edit.kind', 'infographic')->set('edit.folder_id', $f->id)->set('edit.tags', 'sofa, Phòng khách')->call('saveViewer')->assertHasNoErrors();
        $a->refresh();
        $this->assertSame(['Tiêu đề mới', 'Mô tả', 'infographic', $f->id], [$a->title, $a->alt_text, $a->kind, $a->folder_id]);
        $this->assertSame(2, $a->tagModels()->count());

        // Dữ liệu sai bị chặn
        $p->set('edit.kind', 'khong-co')->call('saveViewer')->assertHasErrors('edit.kind');
        $p->set('edit.kind', 'photo');

        // Chuyển ảnh trước/sau theo danh sách đang xem (mới nhất trước: C, B, A), vòng tròn
        $p->set('folder', 'all')->call('openViewer', $c->id)->call('viewerStep', 1)->assertSet('viewing', $b->id)->call('viewerStep', 1)->assertSet('viewing', $a->id)
            ->call('viewerStep', 1)->assertSet('viewing', $c->id)->call('viewerStep', -1)->assertSet('viewing', $a->id);

        // Xóa ảnh chưa dùng: chuyển sang ảnh kế tiếp; đóng khung
        $p->call('openViewer', $b->id)->call('viewerDelete');
        $this->assertNull(MediaAsset::find($b->id));
        $p->assertNotSet('viewing', $b->id)->call('closeViewer')->assertSet('viewing', null);
    }

    public function test_tai_anh_len_vao_thu_muc_dang_mo_hoac_thu_muc_duoc_chon_tu_menu_chuot_phai(): void
    {
        $f = MediaCollection::create(['name' => 'Nội thất', 'slug' => 'noi-that']);
        $mk = function (int $r) {
            $p = tempnam(sys_get_temp_dir(), 'up').'.jpg';
            $im = imagecreatetruecolor(300, 200);
            imagefilledrectangle($im, 0, 0, 300, 200, imagecolorallocate($im, $r, 40, 90));
            imagejpeg($im, $p, 90);

            return UploadedFile::fake()->createWithContent("a$r.jpg", file_get_contents($p));
        };

        // Đang mở thư mục: hộp tải lên mặc định chọn đúng thư mục đó
        $p = Livewire::test(ListMediaAssets::class)->call('openFolder', (string) $f->id)->mountAction('upload')->assertActionDataSet(['folder_id' => $f->id, 'kind' => 'photo']);
        $p->setActionData(['uploads' => [$mk(10)], 'kind' => 'photo', 'folder_id' => $f->id])->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame($f->id, MediaAsset::first()->folder_id);

        // Chuột phải vào thư mục khác trong cây: tải thẳng vào đó, bất kể đang mở đâu
        $g = MediaCollection::create(['name' => 'Ẩm thực', 'slug' => 'am-thuc']);
        Livewire::test(ListMediaAssets::class)->mountAction('upload', ['folder' => $g->id])->assertActionDataSet(['folder_id' => $g->id]);
        // Ở gốc: không chọn thư mục
        Livewire::test(ListMediaAssets::class)->mountAction('upload')->assertActionDataSet(['folder_id' => null]);
    }
}
