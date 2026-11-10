<?php

namespace Tests\Feature\Knowledge;

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemUsage;
use App\Models\Website;
use App\Services\Knowledge\KnowledgeContext;
use App\Services\Knowledge\KnowledgeDedupe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** G4: sản phẩm của công ty là mục kiến thức loại 'product', có chỗ riêng (tối đa 2) ngoài 5 mục thường. */
class ProductKnowledgeTest extends TestCase
{
    use RefreshDatabase;

    private function article(): Article
    {
        $w = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);

        return Article::create(['website_id' => $w->id, 'primary_keyword' => 'máy lọc nước', 'workflow_status' => 'planned']);
    }

    private function kb(Article $a, string $type, string $title, string $status = 'approved'): KnowledgeItem
    {
        $body = 'Thông tin về máy lọc nước '.$title;

        return KnowledgeItem::create(['website_id' => $a->website_id, 'type' => $type, 'title' => $title, 'body' => $body, 'status' => $status, 'version' => 1, 'content_hash' => KnowledgeDedupe::hash($title, $body)]);
    }

    public function test_loai_product_co_nhan_tieng_viet(): void
    {
        $this->assertSame('Sản phẩm', KnowledgeItem::TYPES['product']);
    }

    public function test_toi_da_2_san_pham_da_duyet_va_khong_chiem_cho_5_muc_thuong(): void
    {
        $a = $this->article();
        foreach (range(1, 6) as $i) {
            $this->kb($a, 'fact', 'Sự kiện '.$i);
        }
        foreach (range(1, 3) as $i) {
            $this->kb($a, 'product', 'Model '.$i);
        }
        $this->kb($a, 'product', 'Model nháp', 'proposed');

        $ctx = new KnowledgeContext;
        $ctx->load($a, []);
        $k = $ctx->options()['knowledge'];
        $products = array_values(array_filter($k, fn ($i) => ($i['type'] ?? null) === 'product'));
        $this->assertCount(2, $products);
        $this->assertCount(5, array_filter($k, fn ($i) => ($i['type'] ?? null) !== 'product'));
        $this->assertNotContains('Model nháp', array_column($k, 'title'));

        [$data] = $ctx->stepInfo();
        $this->assertSame(2, $data['products']['count']);
        $this->assertSame(5, $data['knowledge']['count']);
    }

    public function test_san_pham_chua_duyet_khong_bao_gio_vao_bai(): void
    {
        $a = $this->article();
        $this->kb($a, 'product', 'Model nháp', 'proposed');
        $ctx = new KnowledgeContext;
        $ctx->load($a, []);
        $this->assertArrayNotHasKey('knowledge', $ctx->options());
        $this->assertSame(0, $ctx->stepInfo()[0]['products']['count']);
    }

    public function test_ghi_usage_cho_san_pham(): void
    {
        $a = $this->article();
        $p = $this->kb($a, 'product', 'Model A');
        $ctx = new KnowledgeContext;
        $ctx->load($a, []);
        $v = ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 't', 'slug' => 't', 'html_content' => '<p>x</p>', 'review_status' => 'needs_review']);
        $ctx->recordUsage($v);
        $this->assertTrue(KnowledgeItemUsage::where('article_version_id', $v->id)->where('item_id', $p->id)->exists());
    }
}
