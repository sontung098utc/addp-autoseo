<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AG-S20: kho kiến thức lõi (KNOWLEDGE_BASE_DESIGN.md §5). Bản đề xuất cho Dev1 rà.
 * website_id NULL = kiến thức chung cho mọi website. Chỉ atom status=approved mới được AI đọc.
 * FULLTEXT(title, body) chỉ tạo trên MySQL/MariaDB; SQLite (test) dùng LIKE dự phòng ở KnowledgeRetriever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->nullable()->constrained('websites')->cascadeOnDelete();
            $table->string('kind', 12)->default('paste'); // paste|file|url|manual
            $table->string('title', 200);
            $table->string('url', 500)->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->longText('raw_text')->nullable();
            $table->string('status', 16)->default('new'); // new|split
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['website_id', 'status']);
        });

        Schema::create('knowledge_topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->nullable()->constrained('websites')->cascadeOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable(); // tối đa 2 cấp, do service kiểm
            $table->string('name', 120);
            $table->timestamps();
            $table->index(['website_id', 'parent_id']);
        });

        Schema::create('knowledge_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->nullable()->constrained('websites')->cascadeOnDelete();
            $table->foreignId('source_id')->nullable()->constrained('knowledge_sources')->nullOnDelete();
            $table->foreignId('topic_id')->nullable()->constrained('knowledge_topics')->nullOnDelete();
            $table->string('type', 12)->default('fact'); // fact|price|policy|process|faq|messaging
            $table->string('title', 200);
            $table->text('body');
            $table->string('entity', 160)->nullable();
            $table->string('field', 120)->nullable();
            $table->string('value', 255)->nullable();
            $table->string('value_unit', 40)->nullable();
            $table->json('tags')->nullable();
            $table->string('status', 12)->default('proposed'); // proposed|approved|outdated|rejected
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->string('created_by', 40)->nullable(); // 'user:<id>' hoặc 'rule' (tách tất định) hoặc 'ai'
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('last_used_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->index(['website_id', 'status']);
            $table->index(['entity', 'field']);
            $table->index('content_hash');
        });
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE knowledge_items ADD FULLTEXT knowledge_items_title_body_fulltext (title, body)');
        }

        Schema::create('knowledge_item_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('knowledge_items')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('title', 200);
            $table->text('body');
            $table->string('entity', 160)->nullable();
            $table->string('field', 120)->nullable();
            $table->string('value', 255)->nullable();
            $table->string('value_unit', 40)->nullable();
            $table->string('status', 12);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 200)->nullable();
            $table->timestamps();
            $table->unique(['item_id', 'version']);
        });

        Schema::create('knowledge_item_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_version_id')->constrained('article_versions')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('knowledge_items')->cascadeOnDelete();
            $table->unsignedInteger('item_version');
            $table->timestamps();
            $table->unique(['article_version_id', 'item_id']);
        });

        Schema::create('knowledge_gaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->string('query', 190);
            $table->foreignId('article_id')->nullable()->constrained('articles')->nullOnDelete();
            $table->unsignedInteger('count')->default(1);
            $table->timestamps();
            $table->unique(['website_id', 'query']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_gaps');
        Schema::dropIfExists('knowledge_item_usages');
        Schema::dropIfExists('knowledge_item_versions');
        Schema::dropIfExists('knowledge_items');
        Schema::dropIfExists('knowledge_topics');
        Schema::dropIfExists('knowledge_sources');
    }
};
