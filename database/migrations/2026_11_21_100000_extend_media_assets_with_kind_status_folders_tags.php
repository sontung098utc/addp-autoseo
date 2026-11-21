<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Tái cấu trúc thư viện ảnh, bước 1: chỉ BỔ SUNG, không đổi/xóa cột hay file hiện có.
 * - media_assets: kind, status, folder_id, usage_count, slug (mặc định giữ nguyên hành vi cũ: ảnh cũ ở trạng thái "ready").
 * - media_collections (cây thư mục 2 cấp), media_collection_asset (bộ sưu tập phụ), media_tags, media_asset_tag.
 * - Giữ cột JSON `tags` để mọi nơi đang đọc vẫn chạy; bảng tag chỉ được backfill từ đó.
 * Không đụng file_path/disk/thumbnail_path nên URL đã đăng lên CMS và việc đăng bài không bị ảnh hưởng.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('media_collections')->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['parent_id', 'slug']);
        });

        Schema::create('media_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::table('media_assets', function (Blueprint $table) {
            $table->string('kind', 30)->default('photo')->after('website_id')->index();
            $table->string('status', 20)->default('ready')->after('kind')->index();
            $table->foreignId('folder_id')->nullable()->after('status')->constrained('media_collections')->nullOnDelete();
            $table->unsignedInteger('usage_count')->default(0)->after('is_featured');
            $table->string('slug')->nullable()->after('title');
        });

        Schema::create('media_collection_asset', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_collection_id')->constrained('media_collections')->cascadeOnDelete();
            $table->foreignId('media_asset_id')->constrained('media_assets')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['media_collection_id', 'media_asset_id'], 'media_collection_asset_unique');
        });

        Schema::create('media_asset_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_asset_id')->constrained('media_assets')->cascadeOnDelete();
            $table->foreignId('media_tag_id')->constrained('media_tags')->cascadeOnDelete();

            $table->unique(['media_asset_id', 'media_tag_id']);
        });

        $this->backfill();
    }

    /**
     * Backfill idempotent, chỉ ghi vào cột/bảng mới. Công khai để test gọi trực tiếp.
     */
    public function backfill(): void
    {
        // usage_count = số phiên bản bài đang gắn ảnh.
        DB::table('media_assets')->orderBy('id')->select('id', 'metadata', 'tags')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                $meta = json_decode((string) $row->metadata, true);
                $update = [
                    'usage_count' => DB::table('article_version_media')->where('media_asset_id', $row->id)->count(),
                ];
                if (is_array($meta) && ($meta['ai_generated'] ?? false) === true) {
                    $update['kind'] = 'ai_generated';
                }
                DB::table('media_assets')->where('id', $row->id)->update($update);

                $tags = json_decode((string) $row->tags, true);
                if (! is_array($tags)) {
                    continue;
                }
                foreach ($tags as $name) {
                    $name = is_string($name) ? trim($name) : '';
                    $slug = Str::slug($name);
                    if ($name === '' || $slug === '') {
                        continue;
                    }
                    $tagId = DB::table('media_tags')->where('slug', $slug)->value('id')
                        ?? DB::table('media_tags')->insertGetId(['name' => mb_substr($name, 0, 255), 'slug' => mb_substr($slug, 0, 255), 'created_at' => now(), 'updated_at' => now()]);
                    $exists = DB::table('media_asset_tag')->where('media_asset_id', $row->id)->where('media_tag_id', $tagId)->exists();
                    if (! $exists) {
                        DB::table('media_asset_tag')->insert(['media_asset_id' => $row->id, 'media_tag_id' => $tagId]);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_asset_tag');
        Schema::dropIfExists('media_collection_asset');
        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropForeign(['folder_id']);
            $table->dropIndex(['kind']);
            $table->dropIndex(['status']);
            $table->dropColumn(['kind', 'status', 'folder_id', 'usage_count', 'slug']);
        });
        Schema::dropIfExists('media_tags');
        Schema::dropIfExists('media_collections');
    }
};
