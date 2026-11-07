<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AG-S11: nhiều nhà cung cấp AI + định tuyến model theo loại việc. Bản đề xuất cho Dev1 rà (AG-B06).
 * - api_key lưu MÃ HÓA (cast 'encrypted' ở model), không bao giờ hiển thị lại (AGENTS.md §1).
 * - Dữ liệu cũ: nếu agent_settings đã có khóa Gemini thì sao chép NGUYÊN giá trị đã mã hóa (không giải mã) sang một provider Gemini,
 *   kèm 3 route outline/write/repair dùng model cũ. Không xóa/sửa agent_settings nên có thể quay lại.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('kind', 20);                                 // gemini | anthropic | openai_compat
            $table->string('base_url', 255)->nullable();                // bắt buộc với openai_compat
            $table->text('api_key')->nullable();                        // cast 'encrypted'
            $table->decimal('monthly_budget_vnd', 14, 2)->nullable();
            $table->boolean('enabled')->default(true);
            $table->json('pricing')->nullable();                        // {"model-id": {"input": USD/1M, "output": USD/1M}}; thiếu thì ước tính trên cao
            $table->timestamp('last_test_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->string('last_test_message', 200)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ai_model_routes', function (Blueprint $table) {
            $table->id();
            $table->string('task', 20)->unique();                       // outline | write | repair | summary
            $table->string('tier', 12)->default('standard');            // cheap | standard | reasoning
            $table->foreignId('primary_provider_id')->nullable()->constrained('ai_providers')->nullOnDelete();
            $table->string('primary_model', 80)->nullable();
            $table->foreignId('fallback_provider_id')->nullable()->constrained('ai_providers')->nullOnDelete();
            $table->string('fallback_model', 80)->nullable();
            $table->decimal('cost_cap_vnd_per_call', 12, 2)->nullable();
            $table->timestamps();
        });

        $legacy = Schema::hasTable('agent_settings') ? DB::table('agent_settings')->orderBy('id')->first() : null;
        if ($legacy && ! empty($legacy->api_key)) {
            $now = now();
            $model = $legacy->model ?: 'gemini-1.5-flash';
            $id = DB::table('ai_providers')->insertGetId([
                'name' => 'Google Gemini', 'kind' => 'gemini', 'api_key' => $legacy->api_key, 'enabled' => true,
                'last_test_at' => $legacy->last_test_at, 'last_test_ok' => $legacy->last_test_ok, 'last_test_message' => $legacy->last_test_message,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach (['outline' => 'cheap', 'write' => 'standard', 'repair' => 'standard'] as $task => $tier) {
                DB::table('ai_model_routes')->insert(['task' => $task, 'tier' => $tier, 'primary_provider_id' => $id, 'primary_model' => $model, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_model_routes');
        Schema::dropIfExists('ai_providers');
    }
};
