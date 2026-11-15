<?php

namespace Tests\Feature\Agent;

use App\Models\AgentRun;
use App\Models\Article;
use App\Models\Campaign;
use App\Models\CampaignWait;
use App\Models\Website;
use App\Services\Agent\Run\CampaignRunner;
use App\Services\Agent\Run\StaleRunReaper;
use App\Services\Ai\DemoScriptedAiProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Chiến dịch chạy SONG SONG: lấp đầy chỗ trống trong mọi giới hạn, khóa chống giao trùng, nối tiếp khi một bài xong. Tiến trình nền được thay bằng bộ giả. */
class CampaignParallelTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<int> id bài đã giao cho "tiến trình nền" giả theo thứ tự */
    private array $spawned = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['agent.campaign_concurrency' => 3, 'agent.max_parallel_runs' => 10]);
        $this->spawned = [];
        CampaignRunner::$spawner = function (Article $a, int $campaignId) {
            $this->spawned[] = $a->id;

            return AgentRun::create(['article_id' => $a->id, 'website_id' => $a->website_id, 'campaign_id' => $campaignId, 'status' => 'running', 'trigger' => 'schedule', 'started_at' => now(), 'ai_mode' => 'real']);
        };
    }

    protected function tearDown(): void
    {
        CampaignRunner::$spawner = null;
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function runner(): CampaignRunner
    {
        return new CampaignRunner(fn () => new DemoScriptedAiProvider);
    }

    private function campaign(int $planned = 5, array $over = []): Campaign
    {
        $w = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://'.uniqid().'.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $c = Campaign::create($over + ['website_id' => $w->id, 'name' => 'CD', 'status' => 'active', 'source' => 'plan', 'daily_limit' => 10, 'keywords' => []]);
        for ($i = 1; $i <= $planned; $i++) {
            Article::create(['website_id' => $w->id, 'primary_keyword' => "bài số {$i}", 'workflow_status' => 'planned', 'campaign_id' => $c->id, 'priority' => 100 - $i]);
        }

        return $c;
    }

    public function test_giao_nhieu_bai_cung_luc_theo_thu_tu_uu_tien_va_danh_dau_dang_soan(): void
    {
        $c = $this->campaign(5);
        $r = $this->runner()->tick($c);

        $this->assertSame('ran', $r['result']);
        $this->assertCount(3, $this->spawned, 'đúng số bài song song mặc định');
        $this->assertSame(['bài số 1', 'bài số 2', 'bài số 3'], Article::whereIn('id', $this->spawned)->orderBy('id')->pluck('primary_keyword')->all(), 'ưu tiên cao trước');
        $this->assertStringContainsString('3 bài chạy song song', $r['message']);
        $this->assertSame(3, Article::where('workflow_status', 'drafting')->count(), 'đã đánh dấu để lượt khác không lấy trùng');
        $this->assertSame(2, Article::where('workflow_status', 'planned')->count());
    }

    public function test_da_du_cho_song_song_thi_khong_giao_them_roi_co_cho_trong_thi_giao_tiep(): void
    {
        $c = $this->campaign(6);
        $this->runner()->tick($c);
        $again = $this->runner()->tick($c->refresh());
        $this->assertSame('busy', $again['result']);
        $this->assertStringContainsString('3 bài song song', $again['message']);
        $this->assertCount(3, $this->spawned, 'không giao thêm khi đã đủ');

        AgentRun::where('article_id', $this->spawned[0])->update(['status' => 'ready_for_review', 'finished_at' => now()]); // một bài xong
        $r = $this->runner()->tick($c->refresh());
        $this->assertSame('ran', $r['result']);
        $this->assertCount(4, $this->spawned, 'đúng một chỗ trống nên giao đúng một bài');
    }

    public function test_van_bi_chan_boi_bai_moi_ngay_tran_phien_he_thong_va_cau_hinh_rieng(): void
    {
        $c = $this->campaign(8, ['daily_limit' => 2]);
        $this->runner()->tick($c);
        $this->assertCount(2, $this->spawned, 'tối đa 2 bài/ngày dù được phép 3 song song');

        $this->spawned = [];
        config(['agent.max_parallel_runs' => 1]);
        $c2 = $this->campaign(4);
        $this->runner()->tick($c2);
        $this->assertCount(0, $this->spawned, 'cả hệ thống đã có 2 phiên đang chạy ≥ trần 1');

        config(['agent.max_parallel_runs' => 10]);
        $c3 = $this->campaign(6, ['settings' => ['concurrency' => 1]]);
        $this->runner()->tick($c3);
        $this->assertCount(1, $this->spawned, 'chiến dịch đặt riêng 1 bài song song');

        $this->assertSame(5, CampaignRunner::concurrency($this->campaign(1, ['settings' => ['concurrency' => 99]])), 'trần 5');
        $this->assertSame(1, CampaignRunner::concurrency($this->campaign(1, ['settings' => ['concurrency' => 0]])), 'sàn 1');
    }

    public function test_khoa_chong_hai_nhip_cung_luc_giao_trung(): void
    {
        $c = $this->campaign(5);
        $lock = Cache::lock('campaign-tick:'.$c->id, 60);
        $this->assertTrue($lock->get());
        $r = $this->runner()->tick($c);
        $this->assertSame('busy', $r['result']);
        $this->assertCount(0, $this->spawned);
        $lock->release();
        $this->assertSame('ran', $this->runner()->tick($c->refresh())['result']);
    }

    public function test_khoi_chay_tien_trinh_nen_that_bai_thi_bai_giu_dang_soan_va_noi_ro(): void
    {
        CampaignRunner::$spawner = fn () => null;
        $c = $this->campaign(2);
        $r = $this->runner()->tick($c);
        $this->assertSame('blocked', $r['result']);
        $this->assertStringContainsString('tự trả về kế hoạch sau 10 phút', $r['message']);
        $this->assertSame(1, Article::where('workflow_status', 'drafting')->count());
    }

    public function test_het_bai_thi_ghi_cho_bo_sung_khong_giao_gi(): void
    {
        $c = $this->campaign(0);
        $r = $this->runner()->tick($c);
        $this->assertSame('no_keywords', $r['result']);
        $this->assertSame(1, CampaignWait::where('campaign_id', $c->id)->where('code', 'no_keywords')->where('status', 'open')->count());
        $this->assertCount(0, $this->spawned);
    }

    public function test_chua_co_ai_thi_khong_giao_va_khong_danh_dau_bai(): void
    {
        $c = $this->campaign(3);
        $r = (new CampaignRunner(fn () => null))->tick($c);
        $this->assertSame('no_ai', $r['result']);
        $this->assertSame(0, Article::where('workflow_status', 'drafting')->count());
    }

    public function test_noi_tiep_chi_khi_bai_vua_xong_binh_thuong_va_chien_dich_con_bat(): void
    {
        $c = $this->campaign(6, ['settings' => ['concurrency' => 1]]);
        $this->runner()->tick($c);
        $this->assertCount(1, $this->spawned);
        $run = AgentRun::first();
        $run->update(['status' => 'ready_for_review', 'finished_at' => now()]);

        $next = $this->runner()->continueAfter($c->refresh(), ['result' => 'ran', 'message' => 'x', 'run' => $run->refresh()]);
        $this->assertSame('ran', $next['result']);
        $this->assertCount(2, $this->spawned, 'bài xong thì tự giao bài kế tiếp, không chờ lịch 15 phút');

        AgentRun::latest('id')->first()->update(['status' => 'failed']);
        $failed = AgentRun::latest('id')->first();
        $this->assertNull($this->runner()->continueAfter($c->refresh(), ['result' => 'ran', 'message' => 'x', 'run' => $failed->refresh()]), 'bài lỗi thì không nối tiếp (tránh lặp lỗi)');
        $held = AgentRun::create(['article_id' => Article::first()->id, 'website_id' => $c->website_id, 'campaign_id' => $c->id, 'status' => 'held', 'trigger' => 'schedule', 'started_at' => now(), 'ai_mode' => 'real']);
        $this->assertNull($this->runner()->continueAfter($c->refresh(), ['result' => 'ran', 'message' => 'x', 'run' => $held]), 'bị giữ do hết hạn mức AI thì dừng nối tiếp');

        $c->update(['status' => 'paused']);
        $this->assertNull($this->runner()->continueAfter($c->refresh(), ['result' => 'ran', 'message' => 'x', 'run' => $run]), 'đã tạm dừng thì không nối tiếp');
    }

    public function test_hoan_tat_bai_gan_nhan_che_do_o_tien_trinh_nen(): void
    {
        $c = $this->campaign(1, ['mode' => 'balanced']);
        $this->runner()->tick($c);
        $run = AgentRun::first();
        $run->update(['status' => 'ready_for_review']);
        $r = $this->runner()->completeRun($c->refresh(), $run->refresh(), 'bài số 1');
        $this->assertSame('ran', $r['result']);
        $this->assertSame('balanced', $run->refresh()->trust_mode);
    }

    public function test_bai_dang_soan_chi_tra_ve_ke_hoach_sau_10_phut_khong_co_phien(): void
    {
        $w = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $fresh = Article::create(['website_id' => $w->id, 'primary_keyword' => 'mới giao', 'workflow_status' => 'drafting']);
        $old = Article::create(['website_id' => $w->id, 'primary_keyword' => 'bị bỏ quên', 'workflow_status' => 'drafting']);
        Article::whereKey($old->id)->update(['updated_at' => now()->subMinutes(11)]);

        $r = StaleRunReaper::reap();
        $this->assertSame('drafting', $fresh->refresh()->workflow_status, 'tiến trình nền có thể đang khởi động');
        $this->assertSame('planned', $old->refresh()->workflow_status);
        $this->assertSame(1, $r['articles']);
    }
}
