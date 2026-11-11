<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Đề xuất làm mới bài từ lỗi Audit: do hệ thống sinh, chỉ thành việc (articles) khi người duyệt chấp nhận. */
    public function up(): void
    {
        Schema::create('audit_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignId('seo_audit_id')->nullable()->constrained('seo_audits')->nullOnDelete();
            $table->foreignId('crawled_url_id')->nullable()->constrained('crawled_urls')->nullOnDelete();
            $table->string('url', 500);
            // NULL = URL không khớp bài viết nào của website (trang tĩnh, danh mục): chỉ cần sửa thủ công, không giao cho đội AI.
            $table->foreignId('article_id')->nullable()->constrained('articles')->nullOnDelete();
            $table->json('issue_codes'); // loại lỗi trong IssueCatalog (chỉ P0/P1)
            $table->enum('severity', ['P0', 'P1']);
            $table->unsignedTinyInteger('priority');
            $table->enum('status', ['de_xuat', 'da_chap_nhan', 'bo_qua', 'da_xong'])->default('de_xuat');
            $table->foreignId('article_task_id')->nullable()->constrained('articles')->nullOnDelete(); // bài refresh được tạo ra
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['website_id', 'url', 'seo_audit_id']);
            $table->index(['website_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_suggestions');
    }
};
