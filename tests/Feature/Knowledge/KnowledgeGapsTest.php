<?php

namespace Tests\Feature\Knowledge;

use App\Filament\Pages\KnowledgePage;
use App\Models\Article;
use App\Models\KnowledgeGap;
use App\Models\KnowledgeItem;
use App\Models\User;
use App\Models\Website;
use App\Services\Knowledge\KnowledgeContext;
use App\Services\Knowledge\KnowledgeDedupe;
use App\Services\Knowledge\KnowledgeReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** AG-S21: vòng học khoảng trống kiến thức — ghi gap, liệt kê, mở form có sẵn tiêu đề, tự đánh dấu "đã bổ sung" khi duyệt mục khớp. */
class KnowledgeGapsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'approver'): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function site(): Website
    {
        return Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://'.uniqid().'.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function gap(Website $w, string $q = 'học bổng toàn phần', int $count = 2): KnowledgeGap
    {
        $a = Article::create(['website_id' => $w->id, 'primary_keyword' => $q, 'workflow_status' => 'planned']);

        return KnowledgeGap::create(['website_id' => $w->id, 'query' => $q, 'article_id' => $a->id, 'count' => $count]);
    }

    private function proposed(array $a = []): KnowledgeItem
    {
        $a += ['type' => 'fact', 'title' => 'Điều kiện học bổng toàn phần', 'body' => 'Học bổng toàn phần miễn 100% học phí cho sinh viên xuất sắc.', 'status' => 'proposed', 'version' => 1, 'created_by' => 'user'];
        $a['content_hash'] = KnowledgeDedupe::hash($a['title'], $a['body']);

        return KnowledgeItem::create($a);
    }

    public function test_viet_bai_khong_ra_thi_ghi_gap_va_mo_lai_gap_da_bo_sung(): void
    {
        $w = $this->site();
        $art = Article::create(['website_id' => $w->id, 'primary_keyword' => 'chính sách bảo hành', 'workflow_status' => 'planned']);
        $ctx = new KnowledgeContext;
        $ctx->load($art->fresh(), ['Tổng quan']);
        $gap = KnowledgeGap::first();
        $this->assertSame('open', $gap->status);
        $gap->update(['status' => 'filled', 'filled_at' => now(), 'filled_item_id' => 1]);
        (new KnowledgeContext)->load($art->fresh(), ['Tổng quan']);
        $gap->refresh();
        $this->assertSame('open', $gap->status, 'tìm vẫn không ra → mở lại');
        $this->assertSame(2, $gap->count);
    }

    public function test_duyet_muc_khop_thi_gap_da_bo_sung_con_muc_khong_khop_thi_khong(): void
    {
        $w = $this->site();
        $other = $this->site();
        $gap = $this->gap($w);
        $gapOther = $this->gap($other, 'học bổng toàn phần');
        $gapUnrelated = $this->gap($w, 'chính sách bảo hành sản phẩm');
        $review = new KnowledgeReview;
        $by = $this->user();

        $review->approve($this->proposed(['website_id' => $w->id, 'title' => 'Giá vé', 'body' => 'Giá vé vào cổng là năm mươi nghìn đồng.']), $by);
        $this->assertSame('open', $gap->fresh()->status);

        $item = $this->proposed(['website_id' => $w->id]);
        $this->assertSame('open', $gap->fresh()->status, 'chỉ mục ĐÃ DUYỆT mới lấp gap');
        $review->approve($item, $by);
        $this->assertSame('filled', $gap->fresh()->status);
        $this->assertSame($item->id, $gap->fresh()->filled_item_id);
        $this->assertSame('open', $gapOther->fresh()->status, 'mục của website khác không lấp gap này');
        $this->assertSame('open', $gapUnrelated->fresh()->status);
    }

    public function test_muc_chung_lap_gap_moi_website(): void
    {
        $w = $this->site();
        $gap = $this->gap($w);
        (new KnowledgeReview)->approve($this->proposed(['website_id' => null]), $this->user());
        $this->assertSame('filled', $gap->fresh()->status);
    }

    public function test_man_hinh_liet_ke_gap_va_nut_them_kien_thuc_dien_san_tieu_de(): void
    {
        $w = $this->site();
        $gap = $this->gap($w, 'chính sách hoàn tiền', 3);
        $this->actingAs($this->user());
        $c = Livewire::test(KnowledgePage::class)->assertOk()->assertSee('Khoảng trống')->assertSee('chính sách hoàn tiền')->assertSee('Còn thiếu')->assertSee('Thêm kiến thức');
        $c->call('prefillFromGap', $gap->id)->assertSet('new.title', 'chính sách hoàn tiền')->assertSet('new.website_id', (string) $w->id);

        $gap->update(['status' => 'filled']);
        Livewire::test(KnowledgePage::class)->assertSee('Đã bổ sung');
    }

    public function test_quyen_gap(): void
    {
        $gap = $this->gap($this->site());
        $this->actingAs($this->user('writer'));
        Livewire::test(KnowledgePage::class)->assertOk()->assertDontSee('Khoảng trống')->call('prefillFromGap', $gap->id)->assertForbidden();
    }

    public function test_nguon_va_them_kien_thuc_gop_thanh_mot_tab_tai_lieu(): void
    {
        $w = $this->site();
        $g = $this->gap($w);
        $this->actingAs($this->user());

        $html = Livewire::test(KnowledgePage::class)->assertOk()->html();
        preg_match_all('/role="tab"[^>]*data-key="([^"]+)"/', $html, $m);
        $this->assertContains('sources', $m[1]);
        $this->assertNotContains('add', $m[1]);
        $this->assertStringContainsString('<span>Tài liệu</span>', $html);
        $this->assertStringNotContainsString('<span>Nguồn</span>', $html);
        $this->assertStringNotContainsString('<span>Thêm kiến thức</span>', $html);

        // Nút ở tab Khoảng trống điền sẵn tiêu đề vào form nằm trong tab Tài liệu.
        Livewire::test(KnowledgePage::class)->call('prefillFromGap', $g->id)->assertSet('new.title', 'học bổng toàn phần')
            ->assertSeeHtml('x-on:kb-add.window');
    }
}
