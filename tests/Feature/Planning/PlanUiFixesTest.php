<?php

namespace Tests\Feature\Planning;

use App\Filament\Pages\ContentPlanPage;
use App\Filament\Support\ContentPlan\PlanRules;
use App\Filament\Support\ContentPlan\PlanStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use Tests\TestCase;

/** Sửa giao diện trang Kế hoạch (phát hiện 10, 12, 13, 14 ngày 18/11/2026). */
class PlanUiFixesTest extends TestCase
{
    use RefreshDatabase;

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-11-18 10:00', 'Asia/Ho_Chi_Minh');
    }

    public function test_status_published_and_failed_are_not_shown_as_scheduled(): void
    {
        $past = $this->now()->subDay();
        $this->assertSame('Đã đăng', PlanStatus::resolve('scheduled', 'published', $past, $this->now())['text']);
        $this->assertSame('Lỗi', PlanStatus::resolve('scheduled', 'failed', $past, $this->now())['text']);
        $this->assertSame('Đang đăng', PlanStatus::resolve('scheduled', 'publishing', $past, $this->now())['text']);
        $this->assertSame('danger', PlanStatus::resolve('scheduled', 'failed', $past, $this->now())['tone']);
    }

    public function test_scheduled_in_the_past_is_passed_and_future_stays_scheduled(): void
    {
        $this->assertSame('Đã qua', PlanStatus::resolve('scheduled', 'scheduled', $this->now()->subHours(3), $this->now())['text']);
        $this->assertSame('Đã xếp lịch', PlanStatus::resolve('scheduled', 'scheduled', $this->now()->addDay(), $this->now())['text']);
        $this->assertSame('Đã lên kế hoạch', PlanStatus::resolve('planned', null, $this->now()->addDay(), $this->now())['text']);
    }

    public function test_range_label_has_no_stray_space(): void
    {
        $d = CarbonImmutable::parse('2026-11-18');
        $this->assertSame('18/11/2026', PlanStatus::rangeLabel($d, $d));
        $this->assertSame('18/11/2026', PlanStatus::rangeLabel($d, null));
        $this->assertSame('18/11 – 20/11/2026', PlanStatus::rangeLabel($d, $d->addDays(2)));
    }

    public function test_seed_error_messages(): void
    {
        $this->assertNull(PlanRules::seedError('tiểu đường'));
        $this->assertStringContainsString('nhập chủ đề', (string) PlanRules::seedError('   '));
        $this->assertStringContainsString('120', (string) PlanRules::seedError(str_repeat('a', 121)));
    }

    public function test_empty_seed_shows_error_on_field_without_calling_proposer(): void
    {
        $this->actingAs(User::create(['name' => 'a', 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => 'admin', 'is_active' => true]));
        Livewire::test(ContentPlanPage::class)
            ->set('campaignSeed', '')
            ->call('proposeCampaign')
            ->assertHasErrors(['campaignSeed'])
            ->assertSet('campaignProposal', null)
            ->call('exploreTopics')
            ->assertHasErrors(['campaignSeed']);
    }

    public function test_validation_messages_are_vietnamese(): void
    {
        $msg = Validator::make(['day' => ''], ['day' => 'required'], [], ['day' => 'ngày mới'])->errors()->first('day');
        $this->assertStringContainsString('ngày mới', $msg);
        $this->assertStringContainsString('nhập', $msg);
        $this->assertStringNotContainsString('field is required', $msg);
    }

    public function test_empty_slot_opens_assign_dialog_with_save_label(): void
    {
        $this->actingAs(User::create(['name' => 'a', 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => 'admin', 'is_active' => true]));
        \App\Models\Website::create(['name' => 'S', 'base_url' => 'https://s.vn', 'status' => 'active', 'cms_type' => 'wordpress', 'encrypted_credentials' => ['p' => 'x']]);
        Livewire::test(ContentPlanPage::class)
            ->call('mountAction', 'assignDay', ['day' => '2030-01-02'])
            ->assertSee('Xếp bài vào ngày 02/01/2030')
            ->assertSee('Lưu')
            ->assertDontSee('Gửi');
    }
}
