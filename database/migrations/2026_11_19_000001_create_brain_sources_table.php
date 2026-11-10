<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AG-B3 (Kho trí tuệ P2, Tầng 3): Sổ nguồn uy tín của từng Trí tuệ. Bản đề xuất cho Dev1 rà.
 * - Mỗi hàng = một nguồn (tên miền hoặc tên tổ chức) kèm bậc tin cậy do NGƯỜI/chuyên gia xếp. Hệ thống không tự khẳng định nguồn uy tín:
 *   hàng `suggested` (từ mẫu) chưa có bậc và KHÔNG được tính; chỉ `confirmed` có bậc 1-4 hoặc `blocked` mới có hiệu lực.
 * - Nhập `brains.settings.suggested_sources` (gợi ý của mẫu sức khỏe) thành hàng `suggested`; chạy lại được, không đụng hàng đã có.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('brain_sources')) {
            Schema::create('brain_sources', function (Blueprint $table) {
                $table->id();
                $table->foreignId('brain_id')->constrained('brains')->cascadeOnDelete();
                $table->string('name', 190);
                $table->string('domain', 190)->nullable();
                $table->string('tier', 8)->nullable(); // '1'|'2'|'3'|'4'|'blocked'; null khi chưa xếp
                $table->string('status', 12)->default('suggested'); // suggested|confirmed
                $table->string('note', 500)->nullable();
                $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('confirmed_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['brain_id', 'domain']);
                $table->index(['brain_id', 'status']);
            });
        }

        $now = now();
        foreach (DB::table('brains')->whereNotNull('settings')->get(['id', 'settings']) as $b) {
            $settings = json_decode((string) $b->settings, true);
            foreach ((array) ($settings['suggested_sources'] ?? []) as $s) {
                $domain = strtolower(trim((string) ($s['domain'] ?? '')));
                $name = trim((string) ($s['org'] ?? $domain));
                if ($domain === '' || $name === '') {
                    continue;
                }
                DB::table('brain_sources')->insertOrIgnore([
                    'brain_id' => $b->id, 'name' => mb_substr($name, 0, 190), 'domain' => mb_substr($domain, 0, 190), 'tier' => null,
                    'status' => 'suggested', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('brain_sources');
    }
};
