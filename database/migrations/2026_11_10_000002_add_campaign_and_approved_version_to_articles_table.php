<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('website_id')->constrained('campaigns')->nullOnDelete();
            $table->foreignId('approved_version_id')->nullable()->after('workflow_status')->constrained('article_versions')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropForeign(['campaign_id']);
            $table->dropColumn('campaign_id');
            $table->dropForeign(['approved_version_id']);
            $table->dropColumn('approved_version_id');
        });
    }
};
