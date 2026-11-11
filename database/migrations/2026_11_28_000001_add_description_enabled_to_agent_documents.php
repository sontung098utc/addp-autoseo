<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Kỹ năng tự tạo của vai trò: thêm mô tả một dòng, công tắc bật/tắt và người sửa cuối. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_documents', function (Blueprint $table) {
            $table->string('description', 200)->nullable()->after('title');
            $table->boolean('enabled')->default(true)->after('bytes');
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('agent_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn(['description', 'enabled']);
        });
    }
};
