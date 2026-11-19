<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ArticleResource\Pages\CreateArticle;
use App\Filament\Support\Planning\DuplicateKeywordException;
use App\Filament\Support\Planning\KeywordDuplicateGuard;
use App\Filament\Support\Planning\PlannedArticleCreator;
use App\Models\Article;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/** #47: tạo bài lẻ phải chặn trùng từ khóa chính trên cùng website, cùng luật với nhập file/chiến dịch. */
class ArticleKeywordDuplicateTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Website
    {
        return Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://'.uniqid().'.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function writer(): User
    {
        return User::create(['name' => 'w', 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => 'writer', 'is_active' => true]);
    }

    private function submit(Website $site, string $kw, array $extra = [])
    {
        return Livewire::test(CreateArticle::class)
            ->fillForm(['website_id' => $site->id, 'primary_keyword' => $kw, 'search_intent' => 'informational'] + $extra)
            ->call('create');
    }

    private function n(Website $site): int
    {
        return Article::where('website_id', $site->id)->count();
    }

    public function test_trung_khac_hoa_thuong_bi_chan(): void
    {
        $this->actingAs($this->writer());
        $site = $this->site();
        $this->submit($site, 'KB-TEST cách pha trà xanh');
        $this->assertSame(1, $this->n($site));

        $this->submit($site, 'kb-test Cách Pha Trà Xanh')->assertNotified('Từ khóa này đã có bài');
        $this->assertSame(1, $this->n($site));
    }

    public function test_trung_khac_khoang_trang_bi_chan(): void
    {
        $this->actingAs($this->writer());
        $site = $this->site();
        $this->submit($site, 'pha trà xanh');
        $this->submit($site, "  pha   trà\u{00A0} xanh ");
        $this->assertSame(1, $this->n($site));
    }

    public function test_unicode_to_hop_va_dung_san_la_mot(): void
    {
        $site = $this->site();
        (new PlannedArticleCreator)->create($this->writer(), $site, "tra\u{0301} xanh"); // a + dấu sắc tổ hợp
        $this->expectException(DuplicateKeywordException::class);
        (new PlannedArticleCreator)->create($this->writer(), $site, "tr\u{00E1} xanh");
    }

    public function test_khac_website_duoc_phep(): void
    {
        $this->actingAs($this->writer());
        $a = $this->site();
        $b = $this->site();
        $this->submit($a, 'cùng từ khóa');
        $this->submit($b, 'Cùng Từ Khóa');
        $this->assertSame(1, $this->n($a));
        $this->assertSame(1, $this->n($b));
    }

    public function test_canh_bao_hien_tai_o_tu_khoa_chinh_co_ten_trang_thai_va_lien_ket(): void
    {
        $this->actingAs($this->writer());
        $site = $this->site();
        $old = Article::create(['website_id' => $site->id, 'primary_keyword' => 'trà xanh', 'workflow_status' => 'needs_review']);

        $html = (string) \App\Filament\Resources\ArticleResource::duplicateNotice($site->id, 'TRÀ  Xanh');
        $this->assertStringContainsString('#'.$old->id, $html);
        $this->assertStringContainsString('Chờ duyệt', $html);
        $this->assertStringContainsString('Mở bài đó', $html);
        $this->assertStringContainsString('/articles/'.$old->id, $html);
        $this->assertNull(\App\Filament\Resources\ArticleResource::duplicateNotice($site->id, 'từ khóa khác'));
        $this->assertNull(\App\Filament\Resources\ArticleResource::duplicateNotice(null, 'trà xanh'));
    }

    public function test_bai_cu_bi_tra_ve_cho_tao_lai_khi_co_xac_nhan(): void
    {
        $this->actingAs($this->writer());
        $site = $this->site();
        Article::create(['website_id' => $site->id, 'primary_keyword' => 'trà xanh', 'workflow_status' => 'rejected']);

        $this->submit($site, 'Trà Xanh')->assertNotified('Từ khóa này đã có bài');
        $this->assertSame(1, $this->n($site), 'chưa xác nhận thì không tạo');

        $this->submit($site, 'Trà Xanh', ['confirm_reopen' => true]);
        $this->assertSame(2, $this->n($site));
    }

    public function test_bai_dang_cho_duyet_khong_tao_lai_duoc_du_co_xac_nhan(): void
    {
        $this->actingAs($this->writer());
        $site = $this->site();
        Article::create(['website_id' => $site->id, 'primary_keyword' => 'trà xanh', 'workflow_status' => 'published']);

        $this->submit($site, 'trà xanh', ['confirm_reopen' => true]);
        $this->assertSame(1, $this->n($site));
    }

    public function test_hai_lan_gui_lien_tiep_chi_tao_mot_bai(): void
    {
        $this->actingAs($this->writer());
        $site = $this->site();
        $this->submit($site, 'gửi hai lần');
        $this->submit($site, 'gửi hai lần');
        $this->assertSame(1, $this->n($site));
    }

    public function test_khoa_dang_bi_giu_van_khong_tao_trung_nho_kiem_lai_trong_giao_dich(): void
    {
        $site = $this->site();
        $lock = Cache::lock('kwdup:'.$site->id.':'.md5(KeywordDuplicateGuard::normalize('Giữ khóa')), 30);
        $this->assertTrue($lock->get());
        // Khóa bị giữ quá thời gian chờ: bỏ qua khóa nhưng vẫn kiểm lại trong giao dịch, không ném lỗi khóa.
        $c = new PlannedArticleCreator;
        $u = $this->writer();
        $c->create($u, $site, 'Giữ khóa');
        try {
            $c->create($u, $site, 'giữ KHÓA');
            $this->fail('Phải chặn trùng');
        } catch (DuplicateKeywordException) {
        }
        $this->assertSame(1, $this->n($site));
        $lock->release();
    }

    public function test_cache_hong_khong_lam_hong_viec_tao_bai(): void
    {
        $site = $this->site();
        Cache::shouldReceive('lock')->andThrow(new \RuntimeException('cache down'));
        $a = (new PlannedArticleCreator)->create($this->writer(), $site, 'không khóa');
        $this->assertSame('không khóa', $a->primary_keyword);
    }

    public function test_hoi_quy_planned_article_creator_van_chan_ca_bai_da_tu_choi(): void
    {
        $site = $this->site();
        $old = Article::create(['website_id' => $site->id, 'primary_keyword' => 'Học bổng', 'workflow_status' => 'rejected']);
        try {
            (new PlannedArticleCreator)->create($this->writer(), $site, 'học  bổng');
            $this->fail('Phải chặn');
        } catch (DuplicateKeywordException $e) {
            $this->assertSame($old->id, $e->existingArticleId);
        }
        $this->assertSame('học bổng', PlannedArticleCreator::normalize('  Học   BỔNG '));
        $this->assertSame($old->id, PlannedArticleCreator::existing($site, 'HỌC BỔNG')->id);
    }
}
