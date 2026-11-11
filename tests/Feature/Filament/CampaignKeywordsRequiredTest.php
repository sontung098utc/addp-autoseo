<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CampaignResource\Pages\CreateCampaign;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CampaignKeywordsRequiredTest extends TestCase
{
    use RefreshDatabase;

    public function test_tu_khoa_chi_bat_buoc_khi_nguon_la_danh_sach_nhap_tay(): void
    {
        $w = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->actingAs(User::create(['name' => 'a', 'email' => 'a@x.vn', 'password' => 'secret123', 'role' => 'approver', 'is_active' => true]));
        $base = ['name' => 'CD', 'website_id' => $w->id, 'daily_limit' => 3, 'status' => 'paused', 'mode' => 'balanced', 'daily_cost_cap_vnd' => 100000, 'daily_draft_cap' => 5];

        Livewire::test(CreateCampaign::class)->fillForm($base + ['source' => 'plan', 'keywords' => []])->call('create')->assertHasNoFormErrors();
        Livewire::test(CreateCampaign::class)->fillForm($base + ['source' => 'keywords', 'keywords' => []])->call('create')->assertHasFormErrors(['keywords' => 'required']);
    }
}
