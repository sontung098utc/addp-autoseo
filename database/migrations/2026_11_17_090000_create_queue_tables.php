<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bảng cho hàng đợi kiểu `database` (QUEUE_CONNECTION=database): PublishArticleJob, ReconcilePublicationJob và mọi job khác cần bảng `jobs`.
 * Trước đây repo dùng hàng đợi mà không có bảng này; môi trường chỉ chạy `sync` thì không thấy lỗi, còn server đặt `database` thì lệnh gửi job báo
 * "Table 'jobs' doesn't exist" và lượt đăng kẹt ở `publishing`. Chạy lại an toàn (bỏ qua bảng đã có).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }
        if (! Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        // Không xóa: bảng có thể đang chứa job chưa chạy hoặc job lỗi cần xem lại.
    }
};
