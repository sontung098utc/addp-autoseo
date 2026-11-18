<?php

namespace Tests\Feature\Perf;

use App\Filament\Pages\ContentPlanPage;
use App\Filament\Support\Keywords\KeywordService;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Giữ số truy vấn của trang Kế hoạch ở mức không tăng theo số bài (mỗi lượt Livewire dựng lại cả trang). */
class ContentPlanQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $log = [];

    private function seedSite(int $articles): User
    {
        Cache::flush();
        Carbon::setTestNow('2026-11-06 10:00:00');
        $site = Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $user = User::create(['name' => 'w', 'email' => 'w@x.vn', 'password' => 'secret123', 'role' => 'writer', 'is_active' => true]);
        $repo = KeywordService::make()->repo();
        $repo->saveList([]);
        $repo->saveClusters([['name' => 'Học bổng', 'intent' => 'informational', 'keywords' => ['học bổng a', 'học bổng b']]]);
        $this->addArticles($site, $user, $articles);

        return $user;
    }

    private function addArticles(Website $site, User $user, int $n): void
    {
        $html = str_repeat('<p>Nội dung dài của bài viết '.str_repeat('x', 200).'</p>', 150);
        $from = Article::count();
        for ($i = $from; $i < $from + $n; $i++) {
            $a = Article::create(['website_id' => $site->id, 'created_by' => $user->id, 'primary_keyword' => "kw $i", 'workflow_status' => 'needs_review']);
            ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => "Bài $i", 'slug' => "b-$i", 'html_content' => $html, 'review_status' => 'approved']);
        }
    }

    /** @return array{0:int,1:float} số truy vấn, ms */
    private function measure(callable $fn): array
    {
        $n = 0;
        $this->log = [];
        \Illuminate\Support\Facades\Event::forget(\Illuminate\Database\Events\QueryExecuted::class);
        DB::listen(function ($q) use (&$n) { $n++; $this->log[] = $q->sql; });
        $t = microtime(true);
        $fn();

        return [$n, (microtime(true) - $t) * 1000];
    }

    public function test_so_truy_van_khong_tang_theo_so_bai(): void
    {
        $user = $this->seedSite(5);
        $page = Livewire::actingAs($user)->test(ContentPlanPage::class);
        [$small] = $this->measure(fn () => $page->call('nextWeek'));

        $this->addArticles(Website::first(), $user, 115);
        [$big, $ms] = $this->measure(fn () => $page->call('nextWeek'));

        getenv('PERF_REPORT') && fwrite(STDERR, "\n[ĐO] nextWeek: 5 bài={$small} truy vấn; 120 bài={$big} truy vấn, {$ms} ms\n");
        $this->assertSame($small, $big, 'Số truy vấn không được tăng theo số bài (N+1).');
        // Trước khi sửa: 30 truy vấn/lượt (website 8 lần, tải toàn bộ bài kèm html_content 3 lần).
        $this->assertLessThanOrEqual(14, $big);
        $count = fn (string $needle) => count(array_filter($this->log, fn ($q) => str_contains($q, $needle)));
        $this->assertSame(1, $count('from "websites" where "status"'), 'Danh sách website chỉ nạp một lần mỗi lượt.');
        $this->assertSame(1, $count('from "articles" where "website_id" = ? order by'), 'Bài của website chỉ nạp một lần mỗi lượt.');
        $this->assertSame(0, $count('select "article_versions".*'), 'Không kéo html_content của bản thảo chỉ để lấy tiêu đề.');
    }
}
