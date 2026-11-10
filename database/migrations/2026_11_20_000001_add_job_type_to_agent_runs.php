<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * G2 (đề xuất cho Dev1 rà, PR riêng): cho `agent_runs` ghi được các loại việc KHÔNG gắn một bài (khảo sát thị trường, xây kho trí tuệ...).
 * THIET_KE_PIPELINE_THEO_LOAI_VIEC.md §5.2, phương án P1. Phiên cũ tự thành `article_write` nhờ giá trị mặc định.
 *  - job_type: mã loại việc trong JobTypeRegistry; mặc định article_write.
 *  - article_id: cho phép rỗng (loại việc không viết bài). Khóa ngoại vẫn xóa theo bài.
 *  - parent_run_id: phiên cha (ví dụ brain_build do bước nghiên cứu của một bài gọi). Xóa phiên cha thì giữ phiên con.
 *  - input_refs / output_ref: JSON tham chiếu bản ghi vào/ra (ví dụ từ khóa đã thêm), để trang phiên nối được tới kết quả.
 *  - tokens_budget: trần token của phiên (rỗng = theo chính sách chung).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->string('job_type', 30)->default('article_write')->after('website_id');
            $table->unsignedBigInteger('parent_run_id')->nullable()->after('article_id');
            $table->json('input_refs')->nullable();
            $table->json('output_ref')->nullable();
            $table->unsignedInteger('tokens_budget')->nullable();
            $table->index(['job_type', 'website_id']);
            $table->index('parent_run_id');
        });
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->foreignId('article_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Phiên không gắn bài không còn chỗ đứng khi article_id bắt buộc trở lại: xóa chúng (và bước của chúng, theo khóa ngoại).
        DB::table('agent_runs')->whereNull('article_id')->delete();
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropIndex(['job_type', 'website_id']);
            $table->dropIndex(['parent_run_id']);
            $table->dropColumn(['job_type', 'parent_run_id', 'input_refs', 'output_ref', 'tokens_budget']);
        });
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->foreignId('article_id')->nullable(false)->change();
        });
    }
};
