<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** AG-S04: nơi lưu bản NHÁP đã đẩy lên CMS (xem trước, không công khai). Chỉ thêm cột nullable. Bản đề xuất cho Dev1 rà. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_versions', function (Blueprint $table) {
            $table->string('cms_draft_id', 100)->nullable();
            $table->string('cms_draft_url', 500)->nullable();
            $table->timestamp('cms_draft_pushed_at')->nullable();
            $table->string('cms_draft_status', 12)->nullable(); // pushed | failed | cancelled | promoted
        });
    }

    public function down(): void
    {
        Schema::table('article_versions', fn (Blueprint $t) => $t->dropColumn(['cms_draft_id', 'cms_draft_url', 'cms_draft_pushed_at', 'cms_draft_status']));
    }
};
