<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** AG-S14: công tắc vai trò. Lưu danh sách khóa vai trò đang TẮT; chỉ vai trò không bắt buộc mới có hiệu lực (RoleSwitches). Bản đề xuất cho Dev1 rà. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_settings', function (Blueprint $table) {
            $table->json('disabled_roles')->nullable()->after('max_repair_rounds');
        });
    }

    public function down(): void
    {
        Schema::table('agent_settings', function (Blueprint $table) {
            $table->dropColumn('disabled_roles');
        });
    }
};
