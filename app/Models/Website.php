<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Website extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'cms_type',
        'base_url',
        'store_scope',
        'encrypted_credentials',
        'capability_flags',
        'status',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'encrypted_credentials' => 'encrypted:array',
            'capability_flags' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }

    public function publications(): HasMany
    {
        return $this->hasMany(Publication::class);
    }
}
