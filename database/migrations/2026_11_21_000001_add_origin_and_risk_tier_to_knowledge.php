<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G2 (đề xuất cho Dev1 rà, PR riêng): xây kho từ website công ty. THIET_KE_KHO_TRI_TUE_TU_DONG.md §9.
 *  - knowledge_sources: origin (own_site|registry|search|user), fetched_at, final_url, http_status: nguồn gốc và ngày tải của trang.
 *  - knowledge_items: risk_tier (A|B|C, do luật cố định trong mã quyết), review_mode (manual|auto): nhãn "tự nhận" và hoàn tác.
 * Dòng cũ để null, hành vi không đổi. Chạy được trên SQLite và MariaDB 10.6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_sources', function (Blueprint $table) {
            $table->string('origin', 12)->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->string('final_url', 2048)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
        });
        Schema::table('knowledge_items', function (Blueprint $table) {
            $table->string('risk_tier', 1)->nullable();
            $table->string('review_mode', 8)->nullable();
            $table->index(['website_id', 'review_mode']);
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_items', function (Blueprint $table) {
            $table->dropIndex(['website_id', 'review_mode']);
        });
        Schema::table('knowledge_items', function (Blueprint $table) {
            $table->dropColumn(['risk_tier', 'review_mode']);
        });
        Schema::table('knowledge_sources', function (Blueprint $table) {
            $table->dropColumn(['origin', 'fetched_at', 'final_url', 'http_status']);
        });
    }
};
