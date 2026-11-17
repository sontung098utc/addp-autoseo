<?php

namespace Tests\Feature\Agent;

use App\Filament\Clusters\AgentTeam\Pages\AgentRoles;
use App\Filament\Pages\AiUsagePage;
use App\Models\AgentRoleAssignment;
use App\Models\AiProvider;
use App\Models\AiProviderModel;
use App\Models\User;
use App\Services\Agent\Ai\AiModelCatalog;
use App\Services\Agent\Ai\AiModelSync;
use App\Services\Security\UrlSafetyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Đồng bộ model thật từ API hãng (Http giả): giữ model cũ, đánh dấu mới/ngưng, không bao giờ đụng bản gán vai trò; lỗi mạng không làm đổi trạng thái. */
class AiModelSyncTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'sk-test-SECRETKEY-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(UrlSafetyService::class, new class extends UrlSafetyService
        {
            protected function resolveIps(string $host): array
            {
                return filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ['93.184.216.34'];
            }
        });
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function gemini(?array $models = null): AiProvider
    {
        return AiProvider::create(['name' => 'Gemini', 'kind' => 'gemini', 'api_key' => self::KEY, 'enabled' => true, 'models' => $models ?? ['gemini-3.6-flash']]);
    }

    /** Http::fake lần sau không ghi đè lần trước (stub đầu tiên khớp thì thắng): dựng lại bộ giả cho mỗi kịch bản. */
    private function freshHttp(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
    }

    private function fakeGemini(array $ids, array $extra = []): void
    {
        $rows = array_map(fn ($id) => ['name' => 'models/'.$id, 'displayName' => strtoupper($id), 'supportedGenerationMethods' => ['generateContent']], $ids);
        $this->freshHttp();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['models' => array_merge($rows, $extra)])]);
    }

    public function test_lan_dau_la_nen_khong_dan_nhan_moi_cho_ca_danh_sach(): void
    {
        $p = $this->gemini();
        $this->fakeGemini(['gemini-3.6-flash', 'gemini-3.5-flash-lite']);
        $r = (new AiModelSync)->sync($p);
        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['total']);
        $this->assertSame([], $r['added'], 'Lần đầu không báo "mới".');
        $this->assertSame([], AiModelSync::flags($p->fresh()));
        $this->assertNotNull($p->fresh()->models_synced_at);
    }

    public function test_model_moi_co_dau_moi_roi_tu_het_sau_7_ngay_va_khong_tu_bat(): void
    {
        $p = $this->gemini(['gemini-3.6-flash']);
        $this->fakeGemini(['gemini-3.6-flash']);
        (new AiModelSync)->sync($p);
        $this->freshHttp();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['models' => [
            ['name' => 'models/gemini-3.6-flash', 'supportedGenerationMethods' => ['generateContent']],
            ['name' => 'models/gemini-4-flash', 'displayName' => 'Gemini 4 Flash', 'supportedGenerationMethods' => ['generateContent']],
        ]])]);
        $r = (new AiModelSync)->sync($p->fresh());
        $this->assertSame(['gemini-4-flash'], $r['added']);
        $this->assertSame(['gemini-4-flash' => 'new'], AiModelSync::flags($p->fresh()));
        $this->assertSame(['gemini-3.6-flash'], array_column(AiModelCatalog::enabledModels($p->fresh()), 'id'), 'Model mới KHÔNG tự bật.');
        $this->assertSame('gemini-4-flash', AiModelSync::discoveredNotEnabled($p->fresh())[0]['id']);
        $this->assertTrue(AiModelSync::discoveredNotEnabled($p->fresh())[0]['is_new']);

        $this->travel(8)->days();
        $this->assertSame([], AiModelSync::flags($p->fresh()), 'Sau ~1 tuần dấu "Mới" tự hết.');
    }

    public function test_model_bien_mat_bi_danh_dau_ngung_nhung_khong_bi_xoa_va_ban_gan_vai_tro_giu_nguyen(): void
    {
        $p = $this->gemini(['gemini-3.6-flash', 'gemini-old']);
        $this->fakeGemini(['gemini-3.6-flash', 'gemini-old']);
        (new AiModelSync)->sync($p);
        AgentRoleAssignment::create(['role_key' => 'writer', 'provider_id' => $p->id, 'model' => 'gemini-old', 'enabled' => true]);

        $this->fakeGemini(['gemini-3.6-flash']); // gemini-old bị hãng gỡ
        $r = (new AiModelSync)->sync($p->fresh());
        $this->assertSame(['gemini-old'], $r['gone']);
        $this->assertSame(['gemini-old' => 'gone'], AiModelSync::flags($p->fresh()));
        $this->assertContains('gemini-old', array_column(AiModelCatalog::enabledModels($p->fresh()), 'id'), 'Model ngưng vẫn còn trong danh sách đã bật.');
        $a = AgentRoleAssignment::where('role_key', 'writer')->first();
        $this->assertSame('gemini-old', $a->model, 'Không bao giờ đổi bản gán vai trò.');
        $this->assertSame('writer', AiModelSync::goneInUse()[0]['role']);

        $this->travel(30)->days();
        $this->assertSame(['gemini-old' => 'gone'], AiModelSync::flags($p->fresh()), 'Model ngưng đang được dùng thì cảnh báo không tự mất.');
    }

    public function test_model_con_hoat_dong_giu_nguyen_va_model_quay_lai_duoc_bao(): void
    {
        $p = $this->gemini(['a', 'b']);
        $this->fakeGemini(['a', 'b']);
        (new AiModelSync)->sync($p);
        $this->fakeGemini(['a']);
        (new AiModelSync)->sync($p->fresh());
        $this->fakeGemini(['a', 'b']);
        $r = (new AiModelSync)->sync($p->fresh());
        $this->assertSame(['b'], $r['back']);
        $this->assertSame(['b' => 'new'], AiModelSync::flags($p->fresh()));
        $this->assertSame('active', AiProviderModel::where('model_id', 'a')->value('status'));
    }

    public function test_loi_mang_hoac_khoa_sai_khong_doi_trang_thai_nao(): void
    {
        $p = $this->gemini(['gemini-3.6-flash']);
        $this->fakeGemini(['gemini-3.6-flash', 'x']);
        (new AiModelSync)->sync($p);
        $before = AiProviderModel::query()->orderBy('model_id')->get(['model_id', 'status'])->toArray();

        $this->freshHttp();
        Http::fake(['*' => Http::response(['error' => ['message' => 'bad '.self::KEY]], 403)]);
        $r = (new AiModelSync)->sync($p->fresh());
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('khóa API không đúng', (string) $r['error']);
        $this->assertStringNotContainsString(self::KEY, (string) $r['error']);
        $this->assertSame($before, AiProviderModel::query()->orderBy('model_id')->get(['model_id', 'status'])->toArray(), 'Lỗi tạm thời không được làm model bị coi là ngừng.');
    }

    public function test_loc_bo_model_khong_dung_de_sinh_chu_hoac_anh(): void
    {
        $p = $this->gemini();
        $this->fakeGemini(['gemini-3.6-flash', 'text-embedding-9', 'gemini-4-live', 'gemini-nano-banana-3', 'gemini-robotics-er-2'], [['name' => 'models/khong-generate', 'supportedGenerationMethods' => ['embedContent']]]);
        (new AiModelSync)->sync($p);
        $ids = AiProviderModel::orderBy('model_id')->pluck('kind', 'model_id')->all();
        $this->assertSame(['gemini-3.6-flash' => 'text', 'gemini-nano-banana-3' => 'image'], $ids);
    }

    public function test_anthropic_va_openai_tuong_thich_goi_dung_dia_chi_va_header(): void
    {
        $a = AiProvider::create(['name' => 'Claude', 'kind' => 'anthropic', 'api_key' => self::KEY, 'enabled' => true, 'models' => ['claude-sonnet-5-5']]);
        Http::fake([
            'api.anthropic.com/v1/models*' => Http::response(['data' => [['id' => 'claude-sonnet-5-5', 'display_name' => 'Claude Sonnet 5.5']]]),
            'api.openai.com/v1/models' => Http::response(['data' => [['id' => 'gpt-5'], ['id' => 'text-embedding-3-large'], ['id' => 'whisper-1']]]),
        ]);
        $this->assertTrue((new AiModelSync)->sync($a)['ok']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.anthropic.com/v1/models') && $r->hasHeader('x-api-key', self::KEY) && $r->hasHeader('anthropic-version'));

        $o = AiProvider::create(['name' => 'GPT', 'kind' => 'openai_compat', 'base_url' => 'https://api.openai.com/v1', 'api_key' => self::KEY, 'enabled' => true, 'models' => ['gpt-5']]);
        $this->assertTrue((new AiModelSync)->sync($o)['ok']);
        $this->assertSame(['gpt-5'], AiProviderModel::where('ai_provider_id', $o->id)->pluck('model_id')->all(), 'Bỏ model nhúng và giọng nói.');
        Http::assertSent(fn ($r) => $r->url() === 'https://api.openai.com/v1/models' && $r->hasHeader('Authorization', 'Bearer '.self::KEY));
    }

    public function test_base_url_noi_bo_bi_chan_khong_goi_mang(): void
    {
        $this->app->instance(UrlSafetyService::class, new UrlSafetyService);
        Http::fake();
        $o = AiProvider::create(['name' => 'Noi bo', 'kind' => 'openai_compat', 'base_url' => 'http://127.0.0.1:8000/v1', 'api_key' => self::KEY, 'enabled' => true]);
        $r = (new AiModelSync)->sync($o);
        $this->assertFalse($r['ok']);
        Http::assertNothingSent();
    }

    public function test_nut_cap_nhat_chi_admin_dau_hieu_hien_tren_the_va_bat_model_moi_mot_cham(): void
    {
        $admin = $this->user('admin');
        $p = $this->gemini(['gemini-3.6-flash']);
        $this->fakeGemini(['gemini-3.6-flash']);
        (new AiModelSync)->sync($p);
        $this->fakeGemini(['gemini-3.6-flash', 'gemini-4-flash']);

        $this->actingAs($this->user('approver'));
        Livewire::test(AiUsagePage::class)->assertDontSee('data-testid="sync-models"', false);

        $this->actingAs($admin);
        $c = Livewire::test(AiUsagePage::class)->assertSee('data-testid="sync-models"', false)->call('mountAction', 'syncModels')->callMountedAction();
        $c->assertSee('Mới')->assertSee('discovered-models', false)->assertSee('GEMINI-4-FLASH', false);
        $c->call('enableDiscoveredModel', $p->id, 'gemini-4-flash');
        $this->assertContains('gemini-4-flash', array_column(AiModelCatalog::enabledModels($p->fresh()), 'id'));
        $this->assertContains('gemini-3.6-flash', array_column(AiModelCatalog::enabledModels($p->fresh()), 'id'), 'Model cũ vẫn còn.');

        $this->actingAs($this->user('approver'));
        Livewire::test(AiUsagePage::class)->call('enableDiscoveredModel', $p->id, 'gemini-4-flash')->assertForbidden();
    }

    public function test_trang_vai_tro_chan_chon_moi_model_ngung_nhung_giu_ban_gan_cu(): void
    {
        $this->actingAs($this->user('admin'));
        $p = $this->gemini(['gemini-3.6-flash', 'gemini-old']);
        $this->fakeGemini(['gemini-3.6-flash', 'gemini-old']);
        (new AiModelSync)->sync($p);
        $this->fakeGemini(['gemini-3.6-flash']);
        (new AiModelSync)->sync($p->fresh());

        $c = Livewire::test(AgentRoles::class)->call('openStudio', 'writer', 'tech');
        $c->call('pickModel', 'primary', $p->id, 'gemini-old');
        $this->assertNull(AgentRoleAssignment::where('role_key', 'writer')->first()?->model, 'Không cho chọn mới model đã ngưng.');
        $c->call('pickModel', 'primary', $p->id, 'gemini-3.6-flash');
        $this->assertSame('gemini-3.6-flash', AgentRoleAssignment::where('role_key', 'writer')->first()->model);
    }
}
