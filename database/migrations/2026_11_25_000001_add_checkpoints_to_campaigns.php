<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Điểm dừng của chiến dịch: các giai đoạn mà đội AI DỪNG lại chờ người xem/sửa rồi bấm tiếp, thay vì chạy suốt.
 * Giá trị cho phép: 'outline' (sau dàn ý), 'draft' (sau bản nháp: vào hàng chờ duyệt, như hôm nay), 'publish' (trước khi đăng).
 * Rỗng/null = chạy suốt tới bước mà chế độ (Duyệt trước/Toàn quyền) quy định. Đề xuất cho Dev1 rà, PR riêng.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->json('checkpoints')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('checkpoints');
        });
    }
};
