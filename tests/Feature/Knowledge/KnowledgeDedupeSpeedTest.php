<?php

namespace Tests\Feature\Knowledge;

use App\Models\KnowledgeItem;
use App\Services\Knowledge\KnowledgeDedupe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Trang "Tất cả tri thức" từng quá 90 giây trên server vì mỗi lần so lại tải hết mục và chạy similar_text với mọi cặp: bản nhanh phải cho CÙNG kết quả. */
class KnowledgeDedupeSpeedTest extends TestCase
{
    use RefreshDatabase;

    private function item(string $body, string $title = 'T'): KnowledgeItem
    {
        return KnowledgeItem::create(['type' => 'fact', 'title' => $title, 'body' => $body, 'status' => 'approved', 'version' => 1, 'content_hash' => KnowledgeDedupe::hash($title, $body)]);
    }

    public function test_tim_dung_muc_gan_giong_va_trung_y_het_va_bo_qua_muc_khac_han(): void
    {
        $base = 'HbA1c từ 6,5% trở lên ở hai lần xét nghiệm khác nhau cho phép chẩn đoán đái tháo đường theo hướng dẫn hiện hành của nhiều tổ chức y tế.';
        $a = $this->item($base);
        $near = $this->item($base.' Cần xác nhận lại.');
        $same = $this->item($base);
        $far = $this->item('Quy trình dập viên nén gồm cấp liệu, nén, đẩy viên ra và kiểm tra độ cứng bằng thiết bị đo chuyên dụng ở dây chuyền.');
        $short = $this->item('Ngắn.');

        foreach ([new KnowledgeDedupe, (new KnowledgeDedupe)->withScopeCache()] as $d) {
            $r = $d->find($a);
            $this->assertSame([$same->id], $r['exact']->pluck('id')->all());
            $this->assertSame([$near->id], $r['similar']->pluck('item.id')->all(), 'Gần giống thì có, khác hẳn và quá ngắn thì không.');
            $this->assertGreaterThanOrEqual(85.0, $r['similar'][0]['percent']);
            $this->assertEqualsWithDelta($d->similarity($base, $near->body), $r['similar'][0]['percent'], 0.001, 'Phần trăm giống phép tính gốc.');
        }
        $this->assertNotContains($far->id, (new KnowledgeDedupe)->find($a)['similar']->pluck('item.id')->all());
        $this->assertNotContains($short->id, (new KnowledgeDedupe)->find($a)['similar']->pluck('item.id')->all());
    }

    public function test_bo_nho_phuong_cap_chi_tai_mot_lan_cho_nhieu_lan_so(): void
    {
        $items = collect(range(1, 6))->map(fn ($n) => $this->item('Nội dung số '.$n.' '.str_repeat('từ khác nhau '.$n.' ', 6)));
        $d = (new KnowledgeDedupe)->withScopeCache();
        \DB::enableQueryLog();
        foreach ($items as $i) {
            $d->find($i);
        }
        $selects = collect(\DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'knowledge_items'))->count();
        $this->assertSame(1, $selects, 'Cùng phạm vi chỉ tải mục một lần.');
    }

    public function test_canh_bao_trung_duoc_nho_va_tu_lam_moi_khi_kho_doi(): void
    {
        $this->actingAs(\App\Models\User::create(['name' => 'a', 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => 'approver', 'is_active' => true]));
        $base = 'HbA1c từ 6,5% trở lên ở hai lần xét nghiệm khác nhau cho phép chẩn đoán đái tháo đường theo hướng dẫn hiện hành của nhiều tổ chức y tế.';
        $a = $this->item($base, 'Mục A');
        $page = fn () => \Livewire\Livewire::test(\App\Filament\Pages\KnowledgePage::class);
        $page()->assertOk()->assertDontSee('≈ KB#');

        $b = $this->item($base.' Cần xác nhận lại.', 'Mục B');
        $page()->assertSee('KB#'.$a->id)->assertSee('≈ KB#'.$b->id, false); // kho đổi: dấu vân tay đổi nên tính lại, hiện cặp mới

        $queries = 0;
        \DB::listen(function ($q) use (&$queries) { if (str_contains($q->sql, 'content_hash') || str_contains($q->sql, 'from `knowledge_items` where `status` in')) { $queries++; } });
        $page()->assertSee('≈ KB#'.$b->id, false); // kho không đổi: dùng kết quả đã nhớ
        $this->assertLessThan(8, $queries, 'Lần hiển thị thứ hai không tính lại toàn bộ cảnh báo.');

        $b->update(['status' => 'rejected']);
        $page()->assertDontSee('≈ KB#'.$b->id, false); // bị từ chối: tự bỏ cặp cũ
    }
}
