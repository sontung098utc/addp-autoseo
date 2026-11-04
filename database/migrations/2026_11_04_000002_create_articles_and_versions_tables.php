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
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('primary_keyword');
            $table->json('secondary_keywords')->nullable();
            $table->enum('search_intent', ['informational', 'commercial', 'transactional', 'navigational'])->default('informational');
            $table->string('topic_cluster')->nullable();
            $table->enum('workflow_status', ['planned', 'drafting', 'needs_review', 'approved', 'rejected', 'scheduled', 'published'])->default('planned');
            $table->timestamps();
        });

        Schema::create('article_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('title');
            $table->string('slug');
            $table->json('outline')->nullable();
            $table->longText('html_content');
            $table->string('seo_title', 70)->nullable();
            $table->string('meta_description', 160)->nullable();
            $table->string('focus_keyword')->nullable();
            $table->json('faq_schema')->nullable();
            $table->json('sources_cited')->nullable();
            $table->string('ai_provider')->nullable();
            $table->string('ai_model')->nullable();
            $table->string('prompt_version')->nullable();
            $table->unsignedInteger('tokens_used')->default(0);
            $table->decimal('cost_vnd', 10, 2)->default(0.00);
            $table->enum('review_status', ['drafting', 'needs_review', 'approved', 'rejected'])->default('needs_review');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();

            $table->unique(['article_id', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('article_versions');
        Schema::dropIfExists('articles');
    }
};
