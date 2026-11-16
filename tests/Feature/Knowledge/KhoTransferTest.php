<?php

namespace Tests\Feature\Knowledge;

use App\Models\Brain;
use App\Models\User;
use App\Models\Website;
use App\Services\Knowledge\KhoTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KhoTransferTest extends TestCase
{
    use RefreshDatabase;

    private function seedSource(): array
    {
        $uid = User::create(['name' => 'a', 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => 'admin', 'is_active' => true])->id;
        $site = Website::create(['name' => 'A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['pw' => 'BI-MAT'], 'status' => 'active']);
        $brain = Brain::create(['name' => 'Đái tháo đường', 'slug' => 'dd', 'domain_key' => 'dai_thao_duong', 'status' => 'active']);
        DB::table('brain_website')->insert(['brain_id' => $brain->id, 'website_id' => $site->id, 'priority' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('brain_sources')->insert(['brain_id' => $brain->id, 'name' => 'WHO', 'domain' => 'who.int', 'status' => 'trusted', 'created_at' => now(), 'updated_at' => now()]);
        $parent = DB::table('knowledge_topics')->insertGetId(['website_id' => $site->id, 'brain_id' => $brain->id, 'name' => 'Cha', 'created_at' => now(), 'updated_at' => now()]);
        $child = DB::table('knowledge_topics')->insertGetId(['website_id' => $site->id, 'brain_id' => $brain->id, 'parent_id' => $parent, 'name' => 'Con', 'created_at' => now(), 'updated_at' => now()]);
        $src = DB::table('knowledge_sources')->insertGetId(['website_id' => $site->id, 'brain_id' => $brain->id, 'kind' => 'url', 'title' => 'WHO', 'status' => 'ready', 'created_at' => now(), 'updated_at' => now()]);
        $item = DB::table('knowledge_items')->insertGetId(['website_id' => $site->id, 'brain_id' => $brain->id, 'source_id' => $src, 'topic_id' => $child, 'type' => 'fact', 'title' => 'Mốc đường huyết', 'body' => 'Đói trên 125', 'status' => 'approved', 'version' => 1, 'approved_by' => $uid, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('knowledge_item_versions')->insert(['item_id' => $item, 'version' => 1, 'title' => 'Mốc đường huyết', 'body' => 'Đói trên 125', 'status' => 'approved', 'changed_by' => $uid, 'created_at' => now(), 'updated_at' => now()]);

        return [$site, $brain];
    }

    public function test_xuat_khong_mang_bi_mat_va_nhap_chi_them_noi_dung(): void
    {
        [$site] = $this->seedSource();
        $data = KhoTransfer::export([$site->id]);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('BI-MAT', $json, 'Không được mang thông tin kết nối website.');
        $this->assertStringNotContainsString('encrypted_credentials', $json);
        $this->assertCount(1, $data['brains']);

        // Máy đích: đã có website cùng địa chỉ (id khác) và người dùng riêng; nhập vào DB trống kho.
        DB::table('knowledge_items')->delete();
        DB::table('knowledge_item_versions')->delete();
        DB::table('knowledge_sources')->delete();
        DB::table('knowledge_topics')->delete();
        DB::table('brain_sources')->delete();
        DB::table('brain_website')->delete();
        DB::table('brains')->delete();
        $target = Website::create(['name' => 'Đích', 'cms_type' => 'wordpress', 'base_url' => 'https://www.a.vn/', 'encrypted_credentials' => ['pw' => 'GIU'], 'status' => 'active']);

        $dry = KhoTransfer::import($data, [], true);
        $this->assertSame(1, $dry['brains_added']);
        $this->assertSame(0, DB::table('brains')->count(), 'Chạy thử không ghi gì.');

        $r = KhoTransfer::import($data);
        $this->assertSame(['brains_added' => 1, 'items' => 1, 'sources' => 1, 'topics' => 2, 'versions' => 1, 'attached' => 1], array_intersect_key($r, array_flip(['brains_added', 'items', 'sources', 'topics', 'versions', 'attached'])));
        $brain = DB::table('brains')->first();
        $item = DB::table('knowledge_items')->first();
        $this->assertSame($brain->id, $item->brain_id);
        $this->assertSame($target->id, $item->website_id, 'Website nối theo địa chỉ gốc, không theo id cũ.');
        $this->assertNull($item->approved_by, 'Không mang id người dùng của máy khác.');
        $this->assertSame('approved', $item->status, 'Giữ trạng thái duyệt.');
        $child = DB::table('knowledge_topics')->where('name', 'Con')->first();
        $this->assertSame(DB::table('knowledge_topics')->where('name', 'Cha')->value('id'), $child->parent_id, 'Chủ đề con nối lại đúng cha.');
        $this->assertSame($item->id, DB::table('knowledge_item_versions')->value('item_id'));
        $this->assertSame(1, DB::table('brain_website')->where(['brain_id' => $brain->id, 'website_id' => $target->id])->count());
        $this->assertSame('GIU', $target->fresh()->encrypted_credentials['pw'], 'Không đụng kết nối website có sẵn.');
    }

    public function test_nhap_lai_khong_nhan_doi_va_khong_de(): void
    {
        [$site] = $this->seedSource();
        $data = KhoTransfer::export([$site->id]);
        DB::table('brains')->where('slug', 'dd')->update(['description' => 'Đã sửa trên server']);
        $r = KhoTransfer::import($data);
        $this->assertSame(0, $r['brains_added']);
        $this->assertSame(['Đái tháo đường'], $r['brains_skipped']);
        $this->assertSame(1, DB::table('knowledge_items')->count());
        $this->assertSame('Đã sửa trên server', DB::table('brains')->value('description'), 'Không đè bản có sẵn.');
    }

    public function test_website_khong_khop_thi_nhap_nhung_chua_gan(): void
    {
        [$site] = $this->seedSource();
        $data = KhoTransfer::export([$site->id]);
        DB::table('knowledge_items')->delete();
        DB::table('knowledge_item_versions')->delete();
        DB::table('knowledge_sources')->delete();
        DB::table('knowledge_topics')->delete();
        DB::table('brain_sources')->delete();
        DB::table('brain_website')->delete();
        DB::table('brains')->delete();
        $site->delete();
        $r = KhoTransfer::import($data);
        $this->assertSame(1, $r['brains_added']);
        $this->assertSame(0, $r['attached']);
        $this->assertSame(['https://a.vn'], $r['unmatched_sites']);
        $this->assertNull(DB::table('knowledge_items')->value('website_id'));
    }
}
