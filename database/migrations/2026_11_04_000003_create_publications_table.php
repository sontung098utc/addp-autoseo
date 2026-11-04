<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignId('article_version_id')->constrained('article_versions')->cascadeOnDelete();
            $table->string('publication_key')->unique(); // Chống đăng trùng (idempotency key)
            $table->timestamp('scheduled_at_utc');
            $table->string('timezone')->default('Asia/Ho_Chi_Minh');
            $table->enum('status', ['scheduled', 'publishing', 'published', 'failed', 'needs_reconcile'])->default('scheduled');
            $table->string('cms_post_id')->nullable();
            $table->string('published_url')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('published_at_utc')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at_utc']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('publications');
    }
};
