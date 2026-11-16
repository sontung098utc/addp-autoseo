<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mở rộng bảng `campaigns` do lô migration A của Dev1 (2026_11_10_000001) tạo, bằng các cột của chiến dịch tự chủ (đội AI tự giao bài).
 * Chạy SAU lô A; không tạo bảng, không đụng cột của Dev1. down() chỉ xóa đúng các cột nó thêm.
 * Cột `keywords` nullable: chiến dịch tạo theo lô A (không có keywords) vẫn hợp lệ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            if (! Schema::hasColumn('campaigns', 'keywords')) {
                $table->json('keywords')->nullable();                      // danh sách từ khóa cần viết (nguồn 'keywords')
            }
            if (! Schema::hasColumn('campaigns', 'daily_limit')) {
                $table->unsignedSmallInteger('daily_limit')->default(3);  // rào chắn nội dung hàng loạt: tối đa bài/ngày (giờ VN)
            }
            if (! Schema::hasColumn('campaigns', 'last_tick_at')) {
                $table->timestamp('last_tick_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            foreach (['keywords', 'daily_limit', 'last_tick_at'] as $col) {
                if (Schema::hasColumn('campaigns', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
