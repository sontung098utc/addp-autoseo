<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Danh sách model THẬT mà từng tài khoản AI trả về (gọi API danh sách model của hãng), để không phải cố định danh mục trong mã:
 * model ra mới / ngừng thường xuyên. Mỗi dòng nhớ trạng thái (active | gone) và mốc để hiện dấu "Mới" / "Đã ngưng" trong ~7 ngày.
 * Không đụng cột `models` của ai_providers (model ĐÃ BẬT) và không xóa model khỏi bản gán vai trò.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_provider_models')) {
            Schema::create('ai_provider_models', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ai_provider_id')->constrained('ai_providers')->cascadeOnDelete();
                $table->string('model_id', 160);
                $table->string('name', 200)->nullable();
                $table->string('kind', 10)->default('text'); // text | image
                $table->string('status', 10)->default('active')->index(); // active | gone
                $table->timestamp('first_seen_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamp('new_until')->nullable(); // hiện dấu "Mới" tới mốc này (null = không phải model mới)
                $table->timestamp('gone_at')->nullable();
                $table->timestamp('gone_until')->nullable(); // hiện dấu "Vừa ngưng" tới mốc này
                $table->timestamps();
                $table->unique(['ai_provider_id', 'model_id']);
            });
        }
        if (! Schema::hasColumn('ai_providers', 'models_synced_at')) {
            Schema::table('ai_providers', function (Blueprint $table) {
                $table->timestamp('models_synced_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Không xóa: dữ liệu đồng bộ là lịch sử trạng thái model.
    }
};
