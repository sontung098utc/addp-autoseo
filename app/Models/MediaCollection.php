<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Thư mục ảnh (cây 2 cấp). Ảnh có một thư mục chính (media_assets.folder_id) và có thể thuộc thêm bộ sưu tập phụ. */
class MediaCollection extends Model
{
    protected $fillable = ['parent_id', 'name', 'slug', 'position'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(MediaAsset::class, 'folder_id');
    }

    public function extraAssets(): BelongsToMany
    {
        return $this->belongsToMany(MediaAsset::class, 'media_collection_asset')->withTimestamps();
    }
}
