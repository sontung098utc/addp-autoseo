<?php

namespace Tests\Feature\Agent;

use App\Models\AgentRun;
use App\Models\Article;
use App\Models\Website;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Services\Ai\MockAiProvider;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Lỗi không lường trước của phiên chạy phải có log (có ngữ cảnh, không lộ nội dung); migration bù cột thiếu chạy được. */
class OrchestratorErrorLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_loi_khong_luong_truoc_duoc_ghi_log_co_ngu_canh_khong_ro_noi_dung(): void
    {
        app(EmergencyStopService::class)->resetForTesting();
        $w = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $a = Article::create(['website_id' => $w->id, 'primary_keyword' => 'BI-MAT-NOI-DUNG-BAI', 'workflow_status' => 'planned']);
        // Làm hỏng bước lưu bản nháp như DB lệch schema.
        Schema::table('article_versions', fn ($t) => $t->dropColumn('slug'));

        $captured = [];
        Log::shouldReceive('error')->andReturnUsing(function ($m, $ctx = []) use (&$captured) {
            $captured[] = [$m, $ctx];
        });
        Log::shouldReceive('warning', 'info', 'debug', 'notice')->zeroOrMoreTimes();

        $run = (new AgentRunOrchestrator(new MockAiProvider))->run($a);

        $this->assertSame('failed', $run->status);
        $this->assertNotEmpty($captured, 'lỗi bị nuốt, không có log');
        [$msg, $ctx] = $captured[0];
        $this->assertStringContainsString('Phiên chạy', $msg);
        $this->assertSame($run->id, $ctx['run_id']);
        $this->assertSame($a->id, $ctx['article_id']);
        $this->assertArrayHasKey('exception', $ctx);
        $this->assertStringNotContainsString('BI-MAT-NOI-DUNG-BAI', json_encode($ctx, JSON_UNESCAPED_UNICODE));
    }

    public function test_migration_bu_cot_approved_version_id_chay_duoc_khi_cot_bi_thieu(): void
    {
        // Mô phỏng DB lệch schema: dựng lại bảng articles không có cột (SQLite) rồi chạy migration bù.
        Schema::table('articles', function ($t) {
            $t->dropForeign(['approved_version_id']);
        });
        Schema::table('articles', fn ($t) => $t->dropColumn('approved_version_id'));
        $this->assertFalse(Schema::hasColumn('articles', 'approved_version_id'));

        $m = require base_path('database/migrations/2026_11_12_000001_ensure_articles_approved_version_id.php');
        $m->up();
        $this->assertTrue(Schema::hasColumn('articles', 'approved_version_id'));
        $m->up(); // idempotent
        $this->assertTrue(Schema::hasColumn('articles', 'approved_version_id'));
    }
}
