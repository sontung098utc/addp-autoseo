<?php

namespace Tests\Feature\Agent;

use App\Models\AgentRoleAssignment;
use App\Models\AiModelRoute;
use App\Models\AiProvider;
use App\Models\Article;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Ai\AiModelCatalog;
use App\Services\Agent\Roles\RoleAssignments;
use App\Services\Agent\Roles\RoleRegistry;
use App\Services\Agent\Roles\RoleSwitches;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Services\Ai\DemoScriptedAiProvider;
use App\Services\Images\FallbackImageGenerator;
use App\Services\Images\GeminiImageGenerator;
use App\Services\Images\ImageGeneratorResolver;
use App\Services\Images\ImageGeneratorUnavailable;
use App\Services\Images\OpenAiCompatImageGenerator;
use App\Services\Publishing\EmergencyStopService;
use App\Services\Security\UrlSafetyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Phiên 3: loại model (Chữ|Ảnh), vai trò Họa viên giữ model ảnh, ImageGeneratorResolver đọc bản gán của vai trò. */
class AiImageRouteTest extends TestCase
{
    use RefreshDatabase;

    private function provider(string $kind, string $name, ?array $models): AiProvider
    {
        return AiProvider::create(['name' => $name, 'kind' => $kind, 'base_url' => $kind === 'openai_compat' ? 'http://172.17.0.1:8765/v1' : null,
            'api_key' => 'k-'.$name, 'enabled' => true, 'models' => $models]);
    }

    private function admin(): User
    {
        return User::create(['name' => 'a', 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => 'admin', 'is_active' => true]);
    }

    public function test_loai_model_suy_tu_ten_va_doc_duoc_du_lieu_cu(): void
    {
        $this->assertSame('image', AiModelCatalog::inferKind('agy-image'));
        $this->assertSame('image', AiModelCatalog::inferKind('imagen-4'));
        $this->assertSame('image', AiModelCatalog::inferKind('gemini-nano-banana-2.1'));
        $this->assertSame('text', AiModelCatalog::inferKind('claude-sonnet-5-5'));

        // Dữ liệu cũ: chuỗi tên. Dữ liệu mới: {id, kind} khi khác suy đoán.
        $p = $this->provider('openai_compat', 'CLI', ['agy-image', 'agy-flash-low', ['id' => 'weird-name', 'kind' => 'image'], ['id' => 'my-image-chat', 'kind' => 'text']]);
        $kinds = collect(AiModelCatalog::enabledModels($p))->pluck('kind', 'id')->all();
        $this->assertSame(['agy-image' => 'image', 'agy-flash-low' => 'text', 'weird-name' => 'image', 'my-image-chat' => 'text'], $kinds);
        $this->assertSame('agy-flash-low', AiModelCatalog::entryFor('agy-flash-low', 'text'));
        $this->assertSame(['id' => 'weird-name', 'kind' => 'image'], AiModelCatalog::entryFor('weird-name', 'image'));
    }

    public function test_danh_muc_gemini_co_model_anh_khong_bat_san(): void
    {
        $cat = collect(AiModelCatalog::catalog('gemini'))->keyBy('id');
        foreach (['gemini-3.1-flash-lite-image', 'gemini-nano-banana-2.1', 'gemini-3.1-flash-image', 'gemini-3-pro-image'] as $id) {
            $this->assertSame('image', $cat[$id]['kind'], $id);
            $this->assertFalse($cat[$id]['default'], $id);
        }
        $this->assertNotContains('gemini-3.1-flash-image', AiModelCatalog::defaults('gemini'));
    }

    public function test_form_tai_khoan_luu_model_chu_va_model_anh_nhap_tay(): void
    {
        $this->actingAs($this->admin());
        $p = $this->provider('openai_compat', 'CLI', ['agy-flash-low']);

        \Livewire\Livewire::test(\App\Filament\Pages\AiUsagePage::class)
            ->callAction('editProvider', ['name' => 'CLI', 'base_url' => 'http://172.17.0.1:8765/v1', 'enabled' => true, 'models' => [],
                'custom_models' => ['agy-flash-low', 'logo-image-chat'], 'custom_image_models' => ['agy-image', 'tinh-ve-a']], ['id' => $p->id]);

        $kinds = collect(AiModelCatalog::enabledModels($p->fresh()))->pluck('kind', 'id')->all();
        $this->assertSame(['agy-flash-low' => 'text', 'logo-image-chat' => 'text', 'agy-image' => 'image', 'tinh-ve-a' => 'image'], $kinds);
        // Tên suy ra "ảnh" nhưng người dùng đặt ở ô chữ: lưu {id, kind:text}; tên thường đặt ở ô ảnh: lưu {id, kind:image}.
        $raw = $p->fresh()->models;
        $this->assertContains('agy-image', $raw);
        $this->assertContains(['id' => 'logo-image-chat', 'kind' => 'text'], $raw);
        $this->assertContains(['id' => 'tinh-ve-a', 'kind' => 'image'], $raw);
    }

