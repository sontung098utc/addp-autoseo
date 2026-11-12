<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vai trò Họa viên hình ảnh (designer) giờ giữ model ảnh. Sao chép route cũ 'image_gen' (ai_model_routes) sang bản gán của designer
 * để cấu hình đang chạy hiện đúng ở vai trò này. Idempotent và chỉ thêm: không xóa route cũ (ImageGeneratorResolver vẫn đọc nó khi
 * designer chưa có bản gán), không ghi đè bản gán designer đã có model.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_model_routes') || ! Schema::hasTable('agent_role_assignments')) {
            return;
        }
        $route = DB::table('ai_model_routes')->where('task', 'image_gen')->first();
        if (! $route || empty($route->primary_provider_id) || empty($route->primary_model)) {
            return;
        }
        $existing = DB::table('agent_role_assignments')->where('role_key', 'designer')->first();
        if ($existing && ! empty($existing->provider_id) && ! empty($existing->model)) {
            return;
        }
        $row = ['provider_id' => $route->primary_provider_id, 'model' => $route->primary_model, 'enabled' => true, 'updated_at' => now()];
        if ($existing) {
            DB::table('agent_role_assignments')->where('role_key', 'designer')->update($row);
        } else {
            DB::table('agent_role_assignments')->insert($row + ['role_key' => 'designer', 'created_at' => now()]);
        }
    }

    public function down(): void
    {
        // Không hoàn tác: bản gán là dữ liệu cấu hình của người dùng.
    }
};
