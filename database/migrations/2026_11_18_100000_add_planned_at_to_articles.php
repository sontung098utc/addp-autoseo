<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ngày dự kiến đăng của bài "Đã lên kế hoạch" (UTC; giao diện đổi sang giờ Việt Nam). Trước đây ngày này chỉ lưu tạm ở DemoStore (cache, chỉ chạy
 * ở chế độ minh họa) nên ngoài chế độ đó bị bỏ qua im lặng: nhập kế hoạch từ file, tạo bài từ cụm kèm ngày hay đổi ngày đều không lưu được.
 * Cột chỉ là ngày DỰ KIẾN để lên lịch tuần; lịch đăng thật vẫn ở bảng publications. Thêm cột nullable nên không ảnh hưởng dữ liệu có sẵn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dateTime('planned_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropIndex(['planned_at']);
            $table->dropColumn('planned_at');
        });
    }
};
