<?php

namespace Tests\Feature\Knowledge;

use App\Models\KnowledgeGap;
use App\Models\KnowledgeItem;
use App\Models\Website;
use App\Services\Knowledge\KnowledgeRetriever;
use Database\Seeders\AddpSampleKnowledgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Kho mẫu ADDP phải trung thực: chỉ đề xuất, không bịa, AI không dùng được cho tới khi có người duyệt. */
class AddpSampleSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_tao_kho_mau_o_trang_thai_de_xuat_va_website_chua_ket_noi(): void
    {
        $this->seed(AddpSampleKnowledgeSeeder::class);

        $site = Website::where('base_url', 'https://duocaddp.com')->firstOrFail();
        $this->assertSame('pending', $site->status, 'website mẫu chưa kết nối nên chiến dịch không giao việc');
        $this->assertGreaterThanOrEqual(8, KnowledgeItem::where('website_id', $site->id)->count());
        $this->assertSame(0, KnowledgeItem::where('website_id', $site->id)->where('status', '!=', 'proposed')->count(), 'không mục nào được tự duyệt');
        foreach (KnowledgeItem::where('website_id', $site->id)->get() as $i) {
            $this->assertContains('mau', $i->tags);
            $this->assertSame('sample', $i->created_by);
            $this->assertNotSame('', trim((string) $i->source_span), "{$i->title} thiếu nguồn");
        }
    }

    public function test_ai_khong_truy_xuat_duoc_muc_mau_chua_duyet(): void
    {
        $this->seed(AddpSampleKnowledgeSeeder::class);
        $site = Website::where('base_url', 'https://duocaddp.com')->firstOrFail();

        $this->assertCount(0, (new KnowledgeRetriever())->search($site, 'công ty dược ADDP quảng cáo thuốc', 5));
    }

    public function test_muc_phap_ly_ghi_ro_chua_doi_chieu_van_ban_va_do_tin_cay_thap(): void
    {
        $this->seed(AddpSampleKnowledgeSeeder::class);

        $legal = KnowledgeItem::where('title', 'Không quảng cáo thuốc kê đơn cho công chúng')->firstOrFail();
        $this->assertStringContainsString('CHƯA đối chiếu văn bản gốc', $legal->body);
        $this->assertLessThanOrEqual(50, $legal->confidence);
        $this->assertContains('can-xac-nhan', $legal->tags);
    }

    public function test_khoang_trong_cho_phan_chua_co_thong_tin_that_va_khong_co_san_pham_gia(): void
    {
        $this->seed(AddpSampleKnowledgeSeeder::class);

        $gaps = KnowledgeGap::pluck('query')->all();
        $this->assertContains('Danh mục sản phẩm và công dụng được phép nêu', $gaps);
        $this->assertContains('Bảng giá và chương trình khuyến mãi hiện hành', $gaps);
        $this->assertSame(0, KnowledgeItem::where('type', 'price')->count(), 'không bịa giá');
    }

    public function test_chay_lai_khong_tao_trung(): void
    {
        $this->seed(AddpSampleKnowledgeSeeder::class);
        [$items, $gaps, $sites] = [KnowledgeItem::count(), KnowledgeGap::count(), Website::count()];
        $this->seed(AddpSampleKnowledgeSeeder::class);

        $this->assertSame([$items, $gaps, $sites], [KnowledgeItem::count(), KnowledgeGap::count(), Website::count()]);
    }
}
