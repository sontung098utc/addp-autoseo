<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Kho từ khóa thật + cột nối bài viết với từ khóa/cụm (đều NULLABLE, không đổi cột cũ của articles). */
    public function up(): void
    {
        Schema::create('keyword_clusters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->string('name', 120);
            $table->enum('intent', ['informational', 'commercial', 'transactional', 'navigational'])->default('informational');
            $table->timestamps();

            $table->unique(['website_id', 'name']);
        });

        Schema::create('keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->string('keyword');
            // Chuẩn hóa: chữ thường + gộp khoảng trắng. Dùng để chặn trùng, không dùng để hiển thị.
            $table->string('keyword_key');
            // volume/difficulty để NULL khi nguồn không cung cấp (không bao giờ điền 0 hay số giả).
            $table->unsignedInteger('volume')->nullable();
            $table->unsignedTinyInteger('difficulty')->nullable();
            $table->enum('intent', ['informational', 'commercial', 'transactional', 'navigational'])->default('informational');
            $table->enum('source', ['tay', 'csv', 'gsc', 'ai', 'crawl', 'competitor'])->default('tay');
            $table->foreignId('cluster_id')->nullable()->constrained('keyword_clusters')->nullOnDelete();
            $table->enum('status', ['new', 'planned', 'has_article'])->default('new');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['website_id', 'keyword_key']);
        });

        Schema::table('articles', function (Blueprint $table) {
            $table->enum('kind', ['new', 'refresh'])->default('new');
            $table->enum('origin', ['plan', 'keyword', 'audit', 'competitor', 'gsc', 'manual'])->nullable();
            $table->string('origin_ref')->nullable();
            $table->foreignId('keyword_id')->nullable()->constrained('keywords')->nullOnDelete();
            $table->unsignedBigInteger('cluster_id')->nullable();
            $table->unsignedTinyInteger('priority')->nullable();
            $table->unsignedBigInteger('campaign_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('keyword_id');
            $table->dropColumn(['kind', 'origin', 'origin_ref', 'cluster_id', 'priority', 'campaign_id']);
        });
        Schema::dropIfExists('keywords');
        Schema::dropIfExists('keyword_clusters');
    }
};
