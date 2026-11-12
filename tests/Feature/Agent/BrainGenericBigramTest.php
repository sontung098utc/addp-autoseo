<?php

namespace Tests\Feature\Agent;

use App\Models\Article;
use App\Models\Brain;
use App\Models\Website;
use App\Services\Agent\Readiness\BrainAutoAttach;
use App\Services\Agent\Readiness\BrainSuggester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Cụm chung chung ("hướng dẫn", "chăm sóc"...) không được tự gắn Trí tuệ y tế vào bài phần mềm/marketing (và từ đó nâng hạng rủi ro). */
class BrainGenericBigramTest extends TestCase
{
    use RefreshDatabase;

    private function brains(): void
    {
        Brain::create(['name' => 'Dược và sử dụng thuốc an toàn', 'slug' => 'duoc', 'domain_key' => 'duoc', 'language' => 'vi', 'status' => 'active',
            'description' => 'Thông tin về thuốc; hướng dẫn dùng thuốc, liều và chỉ định do người có chuyên môn quyết định']);
        Brain::create(['name' => 'Sức khỏe trẻ em', 'slug' => 'tre_em', 'domain_key' => 'tre_em', 'language' => 'vi', 'status' => 'active',
            'description' => 'Chăm sóc trẻ nhỏ, hướng dẫn cho phụ huynh']);
        Brain::create(['name' => 'Da liễu và chăm sóc da', 'slug' => 'da', 'domain_key' => 'da', 'language' => 'vi', 'status' => 'active', 'description' => '']);
    }

    private function article(string $kw): Article
    {
        $w = Website::query()->first() ?? Website::create(['name' => 'Phần mềm', 'cms_type' => 'wordpress', 'base_url' => 'https://pm.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);

        return Article::create(['website_id' => $w->id, 'primary_keyword' => $kw, 'workflow_status' => 'planned']);
    }

    public function test_bai_phan_mem_khong_bi_goi_y_hay_tu_gan_tri_tue_y_te(): void
    {
        $this->brains();
        foreach (['hướng dẫn thiết lập quy trình email tự động chăm sóc khách hàng', 'so sánh phần mềm marketing automation cho doanh nghiệp vừa và nhỏ', 'marketing automation là gì'] as $kw) {
            $a = $this->article($kw);
            $this->assertSame([], BrainSuggester::forArticle($a), $kw);
            $this->assertSame([], BrainAutoAttach::ensureFor($a), $kw);
            $this->assertSame(0, $a->website->brains()->count(), $kw);
        }
    }

    public function test_chu_de_dung_linh_vuc_van_duoc_goi_y(): void
    {
        $this->brains();
        $s = BrainSuggester::forArticle($this->article('chăm sóc da mặt tại nhà'));

        $this->assertSame('Da liễu và chăm sóc da', $s[0]['name'] ?? null);
    }
}