    public function test_buoc_anh_ghi_ten_model_that_khong_ghi_chung_image(): void
    {
        $m = new \ReflectionMethod(\App\Services\Images\ArticleImageStep::class, 'modelName');
        $m->setAccessible(true);
        $this->assertSame('agy-image', $m->invoke(null, 'OpenAI-tương thích tùy chỉnh / agy-image'));
        $this->assertSame('gemini-3.1-flash-image', $m->invoke(null, 'Gemini / gemini-3.1-flash-image (dự phòng CLI / agy-image)'));
        $this->assertSame('image', $m->invoke(null, null));
    }

    public function test_danh_sach_model_theo_loai_vai_tro(): void
    {
        $this->provider('openai_compat', 'CLI', ['agy-image', 'agy-flash-low']);
        $text = collect(AiModelCatalog::available('text'))->flatMap(fn ($g) => array_column($g['models'], 'id'))->all();
        $image = collect(AiModelCatalog::available('image'))->flatMap(fn ($g) => array_column($g['models'], 'id'))->all();
        $this->assertSame(['agy-flash-low'], $text);
        $this->assertSame(['agy-image'], $image);
        $this->assertSame('image', RoleAssignments::modelKindFor('designer'));
        $this->assertSame('text', RoleAssignments::modelKindFor('writer'));
    }

    public function test_may_chu_tu_choi_gan_sai_loai_model(): void
    {
        $admin = $this->admin();
        $p = $this->provider('openai_compat', 'CLI', ['agy-image', 'agy-flash-low']);
        $a = new RoleAssignments;

        try {
            $a->saveModel('writer', $p->id, 'agy-image', null, null, true, $admin);
            $this->fail('Vai trò chữ không được nhận model ảnh');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('model ảnh', $e->getMessage());
        }
        try {
            $a->saveModel('writer', $p->id, 'agy-flash-low', $p->id, 'agy-image', true, $admin);
            $this->fail('Model dự phòng sai loại cũng bị từ chối');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            $a->saveModel('designer', $p->id, 'agy-flash-low', null, null, true, $admin);
            $this->fail('Vai trò ảnh không được nhận model chữ');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('model ảnh', $e->getMessage());
        }
        $this->assertNull(AgentRoleAssignment::query()->where('role_key', 'writer')->first());

        $a->saveModel('designer', $p->id, 'agy-image', null, null, true, $admin);
        $a->saveModel('writer', $p->id, 'agy-flash-low', null, null, true, $admin);
        $this->assertSame('agy-image', AgentRoleAssignment::query()->where('role_key', 'designer')->value('model'));
    }

    public function test_studio_chon_model_cho_vai_tro_chu_va_anh(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $p = $this->provider('openai_compat', 'CLI', ['agy-image', 'agy-flash-low']);

        $t = \Livewire\Livewire::test(\App\Filament\Clusters\AgentTeam\Pages\AgentRoles::class)->call('openStudio', 'writer', 'model')
            ->assertSee('agy-flash-low')->assertDontSee('agy-image');
        $t->call('pickModel', 'primary', $p->id, 'agy-image');
        $this->assertNull(AgentRoleAssignment::query()->where('role_key', 'writer')->first());

        $d = \Livewire\Livewire::test(\App\Filament\Clusters\AgentTeam\Pages\AgentRoles::class)->call('openStudio', 'designer', 'model')
            ->assertSee('Model ảnh khả dụng')->assertSee('agy-image')->assertDontSee('agy-flash-low');
        $d->call('pickModel', 'primary', $p->id, 'agy-image');
        $this->assertSame('agy-image', AgentRoleAssignment::query()->where('role_key', 'designer')->value('model'));
    }

