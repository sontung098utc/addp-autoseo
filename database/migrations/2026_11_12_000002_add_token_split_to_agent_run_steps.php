<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Token vào/ra của từng bước (trước đây chỉ có tổng tokens_used nên không kiểm lại được chi phí). NULL = không rõ (bước cũ, AI giả lập). Chạy được trên SQLite và MySQL. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_run_steps', function (Blueprint $table) {
            if (! Schema::hasColumn('agent_run_steps', 'prompt_tokens')) {
                $table->unsignedInteger('prompt_tokens')->nullable();
            }
            if (! Schema::hasColumn('agent_run_steps', 'completion_tokens')) {
                $table->unsignedInteger('completion_tokens')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('agent_run_steps', function (Blueprint $table) {
            $table->dropColumn(['prompt_tokens', 'completion_tokens']);
        });
    }
};
