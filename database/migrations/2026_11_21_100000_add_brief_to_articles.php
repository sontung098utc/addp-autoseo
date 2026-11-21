<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brief có cấu trúc của bài (JSON): các trường nhập từ file kế hoạch (đối tượng, giọng văn, nguồn nên dùng, liên kết nội bộ...) và thông tin xuất bản
 * (chuyên mục, slug mong muốn, tác giả, người duyệt). `guidance` vẫn là văn bản cho Người viết/bộ chấm; `brief` để xem/sửa/thống kê theo trường.
 * Cột nullable nên không ảnh hưởng dữ liệu có sẵn. Các trường xuất bản CHƯA được bộ đăng bài WordPress/Magento sử dụng.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->json('brief')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn('brief');
        });
    }
};
