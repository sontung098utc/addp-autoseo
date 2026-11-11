<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent Studio: hồ sơ tùy chỉnh của từng vai trò (persona, quy tắc, tính cách), phiên bản, bài học, tài liệu riêng.
 * KHÔNG có dòng nào = dùng mặc định từ resources/agents/roles/*.php. Chạy được SQLite và MariaDB 10.6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('role_key', 40)->unique();
            $table->string('display_name', 80)->nullable();
            $table->string('title', 120)->nullable();
            $table->json('avatar')->nullable();
            $table->json('traits')->nullable();
            $table->text('persona')->nullable();
            $table->text('instructions')->nullable();
            $table->json('brain_grants')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('agent_profile_versions', function (Blueprint $table) {
            $table->id();
            $table->string('role_key', 40);
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['role_key', 'version']);
        });

        Schema::create('agent_lessons', function (Blueprint $table) {
            $table->id();
            $table->string('role_key', 40)->index();
            $table->string('text', 500);
            $table->boolean('active')->default(true);
            $table->string('source', 12)->default('manual'); // manual | run
            $table->unsignedBigInteger('run_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('agent_documents', function (Blueprint $table) {
            $table->id();
            $table->string('role_key', 40);
            $table->string('kind', 10); // knowledge | skill
            $table->string('title', 160);
            $table->text('body');
            $table->unsignedInteger('bytes')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['role_key', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_documents');
        Schema::dropIfExists('agent_lessons');
        Schema::dropIfExists('agent_profile_versions');
        Schema::dropIfExists('agent_profiles');
    }
};
