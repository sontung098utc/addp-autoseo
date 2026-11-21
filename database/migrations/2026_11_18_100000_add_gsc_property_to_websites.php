<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Thuộc tính Search Console của từng website (sc-domain:ten-mien.vn hoặc https://ten-mien.vn/). Trống = tự suy ra từ base_url. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('websites', 'gsc_property')) {
            Schema::table('websites', function (Blueprint $table) {
                $table->string('gsc_property', 255)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('websites', 'gsc_property')) {
            Schema::table('websites', function (Blueprint $table) {
                $table->dropColumn('gsc_property');
            });
        }
    }
};
