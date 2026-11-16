<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AG-S21: nhật ký tách bằng AI trên nguồn, đoạn nguồn của mục AI đề xuất, và trạng thái "đã bổ sung" của khoảng trống kiến thức.
 * Bản đề xuất cho Dev1 rà. Chỉ thêm cột (nullable/có mặc định), không đổi dữ liệu cũ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_sources', function (Blueprint $table) {
            $table->json('ai_log')->nullable()->after('status'); // danh sách các lượt tách bằng AI (mới nhất trước)
        });
        Schema::table('knowledge_items', function (Blueprint $table) {
            $table->text('source_span')->nullable()->after('confidence'); // đoạn nguyên văn trong nguồn mà AI dựa vào
        });
        Schema::table('knowledge_gaps', function (Blueprint $table) {
            $table->string('status', 10)->default('open')->after('count'); // open | filled
            $table->timestamp('filled_at')->nullable()->after('status');
            $table->unsignedBigInteger('filled_item_id')->nullable()->after('filled_at');
            $table->index(['website_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_gaps', function (Blueprint $table) {
            $table->dropIndex(['website_id', 'status']);
            $table->dropColumn(['status', 'filled_at', 'filled_item_id']);
        });
        Schema::table('knowledge_items', fn (Blueprint $t) => $t->dropColumn('source_span'));
        Schema::table('knowledge_sources', fn (Blueprint $t) => $t->dropColumn('ai_log'));
    }
};
