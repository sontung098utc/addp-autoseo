<?php

namespace Tests\Feature\Agent;

use App\Models\AgentRun;
use App\Models\Article;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** G2: migration 2026_11_20_000001. Không kiểm rollback: đổi cột trên SQLite trong test không đáng tin. */
class AgentRunJobTypeMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Website
    {
        return Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://s.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    public function test_co_cot_moi(): void
    {
        foreach (['job_type', 'parent_run_id', 'input_refs', 'output_ref', 'tokens_budget'] as $col) {
            $this->assertTrue(Schema::hasColumn('agent_runs', $col), $col);
        }
    }

    public function test_phien_tao_khong_chi_dinh_loai_viec_mac_dinh_la_viet_bai(): void
    {
        $site = $this->site();
        $a = Article::create(['website_id' => $site->id, 'primary_keyword' => 'k']);
        $run = AgentRun::create(['website_id' => $site->id, 'article_id' => $a->id, 'status' => 'failed', 'ai_mode' => 'mock', 'trust_tier' => 1]);

        $this->assertSame('article_write', $run->fresh()->job_type);
    }

    public function test_tao_duoc_phien_khong_gan_bai_va_phien_con(): void
    {
        $site = $this->site();
        $parent = AgentRun::create(['website_id' => $site->id, 'job_type' => 'brain_build', 'status' => 'running', 'ai_mode' => 'mock', 'trust_tier' => 1, 'tokens_budget' => 9000]);
        $child = AgentRun::create(['website_id' => $site->id, 'job_type' => 'market_research', 'parent_run_id' => $parent->id, 'status' => 'running', 'ai_mode' => 'mock', 'trust_tier' => 1, 'input_refs' => ['a' => 1]]);

        $this->assertNull($parent->fresh()->article_id);
        $this->assertSame(9000, $parent->fresh()->tokens_budget);
        $this->assertSame($parent->id, $child->fresh()->parent->id);
        $this->assertSame(['a' => 1], $child->fresh()->input_refs);
    }
}
