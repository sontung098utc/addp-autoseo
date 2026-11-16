<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** AG-S02: chế độ chiến dịch, hạn mức chi phí/bài nháp mỗi ngày, nguồn việc, và danh sách "chờ bạn bổ sung". Chỉ thêm cột/bảng mới. Bản đề xuất cho Dev1 rà. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('mode', 12)->default('balanced');                    // cautious | balanced | autonomous (khóa)
            $table->unsignedInteger('daily_cost_cap_vnd')->default(150000);     // trần chi phí AI/ngày (giờ VN)
            $table->unsignedSmallInteger('daily_draft_cap')->default(5);        // trần bài đẩy nháp/ngày
            $table->string('source', 10)->default('plan');                      // plan | keywords
        });

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->string('trust_mode', 12)->nullable();                       // nhãn chế độ của chiến dịch lúc chạy (chỉ gắn nhãn)
        });

        Schema::create('campaign_waits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->string('code', 30);                                         // no_keywords | no_plan ...
            $table->string('title');
            $table->text('detail')->nullable();
            $table->string('status', 10)->default('open');                      // open | dismissed | expired | resolved
            $table->timestamp('expires_at');
            $table->text('limit_note')->nullable();                             // ghi giới hạn khi tự bỏ qua
            $table->timestamps();

            $table->index(['campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_waits');
        Schema::table('agent_runs', fn (Blueprint $t) => $t->dropColumn('trust_mode'));
        Schema::table('campaigns', fn (Blueprint $t) => $t->dropColumn(['mode', 'daily_cost_cap_vnd', 'daily_draft_cap', 'source']));
    }
};
