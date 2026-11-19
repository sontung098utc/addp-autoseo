<?php

namespace Tests\Feature\Perf;

use App\Filament\Clusters\AgentTeam\Pages\AgentOverview;
use App\Filament\Pages\Dashboard;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\User;
use App\Models\Website;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tổng quan và Đội AI tự làm mới theo nhịp (wire:poll): số truy vấn mỗi lượt không được tăng theo số bài/phiên.
 * Đo trước khi sửa (3 → 43 bài, 1/3 phiên đang chạy): Tổng quan 26 → 80, Đội AI 38 → 99.
 */
class OverviewPagesQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private function seedMore(int $n): User
    {
        $site = Website::first() ?? Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $user = User::first() ?? User::create(['name' => 'a', 'email' => 'a@x.vn', 'password' => 'secret123', 'role' => 'admin', 'is_active' => true]);
        $from = Article::count();
        for ($i = $from; $i < $from + $n; $i++) {
            $a = Article::create(['website_id' => $site->id, 'created_by' => $user->id, 'primary_keyword' => "kw $i", 'workflow_status' => 'needs_review']);
            ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => "Bài $i", 'slug' => "b-$i", 'html_content' => '<p>x</p>', 'review_status' => 'approved']);
            $run = AgentRun::create(['website_id' => $site->id, 'article_id' => $a->id, 'job_type' => 'article_write', 'trigger' => 'manual', 'status' => $i % 3 === 0 ? 'running' : 'ready_for_review', 'ai_mode' => 'real', 'started_at' => now()]);
            foreach (['guard', 'brief', 'write'] as $k => $stage) {
                AgentRunStep::create(['agent_run_id' => $run->id, 'seq' => $k + 1, 'stage' => $stage, 'role' => 'writer', 'status' => 'ok', 'title' => $stage, 'data' => []]);
            }
        }

        return $user;
    }

    private function countQueries(string $page, User $user): int
    {
        Event::forget(QueryExecuted::class);
        $n = 0;
        DB::listen(function () use (&$n) { $n++; });
        Livewire::actingAs($user)->test($page);

        return $n;
    }

    public function test_tong_quan_va_doi_ai_khong_n_cong_1(): void
    {
        $user = $this->seedMore(3);
        $res = [];
        foreach ([Dashboard::class, AgentOverview::class] as $page) {
            $res[$page][] = $this->countQueries($page, $user);
        }
        $this->seedMore(40);
        foreach ([Dashboard::class, AgentOverview::class] as $page) {
            $res[$page][] = $this->countQueries($page, $user);
        }
        foreach ($res as $page => [$small, $big]) {
            getenv('PERF_REPORT') && fwrite(STDERR, sprintf("\n[ĐO] %s: 3 bài=%d, 43 bài=%d truy vấn\n", class_basename($page), $small, $big));
            $this->assertLessThanOrEqual($small + 3, $big, class_basename($page).': số truy vấn tăng theo số bài (N+1).');
        }
    }
}
