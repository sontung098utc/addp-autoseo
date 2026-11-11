<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Điểm dừng của chiến dịch: các giai đoạn mà đội AI DỪNG lại chờ người xem/sửa rồi bấm tiếp, thay vì chạy suốt.
 * Giá trị cho phép: 'plan' (Autopilot chỉ đề xuất kế hoạch, chờ người nhận), 'outline' (sau dàn ý), 'draft' (sau bản nháp: không tự duyệt, vào hàng chờ duyệt), 'publish' (trước khi đăng: cần người xác nhận).
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
