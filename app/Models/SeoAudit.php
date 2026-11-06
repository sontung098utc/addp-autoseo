<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoAudit extends Model
{
    use HasFactory;

    protected $fillable = [
        'website_id',
        'audit_type',
        'total_urls_scanned',
        'healthy_urls_count',
        'warning_urls_count',
        'error_urls_count',
        'average_seo_score',
        'summary_issues',
        'status',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'total_urls_scanned' => 'integer',
            'healthy_urls_count' => 'integer',
            'warning_urls_count' => 'integer',
            'error_urls_count' => 'integer',
            'average_seo_score' => 'decimal:2',
            'summary_issues' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
