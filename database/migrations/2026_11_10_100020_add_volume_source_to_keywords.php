<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nguồn của số liệu lượt tìm. Dòng cũ để NULL (không rõ nguồn). Không đổi cột `source` (nguồn của từ khóa).
 * volume_source: csv | tay | dataforseo | gsc | mock. cpc_vnd là ƯỚC TÍNH (đổi từ USD theo tỷ giá cấu hình).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keywords', function (Blueprint $table) {
            $table->string('volume_source', 12)->nullable();
            $table->timestamp('metrics_at')->nullable();
            $table->decimal('competition', 4, 3)->nullable();
            $table->unsignedInteger('cpc_vnd')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('keywords', function (Blueprint $table) {
            $table->dropColumn(['volume_source', 'metrics_at', 'competition', 'cpc_vnd']);
        });
    }
};
