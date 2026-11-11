<?php

namespace Tests\Feature\Knowledge\Discover;

use App\Contracts\StructuredAiInterface;
use App\Filament\Pages\BrainBuildPage;
use App\Models\KnowledgeItem;
use App\Models\User;
use App\Models\Website;
use App\Services\Knowledge\Build\SafePageFetcher;
use App\Services\Knowledge\Discover\SourceDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Feature\Knowledge\Build\FakeSafety;
use Tests\TestCase;

/** Mục "Khám phá nguồn trên web" của trang Xây kho. Tavily, trang ngoài và AI đều giả. */
class BrainBuildDiscoverPageTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->site = Website::create(['name' => 'Ánh Dương', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function user(string $role = 'approver'): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function fakeWeb(): void
    {
        config(['search.tavily.key' => 'tvly-T']);
        $ai = new class implements StructuredAiInterface
        {
            public function generateStructured(string $system, string $user): array
            {
                return ['data' => ['items' => [['title' => 'Rau xanh', 'body' => 'Nên ăn nhiều rau xanh.', 'type' => 'fact', 'topic' => 'Chung', 'tags' => [], 'confidence' => 90, 'source_span' => 'nên ăn nhiều rau xanh']]], 'tokens_used' => 1, 'cost_vnd' => 5.0, 'model' => 'fake'];
            }
        };
        $this->app->instance(SourceDiscoveryService::class, new SourceDiscoveryService(new SafePageFetcher(new FakeSafety, 0), null, null, null, $ai, null, 'AI giả'));
        Http::fake([
            'api.tavily.com/search' => Http::response(['results' => [
                ['title' => 'Hướng dẫn WHO', 'url' => 'https://who.int/a', 'content' => 'Tóm tắt WHO', 'score' => 0.9],
                ['title' => 'Blog sức khỏe', 'url' => 'https://blog.vn/b', 'content' => 'Tóm tắt blog', 'score' => 0.8],
            ]]),
            '*/robots.txt' => Http::response('', 404),
            'who.int/a' => Http::response('<html><head><title>WHO</title></head><body><p>Người bệnh nên ăn nhiều rau xanh mỗi ngày.</p><p>'.str_repeat('Thông tin tham khảo cho cộng đồng. ', 8).'</p></body></html>', 200, ['Content-Type' => 'text/html']),
            'blog.vn/b' => Http::response('<html><head><title>Blog</title></head><body><p>Người bệnh nên ăn nhiều rau xanh mỗi ngày.</p><p>'.str_repeat('Nội dung khác hẳn của blog này. ', 8).'</p></body></html>', 200, ['Content-Type' => 'text/html']),
        ]);
    }

    public function test_thieu_khoa_hien_huong_dan_nut_tat_khong_loi(): void
    {
        config(['search.tavily.key' => '']);
        Http::fake();
        $this->actingAs($this->user());
        $c = Livewire::test(BrainBuildPage::class)->assertSee('Khám phá nguồn trên web')->assertSee('TAVILY_API_KEY', false)->assertSeeHtml('data-testid="bb-web-off"');
        $c->set('topic', 'tiểu đường')->call('discover')->assertNotified()->assertSet('candidates', []);
        Http::assertNothingSent();
    }

    public function test_topic_tu_url_tim_chon_them_va_bao_cao(): void
    {
        $this->fakeWeb();
        $this->actingAs($this->user());
        $c = Livewire::withQueryParams(['topic' => 'người tiểu đường nên ăn gì'])->test(BrainBuildPage::class)->assertSet('topic', 'người tiểu đường nên ăn gì');
        $c->call('discover')->assertSee('Hướng dẫn WHO')->assertSee('Lý do xếp')->assertSee('Tin cậy')->assertSee('Chưa rõ');
        $this->assertSame('https://who.int/a', $c->get('candidates')[0]['url']);
        $this->assertStringContainsString('target="_blank"', $c->html());
        $this->assertStringContainsString('rel="noopener noreferrer"', $c->html());

        $c->set('picked', ['https://blog.vn/b'])->call('addPicked')->assertSee('Đã đọc 1 nguồn');
        $this->assertSame('proposed', KnowledgeItem::first()->status);
        $this->assertSame(1, $c->get('webReport')['items_proposed']);
    }

    public function test_them_tat_ca_tin_cay_mot_cu_bam_tu_nhan_va_hoan_tac(): void
    {
        $this->fakeWeb();
        $this->actingAs($this->user());
        $c = Livewire::test(BrainBuildPage::class)->set('topic', 'tiểu đường')->call('discover')->call('addAllTrusted');
        $this->assertSame(1, $c->get('webReport')['imported']);
        $this->assertSame(1, $c->get('webReport')['items_auto']);
        $c->assertSee('Mục đã tự nhận (1)')->assertSee('web: who.int');
        $item = KnowledgeItem::first();
        $this->assertSame('approved', $item->status);
        $c->call('undo', $item->id);
        $this->assertSame('proposed', $item->fresh()->status);
        Http::assertNotSent(fn ($r) => str_contains((string) $r->url(), 'blog.vn/b'));
    }

    public function test_quyen_writer_khong_vao_trang(): void
    {
        $this->actingAs($this->user('writer'))->get('/admin/kho-tri-tue/tao-tri-tue-doanh-nghiep')->assertForbidden();
    }
}
