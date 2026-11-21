<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** Thư mục ảnh dạng cây nhiều cấp (như thư mục của hệ điều hành). Ảnh có một thư mục chính (media_assets.folder_id). */
class MediaCollection extends Model
{
    /** Giới hạn độ sâu để tránh cây quá sâu (gốc = cấp 1). */
    public const MAX_DEPTH = 8;

    protected $fillable = ['parent_id', 'name', 'slug', 'position'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('name');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(MediaAsset::class, 'folder_id');
    }

    public function extraAssets(): BelongsToMany
    {
        return $this->belongsToMany(MediaAsset::class, 'media_collection_asset')->withTimestamps();
    }

    /** Toàn bộ thư mục dạng [id => [parent_id, name, position]] (cây ảnh nhỏ, nạp một lần). */
    protected static function flat(): array
    {
        return self::query()->orderBy('position')->orderBy('name')->get(['id', 'parent_id', 'name', 'position'])
            ->mapWithKeys(fn ($c) => [$c->id => ['parent_id' => $c->parent_id, 'name' => $c->name]])->all();
    }

    /** Chuỗi tổ tiên từ gốc đến chính nó: [[id, name], ...]. Chống vòng lặp. */
    public static function pathOf(?int $id): array
    {
        $flat = self::flat();
        $path = [];
        $seen = [];
        while ($id !== null && isset($flat[$id]) && ! isset($seen[$id])) {
            $seen[$id] = true;
            array_unshift($path, ['id' => $id, 'name' => $flat[$id]['name']]);
            $id = $flat[$id]['parent_id'];
        }

        return $path;
    }

    public static function depthOf(?int $id): int
    {
        return count(self::pathOf($id));
    }

    public function getPathLabelAttribute(): string
    {
        return implode(' › ', array_column(self::pathOf($this->id), 'name'));
    }

    /** Id của thư mục và mọi thư mục con cháu. */
    public static function withDescendantIds(int $id): array
    {
        $flat = self::flat();
        $out = [$id];
        for ($i = 0; $i < count($out); $i++) {
            foreach ($flat as $cid => $c) {
                if ($c['parent_id'] === $out[$i] && ! in_array($cid, $out, true)) {
                    $out[] = $cid;
                }
            }
        }

        return $out;
    }

    /** Cây lồng nhau cho giao diện: [{id,name,assets,total,children:[...]}], assets = trực tiếp, total = gồm thư mục con. */
    public static function tree(): array
    {
        $flat = self::flat();
        $counts = MediaAsset::query()->whereNotNull('folder_id')->selectRaw('folder_id, count(*) c')->groupBy('folder_id')->pluck('c', 'folder_id')->all();
        $build = function (?int $parent, int $depth) use (&$build, $flat, $counts): array {
            $nodes = [];
            foreach ($flat as $id => $c) {
                if ($c['parent_id'] !== $parent || $depth > self::MAX_DEPTH + 2) {
                    continue;
                }
                $kids = $build($id, $depth + 1);
                $nodes[] = [
                    'id' => $id, 'name' => $c['name'], 'assets' => (int) ($counts[$id] ?? 0),
                    'total' => (int) ($counts[$id] ?? 0) + array_sum(array_column($kids, 'total')),
                    'children' => $kids,
                ];
            }

            return $nodes;
        };

        return $build(null, 1);
    }

    /** [id => "Cha › Con › Cháu"] theo thứ tự cây, dùng cho ô chọn thư mục. $exclude: bỏ thư mục này và con cháu (khi di chuyển). */
    public static function treeOptions(?int $exclude = null): array
    {
        $skip = $exclude ? self::withDescendantIds($exclude) : [];
        $out = [];
        $walk = function (array $nodes, string $prefix) use (&$walk, &$out, $skip): void {
            foreach ($nodes as $n) {
                if (in_array($n['id'], $skip, true)) {
                    continue;
                }
                $label = $prefix.$n['name'];
                $out[$n['id']] = $label;
                $walk($n['children'], $label.' › ');
            }
        };
        $walk(self::tree(), '');

        return $out;
    }

    /** Slug duy nhất trong cùng thư mục cha. */
    public static function uniqueSlug(string $name, ?int $parentId, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'thu-muc';
        $slug = $base;
        $i = 2;
        while (self::query()->where('parent_id', $parentId)->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
