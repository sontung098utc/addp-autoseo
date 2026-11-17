<?php

namespace Tests\Feature\Agent;

use App\Jobs\StartAgentRunJob;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\Website;
use App\Services\Agent\Run\BackgroundRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Hosting tắt proc_open cho PHP web (log server 17/11: "The Process class relies on proc_open"): bấm "Giao đội AI" không chạy gì và giao diện vẫn báo thành công. Nay xếp hàng cho tiến trình dòng lệnh. */
class StartAgentRunQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        BackgroundRun::$canSpawnOverride = null;
        BackgroundRun::$runner = null;
        parent::tearDown();
    }

    private function article(): Article
    {
        $site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);

        return Article::create(['website_id' => $site->id, 'primary_keyword' => 'đo đường huyết', 'workflow_status' => 'planned']);
    }

    public function test_web_khong_mo_duoc_tien_trinh_con_thi_xep_hang_va_khong_goi_tien_trinh(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        BackgroundRun::$canSpawnOverride = false;
        $called = false;
        BackgroundRun::$runner = function () use (&$called) { $called = true; };
        $a = $this->article();

        $this->assertNull(BackgroundRun::start($a, 7, 1, 'làm lại phần 2', 3));
        $this->assertSame('queued', BackgroundRun::lastOutcome());
        $this->assertFalse($called, 'Không thử mở tiến trình con khi biết là bị tắt.');
        Queue::assertPushed(StartAgentRunJob::class, fn ($j) => $j->articleId === $a->id && $j->actorId === 7 && $j->redoMessage === 'làm lại phần 2' && $j->campaignId === 3);
    }

    public function test_hang_doi_sync_thi_khong_the_chay_nen_va_bao_failed_co_log(): void
    {
        config(['queue.default' => 'sync']);
        Queue::fake();
        Log::spy();
        BackgroundRun::$canSpawnOverride = false;

        $this->assertNull(BackgroundRun::start($this->article(), null, 1));
        $this->assertSame('failed', BackgroundRun::lastOutcome());
        Queue::assertNothingPushed();
        Log::shouldHaveReceived('error')->once();
    }

    public function test_job_chay_trong_tien_trinh_dong_lenh_se_mo_tien_trinh_nen_dung_lenh(): void
    {
        config(['queue.default' => 'database', 'agent.php_binary' => '/usr/bin/php82']);
        BackgroundRun::$canSpawnOverride = false; // PHP web; job chạy trong worker nên đi nhánh viaQueue và không xếp hàng lần nữa
        $cmd = null;
        $a = $this->article();
        BackgroundRun::$runner = function (string $c) use (&$cmd, $a) {
            $cmd = $c;
            AgentRun::create(['website_id' => $a->website_id, 'article_id' => $a->id, 'trigger' => 'manual', 'status' => 'running', 'started_at' => now()]); // giả lập tiến trình nền ghi hàng phiên
        };
        Queue::fake();

        (new StartAgentRunJob($a->id, 9, 'sửa lại', null))->handle();

        $this->assertNotNull($cmd);
        $this->assertStringContainsString("'/usr/bin/php82' artisan agent:run-article ".$a->id.' --actor=9', $cmd);
        $this->assertStringContainsString('--redo=', $cmd);
        Queue::assertNothingPushed();
        $this->assertSame('started', BackgroundRun::lastOutcome());
    }

    public function test_job_bo_qua_khi_bai_da_co_phien_dang_chay_hoac_khong_con(): void
    {
        $a = $this->article();
        $called = false;
        BackgroundRun::$runner = function () use (&$called) { $called = true; };
        (new StartAgentRunJob(999999))->handle(); // bài không còn
        $this->assertFalse($called);
    }

    public function test_canspawn_doc_disable_functions_va_cho_phep_ghi_de_khi_kiem_thu(): void
    {
        $this->assertSame(function_exists('proc_open') && ! str_contains((string) ini_get('disable_functions'), 'proc_open'), BackgroundRun::canSpawn());
        BackgroundRun::$canSpawnOverride = false;
        $this->assertFalse(BackgroundRun::canSpawn());
    }
}
