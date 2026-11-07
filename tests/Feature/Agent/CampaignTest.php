<?php

namespace Tests\Feature\Agent;

use App\Filament\Resources\CampaignResource;
use App\Filament\Resources\CampaignResource\Pages\ListCampaigns;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\Campaign;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Run\CampaignRunner;
use App\Services\Ai\DemoScriptedAiProvider;
use App\Services\Publishing\EmergencyStopService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** AG-S01c: chiến dịch tự giao việc có rào chắn, nút chạy một nhịp, bản đồ luồng. */
class CampaignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(EmergencyStopService::class)->resetForTesting();
    }

    protected function tearDown(): void
    {
        app(EmergencyStopService::class)->resetForTesting();
        parent::tearDown();
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function campaign(array $over = []): Campaign
    {
        $wid = $over['website_id'] ?? Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active'])->id;
        unset($over['website_id']);

        return Campaign::create($over + ['website_id' => $wid, 'name' => 'CD1', 'status' => 'active', 'daily_limit' => 2, 'keywords' => ['dịch vụ thiết kế website', 'tối ưu seo cho website', 'quảng cáo google']]);
    }

    private function runner(): CampaignRunner
    {
        return new CampaignRunner(fn () => new DemoScriptedAiProvider());
    }

    public function test_nhip_giao_tu_khoa_ke_tiep_ghi_chien_dich_va_trigger_lich(): void
    {
        $c = $this->campaign();
        $r = $this->runner()->tick($c);

        $this->assertSame('ran', $r['result']);
        $this->assertSame($c->id, $r['run']->campaign_id);
        $this->assertSame('schedule', $r['run']->trigger);
        $this->assertSame('dịch vụ thiết kế website', $r['run']->article->primary_keyword);
        $this->assertSame(0, \App\Models\Publication::count());
    }

    public function test_bo_qua_tu_khoa_da_co_bai_va_het_tu_khoa_thi_dung(): void
    {
        $c = $this->campaign(['keywords' => ['A b c', 'x y z']]);
        Article::create(['website_id' => $c->website_id, 'primary_keyword' => 'a B C', 'workflow_status' => 'planned']);

        $this->assertSame('x y z', $this->runner()->nextKeyword($c));
        $this->runner()->tick($c);
        $this->assertSame('no_keywords', $this->runner()->tick($c)['result']);
    }

    public function test_rao_chan_gioi_han_bai_moi_ngay(): void
    {
        $c = $this->campaign();
        $this->runner()->tick($c);
        $this->runner()->tick($c);
        $third = $this->runner()->tick($c);

        $this->assertSame('limit', $third['result']);
        $this->assertSame(2, AgentRun::count());
        $this->assertSame(1, $c->refresh()->website->articles()->where('primary_keyword', 'quảng cáo google')->count() === 0 ? 1 : 0);
    }

    public function test_ngay_hom_sau_tiep_tuc_giao_viec(): void
    {
        $c = $this->campaign(['daily_limit' => 1]);
        $now = CarbonImmutable::parse('2026-11-07 03:00:00', 'UTC');
        $this->assertSame('ran', $this->runner()->tick($c, $now)['result']);
        $this->assertSame('limit', $this->runner()->tick($c, $now->addHour())['result']);
        AgentRun::query()->update(['started_at' => $now]);
        $this->assertSame('ran', $this->runner()->tick($c, $now->addDay())['result']);
    }

    public function test_tam_dung_dung_khan_cap_va_website_loi_khong_giao_viec(): void
    {
        $paused = $this->campaign(['status' => 'paused']);
        $this->assertSame('paused', $this->runner()->tick($paused)['result']);

        $c = $this->campaign(['website_id' => $paused->website_id, 'name' => 'CD2']);
        app(EmergencyStopService::class)->stop($this->user('approver'), 'Kiểm tra', null);
        $this->assertSame('stopped', $this->runner()->tick($c)['result']);
        app(EmergencyStopService::class)->resetForTesting();

        $c->website->update(['status' => 'error']);
        $this->assertSame('site_inactive', $this->runner()->tick($c->refresh())['result']);
        $this->assertSame(0, AgentRun::count());
    }

    public function test_thieu_ai_that_thi_khong_chay_va_khong_tao_bai(): void
    {
        $c = $this->campaign();
        $r = (new CampaignRunner(fn () => null))->tick($c);

        $this->assertSame('no_ai', $r['result']);
        $this->assertSame(0, Article::count());
        $this->assertSame(0, AgentRun::count());
    }

    public function test_lenh_agent_tick_chay_chien_dich_dang_hoat_dong(): void
    {
        $this->campaign();
        $this->artisan('agent:tick', ['--demo' => true])->expectsOutputToContain('ran')->assertSuccessful();
        $this->assertSame(1, AgentRun::count());
    }


    public function test_luot_tu_dong_khong_dung_playbook_chua_active(): void
    {
        $c = $this->campaign();
        $r = $this->runner()->tick($c);

        $brief = $r['run']->steps->firstWhere('stage', 'brief');
        $this->assertNull($brief->data['playbook']);
        $this->assertStringContainsString('không dùng', $brief->detail);
    }



    public function test_quyen_va_nut_tren_giao_dien(): void
    {
        $c = $this->campaign();

        $this->actingAs($this->user('writer'));
        $this->get(CampaignResource::getUrl('index'))->assertForbidden();
        $this->get('/admin/doi-ai/tong-quan')->assertForbidden();
    }

    public function test_nguoi_duyet_thay_danh_sach_chien_dich(): void
    {
        $c = $this->campaign();
        $this->actingAs($this->user('approver'));
        $this->get(CampaignResource::getUrl('index'))->assertOk()->assertSee('Chiến dịch')->assertSee('0 / 2');
    }

    public function test_nut_chay_mot_nhip_giao_viec_va_cap_nhat_dem_trong_ngay(): void
    {
        $c = $this->campaign();
        $this->actingAs($this->user('approver'));

        // Ngoài môi trường local và không có khóa AI thật: nút KHÔNG chạy và không tạo gì.
        Livewire::test(ListCampaigns::class)->callTableAction('tick', $c)->assertHasNoTableActionErrors();
        $this->assertSame(0, AgentRun::count());
        $this->assertSame(0, Article::count());

        $this->app->detectEnvironment(fn () => 'local');
        Livewire::test(ListCampaigns::class)->callTableAction('tick', $c)->assertHasNoTableActionErrors();
        $this->assertSame(1, AgentRun::count());
        $this->assertSame('mock', AgentRun::first()->ai_mode);
        $this->assertSame(1, CampaignResource::todayCount($c));
        $this->assertSame(2, CampaignResource::remaining($c));
    }

    public function test_ban_do_luong_hien_so_lieu_that_va_phan_dev1(): void
    {
        $this->runner()->tick($this->campaign());
        $this->actingAs($this->user('approver'));

        $this->get('/admin/doi-ai/tong-quan')->assertOk()
            ->assertSee('Cách dùng: 4 bước')->assertSee('Đường đi của một bài')->assertSee('Dev1 cần (AG-B06)')
            ->assertSee('1 phiên chạy thật');
    }

    public function test_ban_do_luong_rong_noi_that_la_chua_co_du_lieu(): void
    {
        $this->actingAs($this->user('admin'));

        $this->get('/admin/doi-ai/tong-quan')->assertOk()->assertSee('0 phiên chạy thật')->assertSee('chưa có số liệu');
    }
}
