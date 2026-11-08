<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** AG-S19: hồ sơ doanh nghiệp/thương hiệu, mỗi website đúng một bản. Không chứa bí mật. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('business_field', 300)->nullable();
            $table->text('audience')->nullable();
            $table->string('voice', 20)->nullable(); // than_thien|chuyen_gia|trang_trong|tre_trung|khac
            $table->text('voice_notes')->nullable();
            $table->json('forbidden_terms')->nullable();
            $table->json('allowed_claims')->nullable();
            $table->json('avoid_competitors')->nullable();
            $table->json('key_messages')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_profiles');
    }
};
