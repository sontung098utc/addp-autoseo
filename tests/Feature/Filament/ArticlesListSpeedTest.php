<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ArticleResource;
use App\Filament\Resources\ArticleResource\Pages\ListArticles;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\KnowledgeItem;
use App\Models\User;
use App\Models\Website;
use App\Support\RequestMemo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Trang Bài viết từng mất 1.305 truy vấn và ~3 s mỗi lần dựng/làm mới (kiểm chất lượng + Trí tuệ tính lại cho từng dòng). */
class ArticlesListSpeedTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'approver'): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function article(Website $site, string $status = 'needs_review'): Article
    {
        $a = Article::create(['website_id' => $site->id, 'primary_keyword' => 'kw'.uniqid(), 'workflow_status' => $status]);
        ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 't', 'slug' => 's'.uniqid(), 'html_content' => '<h2>A</h2><p>Nội dung.</p>', 'review_status' => 'needs_review']);

        return $a;
    }

    private function hint(Article $a): ?string
    {
        $m = new \ReflectionMethod(ArticleResource::class, 'blockedHintOf');
        $m->setAccessible(true);

        return $m->invoke(null, $a);
    }

    public function test_bo_nho_yeu_cau_mac_dinh_tat_va_chi_nho_khi_bat(): void
    {
        $n = 0;
        $f = function () use (&$n) { return ++$n; };
        $this->assertSame(1, RequestMemo::remember('k', $f));
        $this->assertSame(2, RequestMemo::remember('k', $f), 'Tắt: chạy lại mỗi lần (tiến trình sống lâu không thấy dữ liệu cũ).');
        RequestMemo::enable();
        $this->assertSame(3, RequestMemo::remember('k', $f));
        $this->assertSame(3, RequestMemo::remember('k', $f), 'Bật: nhớ trong yêu cầu.');
        RequestMemo::flush();
        $this->assertSame(4, RequestMemo::remember('k', $f));
    }

    private function key(ArticleVersion $v): string
    {
        $m = new \ReflectionMethod(ArticleResource::class, 'hintCacheKey');
        $m->setAccessible(true);

        return $m->invoke(null, $v);
    }

    public function test_khoa_nho_chu_thich_doi_khi_kho_hoac_ban_bai_doi_va_giu_nguyen_khi_khong_doi(): void
    {
        $site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $v = $this->article($site)->versions()->first();

        $k0 = $this->key($v);
        $this->assertSame($k0, $this->key($v->fresh()), 'Không đổi gì thì cùng khóa (dùng lại bộ nhớ).');

        KnowledgeItem::create(['type' => 'fact', 'title' => 'x', 'body' => 'y', 'status' => 'approved', 'version' => 1, 'content_hash' => 'h']);
        $k1 = $this->key($v->fresh());
        $this->assertNotSame($k0, $k1, 'Kho đổi thì tính lại.');

        $this->travel(5)->seconds();
        $v->update(['title' => 'tiêu đề mới']);
        $this->assertNotSame($k1, $this->key($v->fresh()), 'Sửa bản bài thì tính lại.');
    }

    public function test_chu_thich_nut_duyet_duoc_dung_lai_tu_bo_nho(): void
    {
        $site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $a = $this->article($site);
        Cache::flush();
        $computed = $this->hint($a);
        $this->assertStringContainsString('Còn lỗi chặn duyệt', (string) $computed);

        // Đặt sẵn một giá trị khác vào đúng khóa: nếu lần gọi sau trả về nó thì đã dùng bộ nhớ, không chạy lại kiểm chất lượng.
        Cache::put($this->key($a->versions()->first()), 'GIÁ TRỊ TỪ BỘ NHỚ', 60);
        $this->assertSame('GIÁ TRỊ TỪ BỘ NHỚ', $this->hint($a));
    }

    public function test_bang_van_hien_dung_va_khong_loi_khi_bo_nho_dem_hong(): void
    {
        $this->actingAs($this->user());
        $site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $a = $this->article($site);
        $this->article($site);
        Livewire::test(ListArticles::class)->assertOk()->assertCanSeeTableRecords([$a]);

        Cache::shouldReceive('remember')->andThrow(new \RuntimeException('Permission denied'));
        $this->assertStringContainsString('Còn lỗi chặn duyệt', (string) $this->hint($a), 'Bộ nhớ đệm hỏng thì tính trực tiếp, không làm sập trang.');
    }

    public function test_cot_tri_tue_khong_truy_van_lai_cho_tung_dong_cung_website(): void
    {
        $this->actingAs($this->user());
        $site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        foreach (range(1, 10) as $i) {
            $this->article($site, 'planned');
        }
        DB::enableQueryLog();
        Livewire::test(ListArticles::class)->assertOk();
        $brainQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'brain_website'))->count();
        $this->assertLessThan(10, $brainQueries, "Cùng một website: không truy vấn Trí tuệ cho từng dòng ($brainQueries truy vấn).");
    }
}
