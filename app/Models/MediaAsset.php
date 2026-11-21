<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class MediaAsset extends Model
{
    use HasFactory;

    public const KINDS = ['photo', 'product', 'logo_brand', 'infographic', 'ai_generated', 'crawled_ref', 'screenshot', 'other'];

    public const STATUSES = ['inbox', 'ready', 'archived'];

    protected $fillable = [
        'website_id',
        'kind',
        'status',
        'folder_id',
        'usage_count',
        'slug',
        'uploaded_by',
        'disk',
        'file_path',
        'thumbnail_path',
        'file_name',
        'original_name',
        'mime_type',
        'file_size',
        'width',
        'height',
        'sha256',
        'title',
        'alt_text',
        'caption',
        'is_featured',
        'tags',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'is_featured' => 'boolean',
            'tags' => 'array',
            'metadata' => 'array',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaCollection::class, 'folder_id');
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(MediaCollection::class, 'media_collection_asset')->withTimestamps();
    }

    public function tagModels(): BelongsToMany
    {
        return $this->belongsToMany(MediaTag::class, 'media_asset_tag');
    }

    public function articleVersions(): BelongsToMany
    {
        return $this->belongsToMany(ArticleVersion::class, 'article_version_media')
            ->withPivot(['is_featured', 'position', 'custom_alt'])
            ->withTimestamps();
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk($this->disk)->url($this->file_path);
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        if (! $this->thumbnail_path) {
            return $this->url;
        }

        return Storage::disk($this->disk)->url($this->thumbnail_path);
    }

    public function getHumanFileSizeAttribute(): string
    {
        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}
