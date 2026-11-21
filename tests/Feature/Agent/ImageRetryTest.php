<?php

namespace Tests\Feature\Agent;

use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Website;
use App\Services\Agent\Images\ImageRetry;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Ảnh bù: chọn đúng bài thiếu ảnh vì lỗi nhà cung cấp, có giới hạn lượt và khoảng nghỉ. Không gọi AI/ảnh thật. */
class ImageRetryTest extends TestCase
{
    use RefreshDatabase;

    private Article $article;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->detectEnvironment(fn () => 'local');
        app(EmergencyStopService::class)->resetForTesting();
        $w = Website::create(['name' => 'Y', 'cms_type' => 'wordpress', 'base_url' => 'https://y.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->article = Article::create(['website_id' => $w->id, 'primary_keyword' => 'đường huyết', 'workflow_status' => 'needs_review']);
        ArticleVersion::create(['article_id' => $this->article->id, 'version' => 1, 'title' => 't', 'slug' => 't', 'html_content' => '<h2>A</h2><p>x</p>', 'review_status' => 'needs_review']);
    }

    private function run429(int $minutesAgo = 60, string $code = 'provider_error', int $generated = 0): AgentRun
    {
        $run = AgentRun::create(['website_id' => $this->article->website_id, 'article_id' => $this->article->id, 'trigger' => 'manual', 'status' => 'ready_for_review', 'trust_tier' => 'T1', 'ai_mode' => 'real', 'job_type' => 'article_write']);
        $run->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();
        AgentRunStep::create(['agent_run_id' => $run->id, 'seq' => 1, 'stage' => 'images', 'role' => 'designer', 'status' => 'warn', 'title' => 'Không tạo ảnh', 'detail' => 'HTTP 429',
            'data' => ['generated' => $generated, 'skip_code' => $code, 'errors' => ['HTTP 429 hết hạn ngạch']]]);

        return $run;
    }

    public function test_bai_thieu_anh_vi_loi_nha_cung_cap_duoc_chon_sau_khoang_nghi(): void
    {
        $this->run429(60);
        $this->assertSame([$this->article->id], array_map(fn ($a) => $a->id, ImageRetry::candidates()));
    }

    public function test_chua_het_khoang_nghi_thi_khong_chon(): void
    {
        $this->run429(5);
        $this->assertSame([], ImageRetry::candidates());
    }

    public function test_da_co_anh_hoac_loi_khac_thi_khong_chon(): void
    {
        $this->run429(60, 'provider_error', 2);
        $this->assertSame([], ImageRetry::candidates(), 'đã tạo được ảnh');
    }

    public function test_bo_qua_chu_dong_khong_phai_loi_nha_cung_cap(): void
    {
        $this->run429(60, 'role_off');
        $this->assertSame([], ImageRetry::candidates(), 'họa viên tắt/không tạo ảnh theo chủ ý thì không bù');
    }

    public function test_toi_da_3_luot_cho_mot_bai(): void
    {
        for ($i = 0; $i < ImageRetry::MAX_ATTEMPTS; $i++) {
            $r = $this->run429(60 + $i);
            AgentRunStep::create(['agent_run_id' => $r->id, 'seq' => 2, 'stage' => 'decision', 'role' => 'manager', 'status' => 'ok', 'title' => 'đọc', 'detail' => '', 'data' => ['decision' => 'redo_plan', 'message' => ImageRetry::MESSAGE]]);
        }
        $this->assertSame(ImageRetry::MAX_ATTEMPTS, ImageRetry::attempts($this->article));
        $this->assertSame([], ImageRetry::candidates());
    }

    public function test_bai_da_duyet_khong_bi_dung_toi(): void
    {
        $this->run429(60);
        $this->article->update(['workflow_status' => 'approved']);
        $this->assertSame([], ImageRetry::candidates());
    }

    public function test_thong_diep_ep_pham_vi_chi_anh(): void
    {
        $this->assertSame(['images'], \App\Services\Agent\Run\RedoScope::resolve(ImageRetry::MESSAGE)['parts']);
    }
}
