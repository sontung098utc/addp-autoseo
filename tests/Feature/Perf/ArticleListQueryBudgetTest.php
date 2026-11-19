<?php

namespace Tests\Feature\Perf;

use App\Filament\Resources\ArticleResource;
use App\Filament\Resources\ArticleResource\Pages\ListArticles;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

/** Danh sách Bài viết: số truy vấn không được tăng theo số dòng (mỗi dòng có phiên chạy + bản thảo HTML lớn). */
class ArticleListQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private function seedRows(int $n, Website $site, User $user): void
    {
        $html = str_repeat('<p>Nội dung dài '.str_repeat('x', 200).'</p>', 150);
        $from = Article::count();
        for ($i = $from; $i < $from + $n; $i++) {
            $a = Article::create(['website_id' => $site->id, 'created_by' => $user->id, 'primary_keyword' => "kw $i", 'workflow_status' => 'planned']);
            ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => "Bài $i", 'slug' => "b-$i", 'html_content' => $html, 'review_status' => 'approved']);
            $run = AgentRun::create(['website_id' => $site->id, 'article_id' => $a->id, 'job_type' => 'article_write', 'trigger' => 'manual', 'status' => 'ready_for_review', 'ai_mode' => 'real', 'started_at' => now()]);
            AgentRunStep::create(['agent_run_id' => $run->id, 'seq' => 1, 'stage' => 'write', 'role' => 'writer', 'status' => 'ok', 'title' => 'Viết', 'data' => []]);
        }
    }

    /** @return list<string> */
    private function queryLog(User $user): array
    {
        ArticleResource::forgetRowCaches();
        $log = [];
        Event::forget(QueryExecuted::class);
        DB::listen(function ($q) use (&$log) { $log[] = $q->sql; });
        Livewire::actingAs($user)->test(ListArticles::class);

        return $log;
    }

    public function test_so_truy_van_danh_sach_khong_tang_theo_so_dong(): void
    {
        $site = Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $user = User::create(['name' => 'a', 'email' => 'a@x.vn', 'password' => 'secret123', 'role' => 'admin', 'is_active' => true]);
        $this->seedRows(3, $site, $user);
        $small = count($this->queryLog($user));
        $this->seedRows(22, $site, $user);
        $log = $this->queryLog($user);
        $big = count($log);

        getenv('PERF_REPORT') && fwrite(STDERR, "\n[ĐO] danh sách bài: 3 dòng={$small} truy vấn; 25 dòng={$big} truy vấn\n");
        // Trước khi sửa: 19 → 81 truy vấn (phiên gần nhất + bước + tồn tại của bài, mỗi dòng 2-3 truy vấn).
        $this->assertLessThanOrEqual($small + 2, $big, 'Số truy vấn không được tăng theo số dòng (N+1).');
        $this->assertLessThanOrEqual(20, $big);
        $this->assertSame([], array_values(array_filter($log, fn ($q) => str_contains($q, 'select "article_versions".*'))), 'Bảng không được kéo cả html_content của bản thảo.');
    }
}
