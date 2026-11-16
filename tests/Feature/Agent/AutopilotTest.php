<?php

namespace Tests\Feature\Agent;

use App\Models\Article;
use App\Models\Campaign;
use App\Models\Keyword;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Autopilot\AutopilotRunner;
use App\Services\Keywords\KeywordAiUnavailable;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

/** Autopilot: chiến dịch Toàn quyền tự giữ hàng chờ kế hoạch (AI giả tất định; mức bằng chứng: test giả lập). */
class AutopilotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(EmergencyStopService::class)->resetForTesting();
        Cache::flush();
    }

    private function campaign(string $mode = 'autonomous', array $over = []): Campaign
    {
        $w = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://'.uniqid().'.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $u = User::create(['name' => 'w', 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => 'writer', 'is_active' => true]);

        return Campaign::create($over + ['website_id' => $w->id, 'created_by' => $u->id, 'name' => 'CD', 'status' => 'active', 'mode' => $mode, 'source' => 'plan', 'daily_limit' => 3, 'keywords' => []]);
    }

    private function keywords(Campaign $c, int $n): void
    {
        foreach (range(1, $n) as $i) {
            Keyword::create(['website_id' => $c->website_id, 'keyword' => "chủ đề số $i", 'keyword_key' => "chủ đề số $i", 'volume' => 1000 - $i * 10, 'source' => 'csv', 'status' => 'new']);
        }
    }

    private function research(int &$calls, ?\Throwable $throw = null): \Closure
    {
        return function () use (&$calls, $throw) {
            $calls++;
            if ($throw) {
                throw $throw;
            }

            return new class
            {
                public function suggest($site)
                {
                    return ['added' => 5, 'metrics' => ['updated' => 0]];
                }
            };
        };
    }

    public function test_hang_cho_trong_thi_lap_ke_hoach_tu_tu_khoa_co_san(): void
    {
        $c = $this->campaign();
        $this->keywords($c, 6);
        $calls = 0;
        $r = (new AutopilotRunner(null, $this->research($calls)))->tick($c);

        $this->assertSame('acted', $r['result']);
        $this->assertSame(3, Article::where('website_id', $c->website_id)->where('workflow_status', 'planned')->count());
        $a = Article::where('website_id', $c->website_id)->first();
        $this->assertSame('plan', $a->origin);
        $this->assertSame($c->id, $a->campaign_id);
        $this->assertNotNull($a->keyword_id);
        $this->assertNotEmpty($r['actions']);
        $this->assertSame(0, $calls, 'còn đủ từ khóa mới thì không khảo sát lại');
    }

    public function test_chay_lai_khi_hang_cho_da_du_thi_khong_them_nua(): void
    {
        $c = $this->campaign();
        $this->keywords($c, 10);
        $calls = 0;
        $runner = new AutopilotRunner(null, $this->research($calls));
        $runner->tick($c);
        $before = Article::count();
        $r = $runner->tick($c);

        $this->assertSame('queue_ok', $r['result']);
        $this->assertSame($before, Article::count());
    }

    public function test_het_tu_khoa_thi_khao_sat_mot_lan_moi_24_gio(): void
    {
        $c = $this->campaign();
        $calls = 0;
        $runner = new AutopilotRunner(null, $this->research($calls));
        $r = $runner->tick($c);
        $this->assertSame(1, $calls);
        $this->assertStringContainsString('khảo sát', implode(' ', $r['actions']));

        $runner->tick($c);
        $this->assertSame(1, $calls, 'trong 24 giờ không khảo sát lại');
    }

    public function test_chua_co_ai_thi_bo_qua_khao_sat_va_van_lap_ke_hoach(): void
    {
        $c = $this->campaign();
        $this->keywords($c, 4);
        Keyword::where('website_id', $c->website_id)->limit(2)->update(['status' => 'planned']); // còn 2 từ mới < ngưỡng → thử khảo sát
        $calls = 0;
        $r = (new AutopilotRunner(null, $this->research($calls, new KeywordAiUnavailable('Chưa kết nối AI'))))->tick($c);

        $this->assertSame(1, $calls);
        $this->assertGreaterThan(0, Article::where('website_id', $c->website_id)->count());
        $this->assertNotSame('', $r['message']);
    }

    public function test_chi_che_do_toan_quyen_co_nguon_ke_hoach(): void
    {
        foreach (['cautious', 'balanced'] as $mode) {
            $c = $this->campaign($mode);
            $this->keywords($c, 5);
            $this->assertSame('skipped', (new AutopilotRunner)->tick($c)['result']);
        }
        $c = $this->campaign('autonomous', ['source' => 'keywords']);
        $this->assertSame('skipped', (new AutopilotRunner)->tick($c)['result']);
        $this->assertSame(0, Article::count());
    }

    public function test_lenh_va_lich(): void
    {
        $c = $this->campaign();
        $this->keywords($c, 5);
        $this->artisan('autopilot:tick')->expectsOutputToContain('acted')->assertSuccessful();
        $this->assertTrue(collect(Schedule::events())->contains(fn ($e) => str_contains((string) $e->command, 'autopilot:tick')));
    }
}
