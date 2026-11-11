<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Điểm dừng chờ người (chiến dịch): lượt đăng có thể "Tạm dừng" (người dừng riêng một bài) hoặc "Chờ xác nhận" (điểm dừng 'publish').
 * Cả hai KHÔNG bao giờ được gửi lên CMS: lệnh quét chỉ nhặt 'scheduled' và PublishArticleJob từ chối hai trạng thái này.
 * Cột status đổi từ enum sang chuỗi để nhận trạng thái mới (giá trị cũ giữ nguyên).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publications', function (Blueprint $table) {
            $table->string('status', 20)->default('scheduled')->change();
        });
    }

    public function down(): void
    {
        // Lượt đang dừng/chờ xác nhận không thể quay về 'scheduled' (sẽ tự đăng): chuyển sang 'failed' kèm ghi chú để người xử lý lại.
        DB::table('publications')->whereIn('status', ['paused', 'awaiting_confirm'])
            ->update(['status' => 'failed', 'last_error' => 'Đang dừng/chờ xác nhận khi gỡ tính năng điểm dừng; cần xếp lịch lại.']);
        Schema::table('publications', function (Blueprint $table) {
            $table->enum('status', ['scheduled', 'publishing', 'published', 'failed', 'needs_reconcile'])->default('scheduled')->change();
        });
    }
};
