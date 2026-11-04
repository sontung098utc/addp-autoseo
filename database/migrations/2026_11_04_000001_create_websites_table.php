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
        Schema::create('websites', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('cms_type', ['wordpress', 'magento2']);
            $table->string('base_url');
            $table->string('store_scope')->default('default'); // store view code hoặc blog prefix
            $table->text('encrypted_credentials'); // JSON mã hóa AES chứa App Password hoặc OAuth credentials
            $table->json('capability_flags')->nullable();
            $table->enum('status', ['pending', 'active', 'revoked', 'error'])->default('pending');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['base_url', 'store_scope']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('websites');
    }
};
