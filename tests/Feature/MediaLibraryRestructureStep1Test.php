<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\MediaCollection;
use App\Models\MediaTag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MediaLibraryRestructureStep1Test extends TestCase
{
    use RefreshDatabase;

    private function asset(array $o = []): MediaAsset
    {
        return MediaAsset::create($o + [
            'disk' => 'public', 'file_path' => 'media/2026/11/a.webp', 'file_name' => 'a.webp', 'original_name' => 'a.jpg',
            'mime_type' => 'image/webp', 'file_size' => 10, 'sha256' => str_repeat('a', 64),
        ]);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_21_100000_extend_media_assets_with_kind_status_folders_tags.php');
    }

    public function test_asset_cu_giu_nguyen_hanh_vi_voi_gia_tri_mac_dinh(): void
    {
        $a = $this->asset(['tags' => ['x'], 'is_featured' => true])->fresh();
        $this->assertSame('photo', $a->kind);
        $this->assertSame('ready', $a->status);
        $this->assertNull($a->folder_id);
        $this->assertSame(0, $a->usage_count);
        $this->assertSame(['x'], $a->tags);
        $this->assertSame('media/2026/11/a.webp', $a->file_path);
    }

    public function test_backfill_tags_json_usage_va_kind_khong_doi_file_path_va_chay_lai_an_toan(): void
    {
        $a = $this->asset(['tags' => ['Nội thất', 'noi that', '', 'Sofa'], 'metadata' => ['ai_generated' => true]]);
        $b = $this->asset(['sha256' => str_repeat('b', 64), 'file_path' => 'media/b.webp', 'tags' => ['Sofa']]);
        DB::table('media_assets')->where('id', $b->id)->update(['tags' => 'khong-phai-json']);
        $c = $this->asset(['sha256' => str_repeat('c', 64), 'file_path' => 'media/c.webp']);

        $this->migration()->backfill();
        $this->migration()->backfill();

        $this->assertSame('ai_generated', $a->fresh()->kind);
        $this->assertSame('photo', $c->fresh()->kind);
        $this->assertSame('media/2026/11/a.webp', $a->fresh()->file_path);
        $this->assertEqualsCanonicalizing(['noi-that', 'sofa'], MediaTag::pluck('slug')->all());
        $this->assertSame(2, $a->tagModels()->count());
        $this->assertSame(0, $b->tagModels()->count()); // JSON hỏng: bỏ qua, không lỗi
        $this->assertSame(['Nội thất', 'noi that', '', 'Sofa'], $a->fresh()->tags); // cột cũ không bị đụng
    }

    public function test_usage_count_theo_so_phien_ban_gan_anh(): void
    {
        $user = \App\Models\User::create(['name' => 'U', 'email' => 'u@example.com', 'password' => bcrypt('secret123'), 'role' => 'admin']);
        $site = \App\Models\Website::create(['name' => 'S', 'cms_type' => 'magento2', 'base_url' => 'https://m.example.com', 'store_scope' => 'default', 'status' => 'active', 'encrypted_credentials' => ['consumer_key' => 't']]);
        $article = \App\Models\Article::create(['website_id' => $site->id, 'created_by' => $user->id, 'primary_keyword' => 'k', 'search_intent' => 'commercial', 'topic_cluster' => 'c', 'workflow_status' => 'needs_review']);
        $v = fn (int $n) => \App\Models\ArticleVersion::create(['article_id' => $article->id, 'version' => $n, 'title' => 't', 'slug' => 's'.$n, 'focus_keyword' => 'k', 'html_content' => '<p>x</p>', 'review_status' => 'needs_review']);
        $a = $this->asset();
        $a->articleVersions()->attach([$v(1)->id, $v(2)->id]);

        $this->migration()->backfill();

        $this->assertSame(2, $a->fresh()->usage_count);
    }

    public function test_thu_muc_va_bo_suu_tap_phu(): void
    {
        $root = MediaCollection::create(['name' => 'Nội thất', 'slug' => 'noi-that']);
        $child = MediaCollection::create(['name' => 'Sofa', 'slug' => 'sofa', 'parent_id' => $root->id]);
        $a = $this->asset(['folder_id' => $child->id]);
        $a->collections()->attach($root->id);

        $this->assertSame($child->id, $a->folder->id);
        $this->assertSame($root->id, $child->parent->id);
        $this->assertSame(1, $root->extraAssets()->count());
        $child->delete(); // xóa thư mục không xóa ảnh
        $this->assertNull($a->fresh()->folder_id);
        $this->assertNotNull(MediaAsset::find($a->id));
    }

    public function test_down_xoa_dung_phan_bo_sung_va_up_lai_duoc(): void
    {
        $this->asset();
        $m = $this->migration();
        $m->down();
        $this->assertFalse(Schema::hasTable('media_tags'));
        $this->assertFalse(Schema::hasColumn('media_assets', 'kind'));
        $this->assertTrue(Schema::hasColumn('media_assets', 'tags'));
        $this->assertSame(1, MediaAsset::count());
        $m->up();
        $this->assertTrue(Schema::hasColumn('media_assets', 'kind'));
    }
}
