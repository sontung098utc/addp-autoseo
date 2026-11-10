<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AG-S17: gán theo VAI TRÒ (model chính + dự phòng, bật/tắt và ghim phiên bản từng kỹ năng). Bản đề xuất cho Dev1 rà (AG-B06).
 * AiModelRouter ưu tiên bản gán theo vai trò, rồi mới tới ai_model_routes (theo loại việc). Không lưu khóa API ở đây.
 * skills: {"<role>/<key>": {"enabled": bool, "pin": int|null}}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_role_assignments', function (Blueprint $table) {
            $table->id();
            $table->string('role_key', 40)->unique();
            $table->foreignId('provider_id')->nullable()->constrained('ai_providers')->nullOnDelete();
            $table->string('model', 80)->nullable();
            $table->foreignId('fallback_provider_id')->nullable()->constrained('ai_providers')->nullOnDelete();
            $table->string('fallback_model', 80)->nullable();
            $table->boolean('enabled')->default(true);
            $table->json('skills')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_role_assignments');
    }
};
