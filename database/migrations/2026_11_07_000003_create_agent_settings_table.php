<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** AG-S01d: cấu hình kết nối AI của đội AI (một dòng duy nhất). Khóa API lưu MÃ HÓA (AGENTS.md §1). Bản đề xuất cho Dev1 rà (AG-B06). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20)->default('gemini');
            $table->text('api_key')->nullable();                       // cast 'encrypted' ở model
            $table->string('model', 80)->nullable();
            $table->decimal('run_budget_vnd', 12, 2)->default(50000);
            $table->unsignedTinyInteger('max_repair_rounds')->default(3);
            $table->timestamp('last_test_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->string('last_test_message', 200)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_settings');
    }
};
