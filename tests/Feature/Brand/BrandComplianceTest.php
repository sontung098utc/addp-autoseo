<?php

namespace Tests\Feature\Brand;

use App\Filament\Resources\ArticleVersionResource;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\BrandProfile;
use App\Models\User;
use App\Models\Website;
use App\Services\Brand\BrandCompliance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandComplianceTest extends TestCase
{
    use RefreshDatabase;

    private function version(string $html, ?array $profile = null): ArticleVersion
    {
        $w = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://'.uniqid().'.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        if ($profile) {
            BrandProfile::create(['website_id' => $w->id] + $profile);
        }
        $a = Article::create(['website_id' => $w->id, 'primary_keyword' => 'ăn uống lành mạnh', 'workflow_status' => 'needs_review']);

        return ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 'T', 'slug' => 't', 'html_content' => $html, 'review_status' => 'needs_review']);
    }

    public function test_thong_diep_co_hoac_chua_va_tu_cam(): void
    {
        $v = $this->version('<h2>Ăn uống</h2><p>Chúng tôi cam kết sản phẩm an toàn cho gia đình bạn. Hiệu quả nhanh chóng.</p>', [
            'business_field' => 'Thực phẩm', 'audience' => 'Gia đình', 'key_messages' => ['Sản phẩm an toàn cho gia đình', 'Giao hàng trong 24 giờ'], 'forbidden_terms' => ['hiệu quả nhanh chóng', 'chữa khỏi'],
        ]);
        $r = BrandCompliance::forVersion($v);

        $this->assertTrue($r['brand']['messages'][0]['covered']);
        $this->assertFalse($r['brand']['messages'][1]['covered']);
        $this->assertSame(['hiệu quả nhanh chóng'], $r['brand']['forbidden_hits']);
        $this->assertStringContainsString('từ cấm', mb_strtolower(implode(' ', $r['notes'])));
    }

    public function test_khong_co_ho_so_thi_noi_that(): void
    {
        $r = BrandCompliance::forVersion($this->version('<p>abc</p>'));

        $this->assertSame(0, $r['brand']['profile_percent']);
        $this->assertStringContainsString('chưa có hồ sơ doanh nghiệp', $r['notes'][0]);
        $this->assertSame([], $r['domain']['brains']);
    }

    public function test_trang_phien_ban_hien_bang(): void
    {
        $v = $this->version('<p>nội dung</p>', ['business_field' => 'X', 'key_messages' => ['Thông điệp riêng biệt']]);
        $this->actingAs(User::create(['name' => 'a', 'email' => 'a@x.vn', 'password' => 'secret123', 'role' => 'approver', 'is_active' => true]));

        $this->get(ArticleVersionResource::getUrl('view', ['record' => $v]))->assertOk()
            ->assertSee('Bản sắc thương hiệu và quy tắc lĩnh vực')->assertSee('Chưa thấy: Thông điệp riêng biệt')->assertSee('Không có');
    }
}
