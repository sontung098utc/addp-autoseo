<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kết nối Search Console bằng tài khoản Google của người dùng (OAuth chỉ-đọc). Song song với cách service account (khóa JSON trong cấu hình).
 * refresh_token lưu MÃ HÓA ở tầng model; không bao giờ hiển thị lại.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gsc_connections')) {
            Schema::create('gsc_connections', function (Blueprint $table) {
                $table->id();
                $table->string('google_email', 190)->nullable();
                $table->text('refresh_token');
                $table->unsignedBigInteger('connected_by')->nullable();
                $table->timestamp('revoked_at')->nullable()->index();
                $table->string('last_error', 255)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gsc_connections');
    }
};
