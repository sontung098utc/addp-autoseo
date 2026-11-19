<?php

namespace Tests\Feature\Agent;

use App\Filament\Pages\KeywordsPage;
use App\Filament\Support\Keywords\CsvKeywordParser;
use App\Filament\Support\Keywords\EloquentKeywordRepository;
use App\Filament\Support\Keywords\KeywordService;
use App\Filament\Support\Review\ReviewQueue;
use App\Models\AgentRun;
use App\Models\AgentSetting;
use App\Models\AgentRunStep;
use App\Models\Article;
use App\Models\Campaign;
use App\Models\Keyword;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewQueueConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Website
    {
        return Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://'.uniqid().'.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function user(): User
    {
        return User::create(['name' => 'a', 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => 'approver', 'is_active' => true]);
    }

    public function test_so_bai_cho_duyet_dem_theo_bai_va_cung_mot_ham(): void
    {
        $w = $this->site();
        $a1 = Article::create(['website_id' => $w->id, 'primary_keyword' => 'a1', 'workflow_status' => 'needs_review']);
        Article::create(['website_id' => $w->id, 'primary_keyword' => 'a2', 'workflow_status' => 'needs_review']);
        Article::create(['website_id' => $w->id, 'primary_keyword' => 'a3', 'workflow_status' => 'approved']);
        // Nhiều phiên cùng đưa một bài tới chờ duyệt: không được đếm thành nhiều "chờ chuyên môn".
        foreach ([1, 2, 3] as $_) {
            $run = AgentRun::create(['website_id' => $w->id, 'article_id' => $a1->id, 'status' => 'ready_for_review', 'cost_vnd' => 0]);
            AgentRunStep::create(['agent_run_id' => $run->id, 'seq' => 1, 'stage' => 'decision', 'role' => 'system', 'status' => 'warn', 'title' => 'Cần người có chuyên môn duyệt']);
        }

        $this->assertSame(2, ReviewQueue::waitingCount());
        $this->assertSame(1, ReviewQueue::expertCount(now()->subDays(3)));
        $this->assertLessThanOrEqual(ReviewQueue::waitingCount(), ReviewQueue::expertCount(now()->subDays(3)));
    }

    public function test_phien_bi_chan_hoac_loi_dem_theo_moc_thoi_gian(): void
    {
        $w = $this->site();
        AgentRun::create(['website_id' => $w->id, 'status' => 'failed', 'cost_vnd' => 0]);
        AgentRun::create(['website_id' => $w->id, 'status' => 'blocked', 'cost_vnd' => 0]);
        $old = AgentRun::create(['website_id' => $w->id, 'status' => 'failed', 'cost_vnd' => 0]);
        $old->forceFill(['created_at' => now()->subDays(3)])->save();

        $this->assertSame(2, ReviewQueue::problemRunCount(now()->startOfDay()));
        $this->assertSame(3, ReviewQueue::problemRunCount(now()->subDays(7)));
    }

    public function test_doi_ai_tong_quan_dung_cung_so_va_nhan_ro(): void
    {
        $w = $this->site();
        Article::create(['website_id' => $w->id, 'primary_keyword' => 'a1', 'workflow_status' => 'needs_review']);
        AgentRun::create(['website_id' => $w->id, 'status' => 'failed', 'cost_vnd' => 0]);
        $this->actingAs($this->user());

        $this->get('/admin/doi-ai/tong-quan')->assertOk()->assertSee('1 bài chờ bạn duyệt')->assertSee('1 phiên bị chặn hoặc lỗi trong 7 ngày qua')->assertDontSee('bản nháp chờ bạn duyệt');
    }

    public function test_doi_ai_co_chien_dich_bat_khong_ghi_dang_nghi(): void
    {
        $w = $this->site();
        AgentSetting::create(['provider' => 'gemini', 'api_key' => 'AIza-test-1234', 'model' => 'm']);
        Campaign::create(['website_id' => $w->id, 'name' => 'CD', 'status' => 'active', 'daily_limit' => 5, 'keywords' => ['a']]);
        $this->actingAs($this->user());

        $this->get('/admin/doi-ai/tong-quan')->assertOk()->assertDontSee('Đang nghỉ')->assertSee('1 chiến dịch đang bật');
    }

    public function test_nhap_csv_co_cot_luot_tim_dien_volume_va_nguon_csv(): void
    {
        $w = $this->site();
        $r = (new CsvKeywordParser)->parse("keyword,volume\nhọc bổng pháp,720\nđăng ký thử,\n", []);
        (new KeywordService(new EloquentKeywordRepository($w->id)))->addRows($r['rows'], 'csv');

        $k = Keyword::where('website_id', $w->id)->orderBy('id')->get();
        $this->assertSame(720, (int) $k[0]->volume);
        $this->assertSame('csv', $k[0]->volume_source);
        $this->assertNull($k[1]->volume);
        $this->assertNull($k[1]->volume_source);
    }

    public function test_nut_them_vao_ke_hoach_chi_hien_o_tu_khoa_chua_dung(): void
    {
        $w = $this->site();
        $page = new KeywordsPage;
        $all = [
            ['id' => 1, 'keyword' => 'chưa dùng', 'volume' => null, 'volume_source' => null, 'intent' => 'informational', 'source' => 'tay', 'plan' => null],
            ['id' => 2, 'keyword' => 'đã có kế hoạch', 'volume' => 10, 'volume_source' => 'csv', 'intent' => 'informational', 'source' => 'tay', 'plan' => 'planned'],
            ['id' => 3, 'keyword' => 'đã có bài', 'volume' => 10, 'volume_source' => 'csv', 'intent' => 'informational', 'source' => 'tay', 'plan' => 'article'],
        ];
        $rows = $page->keywordRows($all);

        $this->assertSame([true, false, false], array_column($rows, 'canPlan'));
        $this->assertNull($rows[0]['volume']);
        $plan = collect($page->keywordColumns())->firstWhere('key', 'act')['actions'][0];
        $this->assertSame('canPlan', $plan['showIf']);
    }

    public function test_bang_dung_chung_khong_bien_null_thanh_so_0(): void
    {
        $js = file_get_contents(public_path('js/studio-ui.js'));
        $this->assertStringContainsString("v === null || v === undefined || v === '') return null", $js);
        $this->assertStringContainsString('actionsFor(row, c)', file_get_contents(resource_path('views/components/studio/data-table.blade.php')));
    }
}
