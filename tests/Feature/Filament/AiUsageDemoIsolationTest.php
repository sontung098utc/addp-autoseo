<?php

namespace Tests\Feature\Filament;

use App\Filament\Support\Demo\AiUsageDemoData;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lỗi đã gặp: dữ liệu mẫu của màn Chi phí AI dùng chung tiền tố '[Mẫu] ' với các lớp mẫu khác nên seed() bị bỏ qua
 * khi các màn khác đã có mẫu, và purge() có thể xóa nhầm dữ liệu mẫu của màn khác.
 */
class AiUsageDemoIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_van_chay_khi_co_du_lieu_mau_cua_man_khac_va_purge_khong_xoa_nham(): void
    {
        $site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $other = Article::create(['website_id' => $site->id, 'primary_keyword' => '[Mẫu] bài của màn khác', 'workflow_status' => 'needs_review']);
        ArticleVersion::create(['article_id' => $other->id, 'version' => 1, 'title' => 't', 'slug' => 's', 'html_content' => '<p>x</p>', 'review_status' => 'needs_review']);

        $this->assertFalse(AiUsageDemoData::hasSample());
        $this->assertGreaterThan(0, AiUsageDemoData::seed());
        $this->assertTrue(AiUsageDemoData::hasSample());

        AiUsageDemoData::purge();

        $this->assertFalse(AiUsageDemoData::hasSample());
        $this->assertNotNull(Article::find($other->id), 'purge() không được xóa dữ liệu mẫu của màn khác');
        $this->assertSame(1, ArticleVersion::where('article_id', $other->id)->count());
    }

    public function test_mau_chi_phi_ai_khong_lam_phinh_hang_cho_duyet(): void
    {
        $site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);

        AiUsageDemoData::seed();

        $this->assertSame(0, ArticleVersion::where('review_status', 'needs_review')->count(), 'mẫu Chi phí AI không được tạo bản chờ duyệt');
        $this->assertSame(0, Article::where('workflow_status', 'needs_review')->count());
        $this->assertGreaterThan(0, ArticleVersion::count());
    }
}
