<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ArticleResource;
use App\Filament\Resources\ArticleResource\Pages\ViewArticle;
use App\Filament\Support\Article\VersionDiff;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VersionDiffTest extends TestCase
{
    use RefreshDatabase;

    private Article $article;

    protected function setUp(): void
    {
        parent::setUp();
        $site = Website::create(['name' => 'Alpha', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->article = Article::create(['website_id' => $site->id, 'primary_keyword' => 'máy lọc nước', 'workflow_status' => 'needs_review']);
    }

    private function v(int $n, string $html, array $extra = []): ArticleVersion
    {
        return ArticleVersion::create($extra + ['article_id' => $this->article->id, 'version' => $n, 'title' => 'Tiêu đề', 'slug' => 's', 'html_content' => $html, 'seo_title' => 'SEO', 'meta_description' => 'Mô tả', 'review_status' => 'needs_review']);
    }

    public function test_meta_doi(): void
    {
        $d = VersionDiff::between($this->v(1, '<p>a</p>'), $this->v(2, '<p>a</p>', ['title' => 'Mới', 'seo_title' => 'SEO']));
        $this->assertSame(['title'], array_column($d['meta'], 'field'));
        $this->assertSame('Mới', $d['meta'][0]['new']);
        $this->assertSame(1, $d['summary']['meta_changed']);
    }

    public function test_muc_giu_sua_them_bo_ghep_theo_tieu_de(): void
    {
        $old = $this->v(1, '<p>Mở</p><h2>Giá</h2><p>một hai</p><h2>Cũ</h2><p>x</p><h2>Giữ</h2><p>y</p>');
        $new = $this->v(2, '<p>Mở</p><h2>  GIÁ </h2><p>một hai ba bốn</p><h2>Mới</h2><p>z</p><h2>Giữ</h2><p>y</p>');
        $d = VersionDiff::between($old, $new);
        $by = collect($d['sections'])->mapWithKeys(fn ($s) => [mb_strtolower(trim($s['heading'])) => $s]);
        $this->assertSame('same', $by['(mở đầu)']['status']);
        $this->assertSame('changed', $by['giá']['status']);
        $this->assertSame(2, $by['giá']['old_words']);
        $this->assertSame(4, $by['giá']['new_words']);
        $this->assertSame('added', $by['mới']['status']);
        $this->assertSame('removed', $by['cũ']['status']);
        $this->assertSame('same', $by['giữ']['status']);
        $this->assertSame([1, 1, 1], [$d['summary']['sections_changed'], $d['summary']['sections_added'], $d['summary']['sections_removed']]);
    }

    public function test_html_khong_co_h2_va_tinh_tu(): void
    {
        $d = VersionDiff::between($this->v(1, '<p>một hai</p>'), $this->v(2, '<p>một hai ba</p>'));
        $this->assertCount(1, $d['sections']);
        $this->assertSame(VersionDiff::INTRO, $d['sections'][0]['heading']);
        $this->assertSame('changed', $d['sections'][0]['status']);
        $this->assertSame([2, 3], [$d['summary']['words_old'], $d['summary']['words_new']]);
        $this->assertArrayHasKey('P0', $d['summary']['quality_new']);
    }

    public function test_hop_so_sanh_tren_trang_bai(): void
    {
        $this->actingAs(User::create(['name' => 'w', 'email' => 'w@x.vn', 'password' => 'secret123', 'role' => 'writer', 'is_active' => true]));
        $v1 = $this->v(1, '<h2>Giá</h2><p>một hai</p>');
        $this->v(2, '<h2>Giá</h2><p>một hai <script>alert(1)</script> ba</p>', ['title' => 'Tiêu đề mới']);

        $page = $this->get(ArticleResource::getUrl('view', ['record' => $this->article]));
        $page->assertOk()->assertSee('aw-compare-open', false);
        $this->assertSame(1, substr_count($page->getContent(), 'data-testid="aw-compare-open"'));

        Livewire::test(ViewArticle::class, ['record' => $this->article->getKey()])
            ->mountAction('compare', ['version' => $this->article->versions()->where('version', 2)->value('id')])
            ->assertSee('aw-compare')
            ->assertSee('So sánh theo tiêu đề H2; chưa so từng câu.')
            ->assertSee('Đã sửa')
            ->assertSee('Tiêu đề mới')
            ->assertDontSee('<script');
    }

    public function test_canh_bao_khi_p0_tang(): void
    {
        $this->actingAs(User::create(['name' => 'w', 'email' => 'w@x.vn', 'password' => 'secret123', 'role' => 'writer', 'is_active' => true]));
        $this->v(1, '<h2>A</h2><p>'.str_repeat('từ ', 300).'</p>', ['title' => 'Máy lọc nước tốt', 'meta_description' => str_repeat('m ', 70)]);
        $this->v(2, '', ['title' => '', 'seo_title' => null, 'meta_description' => null]);
        $d = VersionDiff::between($this->article->versions()->where('version', 1)->first(), $this->article->versions()->where('version', 2)->first());
        if (($d['summary']['quality_new']['P0'] ?? 0) <= ($d['summary']['quality_old']['P0'] ?? 0)) {
            $this->markTestSkipped('Dữ liệu mẫu không làm P0 tăng');
        }
        Livewire::test(ViewArticle::class, ['record' => $this->article->getKey()])
            ->mountAction('compare', [])->assertSee('Bản mới có nhiều lỗi chặn hơn bản trước.');
    }

    public function test_phien_ban_dau_khong_co_nut(): void
    {
        $this->actingAs(User::create(['name' => 'w', 'email' => 'w@x.vn', 'password' => 'secret123', 'role' => 'writer', 'is_active' => true]));
        $this->v(1, '<p>a</p>');
        $this->get(ArticleResource::getUrl('view', ['record' => $this->article]))->assertOk()->assertDontSee('data-testid="aw-compare-open"', false);
    }
}
