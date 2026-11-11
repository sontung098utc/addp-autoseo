<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cấu hình AI: mỗi tài khoản có danh sách model ĐÃ BẬT. null = chưa chọn, dùng bộ gợi ý mặc định của loại tài khoản. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_providers', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_providers', 'models')) {
                $table->json('models')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_providers', function (Blueprint $table) {
            if (Schema::hasColumn('ai_providers', 'models')) {
                $table->dropColumn('models');
            }
        });
    }
};
