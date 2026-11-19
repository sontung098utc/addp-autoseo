<?php

namespace Tests\Feature\Perf;

use App\Filament\Resources\ArticleResource\Pages\ViewArticle;
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

/** Trang chi tiết bài: số truy vấn của lượt dựng đầu và của lượt nạp tab không phụ thuộc số phiên/bước. */
class ArticleViewQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private function seedArticle(int $runs): array
    {
        $site = Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $user = User::create(['name' => 'a', 'email' => 'a@x.vn', 'password' => 'secret123', 'role' => 'admin', 'is_active' => true]);
        $a = Article::create(['website_id' => $site->id, 'created_by' => $user->id, 'primary_keyword' => 'kw', 'workflow_status' => 'needs_review']);
        $html = str_repeat('<h2>Mục</h2><p>Nội dung dài '.str_repeat('x', 200).'</p>', 40);
        for ($v = 1; $v <= 3; $v++) {
            ArticleVersion::create(['article_id' => $a->id, 'version' => $v, 'title' => "Bản $v", 'slug' => 'b', 'html_content' => $html, 'review_status' => 'approved']);
        }
        for ($r = 0; $r < $runs; $r++) {
            $run = AgentRun::create(['website_id' => $site->id, 'article_id' => $a->id, 'job_type' => 'article_write', 'trigger' => 'manual', 'status' => 'ready_for_review', 'ai_mode' => 'real', 'started_at' => now()]);
            foreach (['guard', 'brief', 'research', 'write', 'grade'] as $i => $stage) {
                AgentRunStep::create(['agent_run_id' => $run->id, 'seq' => $i + 1, 'stage' => $stage, 'role' => 'writer', 'status' => 'ok', 'title' => $stage, 'data' => []]);
            }
        }

        return [$user, $a];
    }

    /** @return array{0:int,1:float} */
    private function measure(callable $fn): array
    {
        Event::forget(QueryExecuted::class);
        $n = 0;
        DB::listen(function () use (&$n) { $n++; });
        $t = microtime(true);
        $fn();

        return [$n, (microtime(true) - $t) * 1000];
    }

    public function test_so_truy_van_khong_tang_theo_so_phien_chay(): void
    {
        config(['ui.lazy_tabs' => true]);
        [$user, $a] = $this->seedArticle(2);
        $this->actingAs($user);
        [$mountSmall] = $this->measure(fn () => Livewire::test(ViewArticle::class, ['record' => $a->id]));

        // thêm 6 phiên nữa
        foreach (range(1, 6) as $_) {
            $run = AgentRun::create(['website_id' => $a->website_id, 'article_id' => $a->id, 'job_type' => 'article_write', 'trigger' => 'manual', 'status' => 'ready_for_review', 'ai_mode' => 'real', 'started_at' => now()]);
            foreach (['guard', 'brief', 'research', 'write', 'grade'] as $i => $stage) {
                AgentRunStep::create(['agent_run_id' => $run->id, 'seq' => $i + 1, 'stage' => $stage, 'role' => 'writer', 'status' => 'ok', 'title' => $stage, 'data' => []]);
            }
        }
        $c = null;
        [$mountBig, $ms] = $this->measure(function () use (&$c, $a) { $c = Livewire::test(ViewArticle::class, ['record' => $a->id]); });
        [$tabs, $ms2] = $this->measure(fn () => $c->call('loadTabs', ['research', 'publish', 'activity', 'keyword']));

        getenv('PERF_REPORT') && fwrite(STDERR, sprintf("\n[ĐO] chi tiết bài: dựng đầu 2 phiên=%d, 8 phiên=%d truy vấn (%.0f ms); loadTabs 4 tab=%d truy vấn (%.0f ms)\n", $mountSmall, $mountBig, $ms, $tabs, $ms2));
        // Trước khi sửa: dựng đầu 165 truy vấn (Trí tuệ của website nạp 27 lần mỗi lượt).
        $this->assertLessThanOrEqual($mountSmall + 2, $mountBig, 'Số truy vấn không được tăng theo số phiên (N+1).');
        $this->assertLessThanOrEqual(150, $mountBig);
    }
}
