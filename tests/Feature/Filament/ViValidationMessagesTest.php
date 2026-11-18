<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CampaignResource\Pages\CreateCampaign;
use App\Filament\Resources\MediaAssetResource;
use App\Filament\Resources\MediaAssetResource\Pages\CreateMediaAsset;
use App\Filament\Resources\WebsiteResource\Pages\CreateWebsite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use Tests\TestCase;

/** #25: mọi thông báo kiểm tra phải bằng tiếng Việt, không lọt câu tiếng Anh của Laravel. */
class ViValidationMessagesTest extends TestCase
{
    use RefreshDatabase;

    private function approver(): User
    {
        return User::create(['name' => 'a', 'email' => 'a@x.vn', 'password' => 'secret123', 'role' => 'approver', 'is_active' => true]);
    }

    public function test_locale_is_vietnamese_and_lang_files_exist(): void
    {
        $this->assertSame('vi', config('app.locale'));
        $this->assertFileExists(base_path('lang/vi/validation.php'));
        $en = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
        $vi = require base_path('lang/vi/validation.php');
        foreach (array_keys($en) as $k) {
            if ($k === 'custom' || $k === 'attributes') {
                continue;
            }
            $this->assertArrayHasKey($k, $vi, "Thiếu bản dịch validation.$k");
        }
    }

    public function test_core_laravel_rules_are_translated(): void
    {
        $v = Validator::make(['a' => '', 'b' => 'x', 'c' => 'abc'], [
            'a' => 'required', 'b' => 'min:3', 'c' => 'email',
        ], [], ['a' => 'tiêu đề', 'b' => 'tên', 'c' => 'email']);
        $this->assertTrue($v->fails());
        foreach ($v->errors()->all() as $m) {
            $this->assertDoesNotMatchRegularExpression('/field is required|must be|must have|may not/i', $m);
        }
    }

    public function test_media_upload_required_message_is_natural(): void
    {
        Livewire::actingAs($this->approver())->test(CreateMediaAsset::class)
            ->call('create')
            ->assertHasErrors(['data.file_path' => 'required'])
            ->assertSee('Vui lòng chọn ảnh tải lên.');
    }

    public function test_empty_create_forms_have_no_english_messages(): void
    {
        $this->actingAs($this->approver());
        foreach ([CreateMediaAsset::class, CreateWebsite::class, CreateCampaign::class] as $page) {
            $c = Livewire::test($page)->call('create');
            $msgs = collect($c->errors()->all());
            foreach ($msgs as $m) {
                $this->assertDoesNotMatchRegularExpression('/field is required|must be|must have|may not|is invalid/i', $m, "$page: $m");
            }
        }
    }

    public function test_media_resource_labels_and_title(): void
    {
        $this->assertSame('Tải ảnh mới', (new \ReflectionClass(CreateMediaAsset::class))->getProperty('title')->getValue());
        $this->assertSame('ảnh', MediaAssetResource::getModelLabel());
    }
}
