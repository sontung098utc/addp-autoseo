<?php

namespace Tests\Feature\Keywords;

use App\Filament\Support\Keywords\KeywordService;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Gom cụm theo luật tách ý định tìm hiểu khỏi ý định mua/so sánh (lỗi thấy khi nhập vai: "chỉ số đường huyết" lẫn với "mua máy đo đường huyết"). */
class ClusterIntentSplitTest extends TestCase
{
    use RefreshDatabase;

    public function test_tu_khoa_tim_hieu_va_mua_khong_chung_cum_va_ten_cum_khong_trung(): void
    {
        Cache::flush();
        config(['studio.keywords_real' => true]);
        Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->actingAs(User::create(['name' => 'w', 'email' => 'w@x.vn', 'password' => 'secret123', 'role' => 'writer', 'is_active' => true]));
        $svc = KeywordService::make();
        $svc->addRows([
            ['keyword' => 'chỉ số đường huyết bình thường', 'volume' => null, 'intent' => 'informational'],
            ['keyword' => 'đường huyết cao là gì', 'volume' => null, 'intent' => 'informational'],
            ['keyword' => 'mua máy đo đường huyết', 'volume' => null, 'intent' => 'transactional'],
            ['keyword' => 'máy đo đường huyết loại nào tốt', 'volume' => null, 'intent' => 'commercial'],
        ]);

        $svc->propose();
        $props = $svc->repo()->proposals();

        $this->assertCount(2, $props, 'một cụm tìm hiểu và một cụm mua/so sánh');
        foreach ($props as $p) {
            $intents = array_unique(array_map(fn ($k) => in_array($k, ['mua máy đo đường huyết', 'máy đo đường huyết loại nào tốt'], true) ? 'shop' : 'info', $p['keywords']));
            $this->assertCount(1, $intents, 'không trộn hai ý định trong một cụm: '.json_encode($p['keywords'], JSON_UNESCAPED_UNICODE));
        }
        $this->assertCount(2, array_unique(array_column($props, 'name')), 'tên cụm không trùng nhau');
        $this->assertTrue(collect($props)->contains(fn ($p) => str_contains($p['name'], '(mua/so sánh)')));
    }
}
