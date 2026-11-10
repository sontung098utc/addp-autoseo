<?php

namespace Database\Seeders;

use App\Services\Brain\BrainService;
use App\Services\Brain\BrainTemplates;
use Illuminate\Database\Seeder;

/** AG-B2: tạo 14 Trí tuệ sức khỏe từ mẫu (chỉ khung; chưa gắn website; chưa có kiến thức). Chạy lại không tạo trùng. */
class HealthBrainsSeeder extends Seeder
{
    public function run(): void
    {
        (new BrainService())->installTemplates(BrainTemplates::HEALTH_KEYS);
    }
}
