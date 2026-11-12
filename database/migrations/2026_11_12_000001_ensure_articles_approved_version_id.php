<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sửa lệch schema: có môi trường (vd DB preview dựng từ nhánh cũ rồi đánh dấu migration 2026_11_10_000002 là đã chạy) thiếu
 * articles.approved_version_id, khiến mọi lần tạo ArticleVersion lỗi ("no such column"). Migration gốc không bao giờ chạy lại ở các
 * môi trường đó, nên bổ sung ở đây. Idempotent: có cột rồi thì không làm gì. Chạy được trên SQLite và MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('articles', 'approved_version_id')) {
            Schema::table('articles', function (Blueprint $table) {
                // Cột + chỉ mục; khóa ngoại chỉ có ở môi trường dựng đúng từ migration gốc (bổ sung FK sau trên SQLite phải dựng lại bảng, không đáng rủi ro).
                $table->unsignedBigInteger('approved_version_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        // Không gỡ: cột thuộc migration 2026_11_10_000002.
    }
};
