<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tài khoản AI kiểu "API tùy chỉnh" (kind = custom_http): cách xác thực, đường dẫn, mẫu body và cách đọc kết quả của một API tạo ảnh
 * không thuộc hãng có sẵn. Chỉ là cấu hình (không chứa khóa); khóa vẫn nằm ở cột api_key đã mã hóa.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ai_providers', 'http_config')) {
            Schema::table('ai_providers', function (Blueprint $table) {
                $table->json('http_config')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ai_providers', 'http_config')) {
            Schema::table('ai_providers', function (Blueprint $table) {
                $table->dropColumn('http_config');
            });
        }
    }
};
