<?php

namespace Tests\Feature\Agent;

use App\Filament\Resources\CampaignResource;
use App\Filament\Resources\CampaignResource\Pages\CreateCampaign;
use App\Filament\Resources\CampaignResource\Pages\ListCampaigns;
use App\Filament\Resources\CampaignResource\Pages\ViewCampaign;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\Campaign;
use App\Models\User;
use App\Models\Website;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Trạng thái chiến dịch phản ánh việc THẬT; "Bắt đầu chạy" bật và giao bài ngay; không còn ô chọn trạng thái trong cấu hình. */
class CampaignStartTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function campaign(array $over = []): Campaign
    {
        $w = Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $c = Campaign::create($over + ['website_id' => $w->id, 'name' => 'CD1', 'status' => 'paused', 'source' => 'plan', 'daily_limit' => 2, 'keywords' => []]);
        Article::create(['website_id' => $w->id, 'primary_keyword' => 'bị tai biến bao lâu thì khỏi', 'workflow_status' => 'planned', 'campaign_id' => $c->id, 'priority' => 90]);
        Article::create(['website_id' => $w->id, 'primary_keyword' => 'dấu hiệu tai biến sớm', 'workflow_status' => 'planned', 'campaign_id' => $c->id, 'priority' => 40]);

        return $c;
    }

    public function test_cau_hinh_khong_con_o_chon_trang_thai(): void
    {
        $this->actingAs($this->user('admin'));
        Livewire::test(CreateCampaign::class)->assertFormFieldDoesNotExist('status');
        $w = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://s.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->assertSame('paused', Campaign::create(['website_id' => $w->id, 'name' => 'CD mới', 'keywords' => []])->refresh()->status, 'chiến dịch mới luôn tạm dừng cho tới khi bấm Bắt đầu chạy');
    }

    public function test_trang_thai_phan_anh_viec_that(): void
    {
        $c = $this->campaign();
        $this->assertSame('paused', CampaignResource::stateOf($c)['key']);

        $c->update(['status' => 'active', 'last_tick_at' => null]);
        $s = CampaignResource::stateOf($c->refresh());
        $this->assertSame('no_tick', $s['key'], 'đã bật nhưng chưa từng có lượt tự động');
        $this->assertStringContainsString('Đã bật', $s['label']);
        $this->assertStringNotContainsString('Đang chạy', $s['label'], 'không được nói "đang chạy" khi chưa chạy');
        $this->assertStringContainsString('scheduler', $s['hint']);

        $c->update(['last_tick_at' => now()->subMinutes(5)]);
        $this->assertSame('waiting', CampaignResource::stateOf($c->refresh())['key']);

        $c->update(['last_tick_at' => now()->subMinutes(CampaignResource::TICK_MINUTES * 3)]);
        $this->assertSame('no_tick', CampaignResource::stateOf($c->refresh())['key'], 'lâu không có lượt tự động');

        $c->update(['last_tick_at' => now()]);
        foreach ([1, 2] as $i) {
            AgentRun::create(['article_id' => Article::first()->id, 'website_id' => $c->website_id, 'campaign_id' => $c->id, 'status' => 'ready_for_review', 'trigger' => 'schedule', 'started_at' => now(), 'ai_mode' => 'real']);
        }
        $this->assertSame('limit', CampaignResource::stateOf($c->refresh())['key'], 'đủ bài hôm nay');
    }

    public function test_bat_dau_chay_bat_chien_dich_va_giao_bai_ngay_khong_cho_lich(): void
    {
        $c = $this->campaign();
        $this->actingAs($this->user('approver'));
        $this->assertStringContainsString('bị tai biến bao lâu thì khỏi', CampaignResource::startDescription($c), 'nói rõ sẽ giao bài nào trước (ưu tiên cao nhất)');
        $this->assertStringContainsString('tối đa 2 bài mỗi ngày', CampaignResource::startDescription($c));

        $this->app->detectEnvironment(fn () => 'local');
        Livewire::test(ViewCampaign::class, ['record' => $c->id])->assertSee('Bắt đầu chạy')->assertDontSee('Giao bài ngay')->assertDontSee('Chạy một nhịp')
            ->callAction('start')->assertHasNoActionErrors();

        $c->refresh();
        $this->assertSame('active', $c->status);
        $this->assertNotNull($c->last_tick_at, 'đã giao một lượt ngay');
        $this->assertSame(1, AgentRun::where('campaign_id', $c->id)->count(), 'bài đầu được giao ngay, không chờ lịch');
        $this->assertSame('bị tai biến bao lâu thì khỏi', AgentRun::first()->article->primary_keyword);
    }

    public function test_bat_dau_chay_khi_chua_co_ai_that_van_bat_nhung_noi_ro_khong_chay(): void
    {
        $c = $this->campaign();
        $this->actingAs($this->user('approver'));
        Livewire::test(ListCampaigns::class)->callTableAction('start', $c)->assertHasNoTableActionErrors();

        $c->refresh();
        $this->assertSame('active', $c->status, 'bấm Bắt đầu chạy là bật chiến dịch');
        $this->assertNotNull($c->last_tick_at, 'đã thử giao một lượt ngay (có chạy được hay không còn tùy AI đã cấu hình)');
        $this->assertNotSame('Đang chạy', CampaignResource::stateOf($c)['label'], 'trạng thái không bao giờ ghi "Đang chạy" chung chung');
    }

    public function test_tam_dung_va_giao_1_bai_ngay_chi_hien_khi_dang_bat(): void
    {
        $c = $this->campaign(['status' => 'active', 'last_tick_at' => now()]);
        $this->actingAs($this->user('approver'));
        Livewire::test(ViewCampaign::class, ['record' => $c->id])->assertSee('Tạm dừng')->assertSee('Giao bài ngay')->assertDontSee('Bắt đầu chạy')
            ->callAction('pause');
        $this->assertSame('paused', $c->refresh()->status);

        Livewire::test(ViewCampaign::class, ['record' => $c->id])->assertSee('Bắt đầu chạy')->assertDontSee('Giao bài ngay');
    }

    public function test_writer_khong_bat_duoc_chien_dich(): void
    {
        $c = $this->campaign();
        $this->actingAs($this->user('writer'));
        try {
            CampaignResource::start($c);
            $this->fail('writer phải bị chặn');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame('paused', $c->refresh()->status);
    }
}
