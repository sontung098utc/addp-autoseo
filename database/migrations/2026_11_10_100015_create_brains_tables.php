<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AG-B1: Kho trí tuệ P1 (KHO_TRI_TUE_DESIGN.md §9, KHO_TRI_TUE_RESEARCH_VA_KIEN_TRUC.md §3). Bản đề xuất cho Dev1 rà.
 * - brains / brain_website / brain_profiles; brain_id NULLABLE vào 4 bảng kho kiến thức (KHÔNG đổi/xóa website_id); campaigns.brain_ids (json, null = dùng Trí tuệ của website).
 * - Chuyển dữ liệu: mỗi website một "Trí tuệ của <tên>"; mục có website_id null → Trí tuệ "Chung" gắn mọi website. Chạy lại được (idempotent):
 *   chỉ tạo khi chưa có (theo slug), chỉ gán brain_id cho bản ghi còn NULL.
 * - Không dùng khóa ngoại cho brain_id trên bảng cũ (SQLite không thêm FK vào bảng có sẵn); toàn vẹn do ứng dụng giữ.
 */
return new class extends Migration
{
    private const KB_TABLES = ['knowledge_sources', 'knowledge_topics', 'knowledge_items', 'knowledge_gaps'];

    public function up(): void
    {
        if (! Schema::hasTable('brains')) {
            Schema::create('brains', function (Blueprint $table) {
                $table->id();
                $table->string('name', 160);
                $table->string('slug', 190)->unique();
                $table->string('domain_key', 60)->nullable();
                $table->string('template_key', 60)->nullable();
                $table->string('language', 8)->default('vi');
                $table->text('description')->nullable();
                $table->string('status', 12)->default('active'); // active|archived
                $table->json('settings')->nullable(); // {ai_reliance: free|numbers|must_cite, risk_class: low|medium|high}
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('brain_website')) {
            Schema::create('brain_website', function (Blueprint $table) {
                $table->id();
                $table->foreignId('brain_id')->constrained('brains')->cascadeOnDelete();
                $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
                $table->unsignedSmallInteger('priority')->default(10);
                $table->timestamps();
                $table->unique(['brain_id', 'website_id']);
            });
        }
        if (! Schema::hasTable('brain_profiles')) {
            Schema::create('brain_profiles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('brain_id')->unique()->constrained('brains')->cascadeOnDelete();
                $table->json('voice')->nullable();
                $table->json('structure')->nullable();
                $table->json('terminology')->nullable();
                $table->json('reasoning')->nullable();
                $table->text('rubric_notes')->nullable();
                $table->json('exemplars')->nullable();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
        foreach (self::KB_TABLES as $t) {
            if (! Schema::hasColumn($t, 'brain_id')) {
                Schema::table($t, function (Blueprint $table) use ($t) {
                    $table->unsignedBigInteger('brain_id')->nullable();
                    $table->index('brain_id', $t.'_brain_id_index');
                });
            }
        }
        if (! Schema::hasColumn('campaigns', 'brain_ids')) {
            Schema::table('campaigns', function (Blueprint $table) {
                $table->json('brain_ids')->nullable();
            });
        }

        $this->migrateData();
    }

    private function migrateData(): void
    {
        $now = now();
        $settings = json_encode(['ai_reliance' => 'numbers', 'risk_class' => 'low']);

        $ensure = function (string $slug, string $name, string $desc) use ($now, $settings): int {
            $id = DB::table('brains')->where('slug', $slug)->value('id');
            if ($id) {
                return (int) $id;
            }

            return (int) DB::table('brains')->insertGetId([
                'name' => mb_substr($name, 0, 160), 'slug' => $slug, 'domain_key' => 'thuong_hieu', 'template_key' => 'thuong_hieu', 'language' => 'vi',
                'description' => $desc, 'status' => 'active', 'settings' => $settings, 'created_at' => $now, 'updated_at' => $now,
            ]);
        };
        $link = function (int $brainId, int $websiteId, int $priority) use ($now): void {
            DB::table('brain_website')->insertOrIgnore(['brain_id' => $brainId, 'website_id' => $websiteId, 'priority' => $priority, 'created_at' => $now, 'updated_at' => $now]);
        };
        $profile = function (int $brainId) use ($now): void {
            DB::table('brain_profiles')->insertOrIgnore(['brain_id' => $brainId, 'created_at' => $now, 'updated_at' => $now]);
        };

        $siteBrains = [];
        foreach (DB::table('websites')->orderBy('id')->get(['id', 'name']) as $w) {
            $b = $ensure('tri-tue-website-'.$w->id, 'Trí tuệ của '.$w->name, 'Tạo tự động từ kho kiến thức sẵn có của website.');
            $siteBrains[$w->id] = $b;
            $link($b, (int) $w->id, 10);
            $profile($b);
            foreach (self::KB_TABLES as $t) {
                DB::table($t)->where('website_id', $w->id)->whereNull('brain_id')->update(['brain_id' => $b]);
            }
        }

        $hasShared = false;
        foreach (self::KB_TABLES as $t) {
            $hasShared = $hasShared || DB::table($t)->whereNull('website_id')->whereNull('brain_id')->exists();
        }
        if ($hasShared) {
            $common = $ensure('chung', 'Chung', 'Kiến thức dùng chung cho mọi website (trước đây không gắn website nào).');
            $profile($common);
            foreach (array_keys($siteBrains) as $siteId) {
                $link($common, (int) $siteId, 100); // ưu tiên sau Trí tuệ riêng của website
            }
            foreach (self::KB_TABLES as $t) {
                DB::table($t)->whereNull('website_id')->whereNull('brain_id')->update(['brain_id' => $common]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('campaigns', 'brain_ids')) {
            Schema::table('campaigns', fn (Blueprint $t) => $t->dropColumn('brain_ids'));
        }
        foreach (self::KB_TABLES as $t) {
            if (Schema::hasColumn($t, 'brain_id')) {
                Schema::table($t, function (Blueprint $table) use ($t) {
                    $table->dropIndex($t.'_brain_id_index');
                    $table->dropColumn('brain_id');
                });
            }
        }
        Schema::dropIfExists('brain_profiles');
        Schema::dropIfExists('brain_website');
        Schema::dropIfExists('brains');
    }
};
