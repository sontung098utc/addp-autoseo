<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** AG-S01: chiến dịch = nơi người điều phối đặt mục tiêu; hệ thống tự giao từng từ khóa cho đội AI trong giới hạn mỗi ngày. Bản đề xuất cho Dev1 rà. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('status', 10)->default('paused');          // active | paused
            $table->json('keywords');                                 // danh sách từ khóa cần viết
            $table->unsignedSmallInteger('daily_limit')->default(3);  // rào chắn nội dung hàng loạt: tối đa bài/ngày (giờ VN)
            $table->timestamp('last_tick_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'website_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