    public function test_resolver_uu_tien_vai_tro_roi_route_cu_roi_bao_loi(): void
    {
        $this->app['env'] = 'production';
        $this->assertStringContainsString('Chưa cấu hình', (string) ImageGeneratorResolver::why());
        $this->assertSame('none', ImageGeneratorResolver::status()['source']);

        $p = $this->provider('openai_compat', 'CLI', ['agy-image']);
        $g = $this->provider('gemini', 'Gem', ['gemini-3.1-flash-image']);
        AiModelRoute::create(['task' => 'image_gen', 'tier' => 'cheap', 'primary_provider_id' => $p->id, 'primary_model' => 'agy-image']);
        $r = ImageGeneratorResolver::resolve();
        $this->assertSame('route', $r['source']);
        $this->assertInstanceOf(OpenAiCompatImageGenerator::class, $r['generator']);

        AgentRoleAssignment::create(['role_key' => 'designer', 'provider_id' => $g->id, 'model' => 'gemini-3.1-flash-image', 'enabled' => true]);
        $r = ImageGeneratorResolver::resolve();
        $this->assertSame('designer', $r['source']);
        $this->assertInstanceOf(GeminiImageGenerator::class, $r['generator']);
        $this->assertStringContainsString('Tạo ảnh: Gem / gemini-3.1-flash-image', ImageGeneratorResolver::status()['text']);

        AgentRoleAssignment::query()->where('role_key', 'designer')->update(['fallback_provider_id' => $p->id, 'fallback_model' => 'agy-image']);
        $r = ImageGeneratorResolver::resolve();
        $this->assertInstanceOf(FallbackImageGenerator::class, $r['generator']);

        // Bản gán tạm tắt: quay về route cũ (tương thích).
        AgentRoleAssignment::query()->where('role_key', 'designer')->update(['enabled' => false]);
        $this->assertSame('route', ImageGeneratorResolver::resolve()['source']);
    }

    public function test_da_gan_cho_hoa_vien_nhung_provider_tat_thi_bao_loi_khong_am_tham_doi(): void
    {
        $p = $this->provider('openai_compat', 'CLI', ['agy-image']);
        $p->update(['enabled' => false]);
        AgentRoleAssignment::create(['role_key' => 'designer', 'provider_id' => $p->id, 'model' => 'agy-image', 'enabled' => true]);
        AiModelRoute::create(['task' => 'image_gen', 'tier' => 'cheap', 'primary_provider_id' => $p->id, 'primary_model' => 'agy-image']);
        $this->expectException(ImageGeneratorUnavailable::class);
        ImageGeneratorResolver::resolve();
    }

    public function test_dong_bo_route_cu_sang_vai_tro_hoa_vien_bang_migration_idempotent(): void
    {
        $p = $this->provider('openai_compat', 'CLI', ['agy-image']);
        AiModelRoute::create(['task' => 'image_gen', 'tier' => 'cheap', 'primary_provider_id' => $p->id, 'primary_model' => 'agy-image']);
        $m = require database_path('migrations/2026_11_13_000001_copy_image_route_to_designer_assignment.php');
        $m->up();
        $m->up();
        $this->assertSame(1, AgentRoleAssignment::query()->where('role_key', 'designer')->count());
        $a = AgentRoleAssignment::query()->where('role_key', 'designer')->first();
        $this->assertSame([$p->id, 'agy-image', true], [$a->provider_id, $a->model, $a->enabled]);
        $this->assertNotNull(AiModelRoute::where('task', 'image_gen')->first()); // route cũ giữ nguyên
        $this->assertSame('designer', ImageGeneratorResolver::resolve()['source']);
    }

    public function test_vai_tro_hoa_vien_khai_bao_dung_va_buoc_anh_ghi_vai_tro_designer(): void
    {
        $reg = new RoleRegistry;
        $r = $reg->find('designer');
        $this->assertSame('in_development', $r['readiness']);
        $this->assertSame('designer', RoleRegistry::STEP_ROLE['images']);
        $this->assertTrue($reg->isSwitchable('designer'));

        app(EmergencyStopService::class)->resetForTesting();
        $this->app->instance(UrlSafetyService::class, new class extends UrlSafetyService
        {
            protected function resolveIps(string $host): array
            {
                return filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ['93.184.216.34'];
            }
        });
        $w = Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $art = Article::create(['website_id' => $w->id, 'primary_keyword' => 'dịch vụ thiết kế website', 'workflow_status' => 'planned']);
        $run = (new AgentRunOrchestrator(new DemoScriptedAiProvider))->run($art, null, 'manual');
        $img = $run->steps->firstWhere('stage', 'images');
        if ($img) {
            $this->assertSame('designer', $img->role);
            $this->assertSame('designer', $img->data['role_key']);
        }

        // Tắt vai trò Họa viên: bước ảnh bỏ qua với lý do rõ, phần còn lại không đổi.
        (new RoleSwitches)->set('designer', false, $this->admin());
        $art2 = Article::create(['website_id' => $w->id, 'primary_keyword' => 'thiết kế logo', 'workflow_status' => 'planned']);
        $run2 = (new AgentRunOrchestrator(new DemoScriptedAiProvider))->run($art2, null, 'manual');
        $img2 = $run2->steps->firstWhere('stage', 'images');
        $this->assertNotNull($img2);
        $this->assertSame('skipped', $img2->status);
        $this->assertSame('designer', $img2->data['role_key']);
        $this->assertSame('role_off', $img2->data['skip_code']);
        $this->assertNotNull($run2->final_version_id);
    }
}
