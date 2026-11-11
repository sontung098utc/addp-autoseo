<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE publications MODIFY COLUMN status ENUM('scheduled', 'publishing', 'published', 'failed', 'needs_reconcile', 'held') NOT NULL DEFAULT 'scheduled'");
        } elseif (DB::getDriverName() === 'sqlite') {
            Schema::table('publications', function (Blueprint $table) {
                $table->string('status', 32)->default('scheduled')->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE publications MODIFY COLUMN status ENUM('scheduled', 'publishing', 'published', 'failed', 'needs_reconcile') NOT NULL DEFAULT 'scheduled'");
        }
    }
};
