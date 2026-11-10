<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AG-S01 (lát dọc, bản đề xuất cho Dev1 rà): nhật ký một "phiên chạy" của đội AI và từng bước trong đó.
 * Đây là bảng nền cho AG-B01/B02/B03; Dev1 có thể đổi tên/cột, giao diện đọc qua model nên chỉ sửa một chỗ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->unsignedBigInteger('campaign_id')->nullable()->index();   // chiến dịch giao việc (nếu có)
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('trigger', 20)->default('manual');         // manual | schedule
            $table->string('status', 20)->default('running');         // running | ready_for_review | held | blocked | failed
            $table->string('trust_tier', 4)->default('T1');           // T1 = chỉ tới bản nháp
            $table->string('risk_level', 10)->nullable();             // low | medium | high
            $table->string('ai_mode', 10)->default('mock');           // real | mock
            $table->text('outcome_reason')->nullable();
            $table->foreignId('final_version_id')->nullable()->constrained('article_versions')->nullOnDelete();
            $table->unsignedTinyInteger('repair_rounds')->default(0);
            $table->unsignedInteger('tokens_used')->default(0);
            $table->decimal('cost_vnd', 12, 2)->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['website_id', 'status']);
            $table->index('article_id');
        });

        Schema::create('agent_run_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_run_id')->constrained('agent_runs')->cascadeOnDelete();
            $table->unsignedSmallInteger('seq');
            $table->string('stage', 20);                              // guard | risk | brief | write | grade | repair | handoff
            $table->string('role', 20);                               // system | strategist | writer | editor
            $table->string('status', 10);                             // ok | warn | blocked | failed | skipped
            $table->string('title');
            $table->text('detail')->nullable();                       // vì sao hệ thống quyết định như vậy
            $table->json('data')->nullable();
            $table->string('model', 60)->nullable();
            $table->unsignedInteger('tokens_used')->default(0);
            $table->decimal('cost_vnd', 12, 2)->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['agent_run_id', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_run_steps');
        Schema::dropIfExists('agent_runs');
    }
};
