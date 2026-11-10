<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chỉ dẫn thêm của người dùng cho đội AI ở MỘT bài (góc nhìn, đối tượng, điều cần nhấn): can thiệp giữa chừng mà không phải giao lại mù.
 * Đi vào dàn ý và bản viết như DỮ LIỆU định hướng (không ghi đè quy tắc an toàn). Đề xuất cho Dev1 rà, PR riêng.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->text('guidance')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn('guidance');
        });
    }
};
