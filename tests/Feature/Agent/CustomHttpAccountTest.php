<?php

namespace Tests\Feature\Agent;

use App\Filament\Pages\AiUsagePage;
use App\Models\AiProvider;
use App\Models\AiProviderModel;
use App\Models\User;
use App\Services\Agent\Ai\AiModelCatalog;
use App\Services\Images\Http\HttpApiSpec;
use App\Services\Security\UrlSafetyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Tài khoản AI kiểu "API tùy chỉnh" qua trang Cấu hình AI. Http::fake, chưa chạy với dịch vụ thật. */
class CustomHttpAccountTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'sk_live_CUSTOMKEY9876543210zzzz';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::create(['name' => 'a', 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => 'admin', 'is_active' => true]));
        $this->mock(UrlSafetyService::class, fn ($m) => $m->shouldReceive('validateAiBaseUrl')->andReturn(true));
    }

    private function cfg()
    {
        return Livewire::withQueryParams(['tab' => 'cau-hinh'])->test(AiUsagePage::class);
    }

    private function enc(array $spec): string
    {
        return json_encode($spec, JSON_UNESCAPED_UNICODE);
    }

    private function add(array $over = []): AiProvider
    {
        $this->cfg()->callAction('addProvider', $over + ['preset' => 'sangtao', 'name' => 'sangtao.ai', 'base_url' => 'https://api.sangtao.test/v2', 'api_key' => self::KEY,
            'http_config' => $this->enc(HttpApiSpec::preset('sangtao')), 'models' => ['gpt-image-2.6'], 'suggest' => false])->assertHasNoActionErrors();

        return AiProvider::query()->latest('id')->firstOrFail();
    }

    public function test_add_custom_http_account_stores_config_encrypted_key_and_image_model(): void
    {
        $p = $this->add();

        $this->assertSame('custom_http', $p->kind);
        $this->assertSame('sangtao', $p->http_config['preset']);
        $this->assertSame('X-Api-Key', $p->http_config['auth']['name']);
        $this->assertSame('sangtao', AiModelCatalog::brandOf($p));
        $this->assertSame(self::KEY, $p->api_key);
        $this->assertStringNotContainsString(self::KEY, (string) DB::table('ai_providers')->where('id', $p->id)->value('api_key')); // khóa mã hóa trong DB
        $this->assertStringNotContainsString(self::KEY, (string) DB::table('ai_providers')->where('id', $p->id)->value('http_config'));
        $this->assertSame('image', AiModelCatalog::kindOf($p, 'gpt-image-2.6'));
    }

    public function test_invalid_json_config_or_missing_base_url_is_rejected(): void
    {
        $base = ['preset' => 'sangtao', 'name' => 'x', 'base_url' => 'https://api.sangtao.test/v2', 'api_key' => self::KEY, 'models' => [], 'suggest' => false];
        $this->cfg()->callAction('addProvider', $base + ['http_config' => '{không phải json'])->assertHasActionErrors();
        $this->cfg()->callAction('addProvider', $base + ['http_config' => $this->enc(['auth' => ['type' => 'cookie']] + HttpApiSpec::preset('sangtao'))])->assertHasActionErrors();
        $this->assertSame(0, AiProvider::query()->count());
    }

    public function test_connection_test_uses_get_not_chat_and_reports_bad_key(): void
    {
        $p = $this->add();
        Http::fake(['api.sangtao.test/v2/models' => Http::sequence()->push(['models' => []])->push([], 401)]);

        $this->cfg()->callAction('testProvider', [], ['id' => $p->id]);
        $this->assertTrue($p->fresh()->last_test_ok);

        $this->cfg()->callAction('testProvider', [], ['id' => $p->id]);
        $p = $p->fresh();
        $this->assertFalse($p->last_test_ok);
        $this->assertStringContainsString('401', $p->last_test_message);
        $this->assertStringNotContainsString(self::KEY, $p->last_test_message);
        Http::assertNotSent(fn ($q) => str_contains($q->url(), 'chat') || $q->method() === 'POST');
    }

    public function test_sync_models_marks_custom_http_models_as_image(): void
    {
        $p = $this->add();
        Http::fake(['api.sangtao.test/v2/models*' => Http::response(['models' => [['slug' => 'gpt-image-2.6', 'displayName' => 'GPT Image 2.6'], ['slug' => 'model-moi', 'displayName' => 'Model mới']]])]);

        $this->cfg()->callAction('syncModels');

        $this->assertSame(['gpt-image-2.6', 'model-moi'], AiProviderModel::query()->where('ai_provider_id', $p->id)->orderBy('model_id')->pluck('model_id')->all());
        $this->assertSame(['image'], AiProviderModel::query()->where('ai_provider_id', $p->id)->pluck('kind')->unique()->values()->all());
    }

    public function test_edit_updates_config_and_rejects_bad_config(): void
    {
        $p = $this->add();
        $new = HttpApiSpec::preset('sangtao');
        $new['async']['interval_s'] = 7;
        $edit = ['name' => 'sangtao.ai', 'base_url' => 'https://api.sangtao.test/v2', 'enabled' => true, 'models' => [], 'custom_models' => [], 'custom_image_models' => ['gpt-image-2.6']];

        $this->cfg()->callAction('editProvider', $edit + ['http_config' => $this->enc($new)], ['id' => $p->id])->assertHasNoActionErrors();
        $this->assertSame(7, $p->fresh()->http_config['async']['interval_s']);

        $this->cfg()->callAction('editProvider', $edit + ['http_config' => $this->enc(['mode' => 'async'])], ['id' => $p->id])->assertHasActionErrors();
        $this->assertSame(7, $p->fresh()->http_config['async']['interval_s']);
    }
}
