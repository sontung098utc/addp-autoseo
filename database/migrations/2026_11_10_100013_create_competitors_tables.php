<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** AG-S24: đối thủ + khoảng trống từ khóa THẬT theo website. Không crawl đối thủ; chỉ lưu tên miền đã chuẩn hóa. */
    public function up(): void
    {
        Schema::create('competitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->string('domain');
            $table->string('brand_name', 120)->nullable();
            $table->enum('kind', ['truc_tiep', 'bao_chi', 'khong_lien_quan'])->default('truc_tiep');
            $table->string('note', 500)->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['website_id', 'domain']);
        });

        Schema::create('keyword_gaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignId('competitor_id')->nullable()->constrained('competitors')->cascadeOnDelete();
            $table->string('keyword');
            $table->unsignedInteger('competitor_rank')->nullable();
            $table->unsignedInteger('our_rank')->nullable();
            // NULL khi nguồn không có lượng tìm (không bao giờ điền 0/số giả).
            $table->unsignedInteger('volume')->nullable();
            $table->enum('source', ['csv', 'gsc', 'crawl', 'tay'])->default('csv');
            $table->unsignedTinyInteger('priority_score')->nullable();
            $table->enum('status', ['moi', 'da_them_tu_khoa', 'bo_qua'])->default('moi');
            $table->foreignId('keyword_id')->nullable()->constrained('keywords')->nullOnDelete();
            $table->timestamps();

            $table->unique(['website_id', 'competitor_id', 'keyword']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_gaps');
        Schema::dropIfExists('competitors');
    }
};
