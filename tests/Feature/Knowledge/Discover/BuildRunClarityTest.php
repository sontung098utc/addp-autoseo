<?php

namespace Tests\Feature\Knowledge\Discover;

use App\Contracts\StructuredAiInterface;
use App\Filament\Resources\AgentRunResource;
use App\Models\AgentRun;
use App\Models\User;
use App\Models\Website;
use App\Services\Knowledge\Build\SafePageFetcher;
use App\Services\Knowledge\Discover\SourceDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Knowledge\Build\FakeSafety;
use Tests\TestCase;

/** Phiên "Xây kho trí tuệ" phải nói rõ: xây cho chủ đề nào, lỗi vì sao, kết quả thật (0 mục thì không ghi "bản nháp chờ duyệt"), và không đẻ phiên trùng. AI GIẢ, Http GIẢ. */
class BuildRunClarityTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->site = Website::create(['name' => 'ADDP', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->user = User::create(['name' => 'u', 'email' => 'u@x.vn', 'password' => 'secret123', 'role' => 'approver', 'is_active' => true]);
    }

    private function page(): string
    {
        return '<html><head><title>Ăn uống</title></head><body><main><h1>Ăn uống</h1><p>Người tiểu đường nên ăn nhiều rau xanh và hạn chế đồ ngọt.</p><p>'.str_repeat('Thông tin tham khảo dành cho cộng đồng. ', 6).'</p></main></body></html>';
    }

    private function ai(callable $f): object
    {
        return new class($f) implements StructuredAiInterface
        {
            public function __construct(private $f) {}

            public function generateStructured(string $system, string $user): array
            {
                return ($this->f)();
            }
        };
    }

    private function svc(?object $ai): SourceDiscoveryService
    {
        return new SourceDiscoveryService(new SafePageFetcher(new FakeSafety, 0), null, null, null, $ai, null, 'AI giả');
    }

    private function imp(array $urls, ?object $ai, array $opt = []): array
    {
        return $this->svc($ai)->import($this->site, array_map(fn ($u) => ['url' => $u], $urls), $this->user,
            $opt + ['topic' => 'thuốc hạ đường huyết', 'purpose' => 'gap', 'search' => ['query' => 'thuốc hạ đường huyết hướng dẫn', 'results' => 2, 'cost_vnd' => 200.0, 'provider' => 'tavily']]);
    }

    public function test_chu_de_luu_va_hien_o_danh_sach_va_chi_tiet(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        $this->imp(['https://who.int/a'], null);
        $run = AgentRun::latest('id')->first();
        $this->assertSame('thuốc hạ đường huyết', $run->input_refs['topic']);

        $this->actingAs($this->user);
        $this->get(AgentRunResource::getUrl('index'))->assertOk()->assertSee('Xây kho trí tuệ: thuốc hạ đường huyết', false)->assertSee('tự bổ sung kho khi viết bài');
        $this->get(AgentRunResource::viewUrl($run))->assertOk()->assertSee('Xây kho trí tuệ: thuốc hạ đường huyết', false);
    }

    public function test_phien_cu_khong_co_chu_de_van_suy_ra_tu_buoc_tim_nguon(): void
    {
        $run = AgentRun::create(['website_id' => $this->site->id, 'job_type' => 'brain_build', 'status' => 'ready_for_review', 'ai_mode' => 'real', 'started_at' => now(), 'input_refs' => ['sources' => 2, 'purpose' => 'gap'], 'output_ref' => ['item_ids' => []]]);
        $run->steps()->create(['seq' => 1, 'stage' => 'search', 'role' => 'system', 'status' => 'completed', 'title' => 'Tìm nguồn trên web', 'data' => ['query' => 'đái tháo đường type 2 hướng dẫn'], 'cost_vnd' => 200]);
        $this->actingAs($this->user);
        $this->get(AgentRunResource::getUrl('index'))->assertOk()->assertSee('Xây kho trí tuệ: đái tháo đường type 2 hướng dẫn', false)
            ->assertSee('Xong nhưng không có mục mới')->assertDontSee('Đã có bản nháp chờ duyệt');
    }

    public function test_loi_tai_trang_hien_ro_ten_mien_ly_do_va_phien_la_loi_khong_phai_ban_nhap(): void
    {
        Http::fake(function ($req) {
            throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds');
        });
        $r = $this->imp(['https://who.int/a', 'https://cdc.gov/b'], null);

        $run = AgentRun::find($r['run_id']);
        $this->assertSame('failed', $run->status);
        $steps = $run->steps->where('stage', 'source');
        $this->assertCount(2, $steps);
        $this->assertSame('failed', $steps->first()->status);
        $this->assertStringContainsString('who.int', $steps->first()->detail);
        $this->assertStringContainsString('Hết thời gian chờ', $steps->first()->detail);
        $this->assertStringContainsString('AI chưa được gọi', $steps->first()->detail);
        $this->assertStringContainsString('who.int', (string) $run->outcome_reason);
        $this->actingAs($this->user);
        $this->get(AgentRunResource::getUrl('index'))->assertOk()->assertDontSee('Đã có bản nháp chờ duyệt');
        $this->get(AgentRunResource::viewUrl($run))->assertOk()->assertSee('Hết thời gian chờ', false);
    }

    public function test_phan_biet_loi_dns_robots_va_ma_http(): void
    {
        $this->assertStringContainsString('robots.txt', SourceDiscoveryService::explainFetch('robots', 'robots.txt của website không cho đọc trang này.', 'x.vn'));
        $this->assertStringContainsString('an toàn mạng', SourceDiscoveryService::explainFetch('blocked', 'IP nội bộ', 'x.vn'));
        $this->assertStringContainsString('2 MB', SourceDiscoveryService::explainFetch('too_large', 'Trang lớn hơn 2 MB.', 'x.vn'));
        Http::fake(function () {
            throw new ConnectionException('cURL error 6: Could not resolve host: khong-co.vn');
        });
        $p = (new SafePageFetcher(new FakeSafety, 0))->fetchExternal('https://khong-co.vn/a');
        $this->assertFalse($p['ok']);
        $this->assertStringContainsString('DNS', $p['message']);
    }

    public function test_ai_het_han_muc_429_la_buoc_loi_co_ly_do_ro_va_token_0(): void
    {
        Http::fake(['*robots.txt' => Http::response('', 404), '*' => Http::response($this->page(), 200, ['Content-Type' => 'text/html'])]);
        $ai = $this->ai(fn () => throw new \RuntimeException('HTTP 429: rate limit exceeded, quota hết'));
        $r = $this->imp(['https://who.int/a'], $ai);

        $run = AgentRun::find($r['run_id']);
        $step = $run->steps->where('stage', 'source')->first();
        $this->assertSame('failed', $step->status, 'tải được trang nhưng AI không rút được mục nào: không được ghi Xong');
        $this->assertStringContainsString('hạn mức', $step->detail);
        $this->assertStringContainsString('429', $step->detail);
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('hạn mức', (string) $run->outcome_reason);
    }

    public function test_ai_tra_sai_cau_truc_json_noi_dung_model_yeu(): void
    {
        $this->assertStringContainsString('JSON', (string) SourceDiscoveryService::explainAi(['error' => 'AI trả về sai cấu trúc JSON (thiếu "items").'], 'who.int', 0));
        $this->assertStringContainsString('cấu hình', (string) SourceDiscoveryService::explainAi(['error' => 'Chưa cấu hình route AI knowledge'], 'who.int', 0));
        $this->assertNull(SourceDiscoveryService::explainAi([], 'who.int', 3));
    }

    public function test_ai_chay_duoc_nhung_khong_muc_hop_le_thi_khong_bao_ban_nhap_cho_duyet(): void
    {
        Http::fake(['*robots.txt' => Http::response('', 404), '*' => Http::response($this->page(), 200, ['Content-Type' => 'text/html'])]);
        $ai = $this->ai(fn () => ['data' => ['items' => []], 'tokens_used' => 10, 'cost_vnd' => 5.0, 'model' => 'fake']);
        $r = $this->imp(['https://who.int/a'], $ai);

        $run = AgentRun::find($r['run_id']);
        $this->assertSame('warn', $run->steps->where('stage', 'source')->first()->status);
        $this->assertSame('failed', $run->status);
        $this->actingAs($this->user);
        $this->get(AgentRunResource::getUrl('index'))->assertOk()->assertDontSee('Đã có bản nháp chờ duyệt');
    }

    public function test_co_muc_thi_ghi_so_muc_that(): void
    {
        Http::fake(['*robots.txt' => Http::response('', 404), '*' => Http::response($this->page(), 200, ['Content-Type' => 'text/html'])]);
        $ai = $this->ai(fn () => ['data' => ['items' => [['title' => 'Ăn', 'body' => 'Nên ăn nhiều rau xanh.', 'type' => 'fact', 'topic' => 'Chung', 'tags' => [], 'confidence' => 90, 'source_span' => 'nên ăn nhiều rau xanh và hạn chế đồ ngọt']]], 'tokens_used' => 10, 'cost_vnd' => 5.0, 'model' => 'fake']);
        $r = $this->imp(['https://who.int/a'], $ai);

        $run = AgentRun::find($r['run_id']);
        $this->assertSame('ready_for_review', $run->status);
        $this->actingAs($this->user);
        $this->get(AgentRunResource::getUrl('index'))->assertOk()->assertSee('Đã nạp 1 mục', false);
    }

    public function test_luot_he_thong_giong_het_trong_10_phut_khong_mo_them_phien_nhung_tay_thi_van_chay(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        $first = $this->imp(['https://who.int/a'], null, ['as_system' => true]);
        $second = $this->imp(['https://who.int/a'], null, ['as_system' => true]);
        $this->assertSame($first['run_id'], $second['run_id']);
        $this->assertSame('duplicate', $second['stopped_reason']);
        $this->assertSame(1, AgentRun::where('job_type', 'brain_build')->count());

        $other = $this->imp(['https://who.int/a'], null, ['as_system' => true, 'topic' => 'chủ đề khác', 'search' => ['query' => 'khác']]);
        $this->assertNotSame($first['run_id'], $other['run_id']);

        $this->imp(['https://who.int/a'], null); // người bấm tay: không chặn
        $this->assertSame(3, AgentRun::where('job_type', 'brain_build')->count());

        $this->travel(11)->minutes();
        $again = $this->imp(['https://who.int/a'], null, ['as_system' => true]);
        $this->assertNotSame($first['run_id'], $again['run_id'], 'hết cửa sổ thì chạy lại được');
    }
}
