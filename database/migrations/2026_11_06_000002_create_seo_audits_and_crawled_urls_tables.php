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
        Schema::create('crawled_urls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->string('url', 1024);
            $table->integer('http_status')->default(200);
            $table->string('title', 255)->nullable();
            $table->text('meta_description')->nullable();
            $table->string('canonical_url', 1024)->nullable();
            $table->string('h1', 255)->nullable();
            $table->unsignedInteger('h1_count')->default(0);
            $table->json('headings_summary')->nullable();
            $table->unsignedInteger('images_count')->default(0);
            $table->unsignedInteger('missing_alt_count')->default(0);
            $table->unsignedInteger('internal_links_count')->default(0);
            $table->unsignedInteger('external_links_count')->default(0);
            $table->boolean('has_schema')->default(false);
            $table->json('schema_types')->nullable();
            $table->unsignedTinyInteger('seo_score')->default(100);
            $table->json('issues')->nullable();
            $table->timestamp('last_crawled_at')->nullable();
            $table->timestamps();

            $table->index(['website_id', 'seo_score']);
        });

        Schema::create('seo_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->enum('audit_type', ['full_sitemap', 'single_url', 'sample'])->default('full_sitemap');
            $table->unsignedInteger('total_urls_scanned')->default(0);
            $table->unsignedInteger('healthy_urls_count')->default(0);
            $table->unsignedInteger('warning_urls_count')->default(0);
            $table->unsignedInteger('error_urls_count')->default(0);
            $table->decimal('average_seo_score', 5, 2)->default(0.00);
            $table->json('summary_issues')->nullable();
            $table->enum('status', ['pending', 'running', 'completed', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seo_audits');
        Schema::dropIfExists('crawled_urls');
    }
};
