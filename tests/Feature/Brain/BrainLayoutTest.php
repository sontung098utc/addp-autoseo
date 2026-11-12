<?php

namespace Tests\Feature\Brain;

use App\Models\Brain;
use App\Services\Brain\BrainService;
use App\Services\Brain\BrainTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrainLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_layout_of_article_type_is_saved_and_health_templates_ship_default_layout(): void
    {
        $brain = Brain::create(['name' => 'Y', 'slug' => 'y', 'domain_key' => 'y', 'language' => 'vi', 'status' => 'active', 'description' => '']);
        (new BrainService)->saveProfile($brain, ['structure' => ['article_types' => [['name' => 'Giải thích', 'outline' => ['Là gì'], 'layout' => ['Bảng mốc', ' ', 'Khung cảnh báo']]]]], \App\Models\User::firstOrCreate(['email' => 'admin@b1.vn'], ['name' => 'a', 'password' => 'secret123', 'role' => 'admin', 'is_active' => true]));
        $t = $brain->fresh()->profile->structure['article_types'][0];
        $this->assertSame(['Bảng mốc', 'Khung cảnh báo'], $t['layout']);

        $tpl = BrainTemplates::health(['name' => 'X', 'description' => '', 'domain_key' => 'x', 'reliance_reason' => '', 'topics' => [], 'types' => ['giai-thich' => ['Giải thích', ['A']]], 'terms' => [], 'rules' => [], 'rubric' => [], 'sources' => []]);
        $this->assertSame(BrainTemplates::HEALTH_LAYOUT, $tpl['profile']['structure']['article_types'][0]['layout']);
        $this->assertStringContainsString('Khi nào nên gặp bác sĩ', implode(' ', BrainTemplates::HEALTH_LAYOUT));
    }
}
