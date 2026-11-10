<?php

namespace App\Filament\Support\Audit;

use App\Models\AuditLog;
use Illuminate\Support\Carbon;

class DatabaseAuditSink implements AuditSink
{
    public function write(array $entry): void
    {
        AuditLog::create([
            'action' => (string) ($entry['action'] ?? 'unknown'),
            'actor_id' => $entry['actor_id'] ?? null,
            'actor_role' => $entry['actor_role'] ?? null,
            'article_id' => $entry['article_id'] ?? null,
            'article_version_id' => $entry['article_version_id'] ?? null,
            'website_id' => $entry['website_id'] ?? null,
            'reason' => $entry['reason'] ?? null,
            'self_approved' => (bool) ($entry['self_approved'] ?? false),
            'extra' => $entry['extra'] ?? null,
            'at_utc' => isset($entry['at_utc']) ? Carbon::parse($entry['at_utc']) : now('UTC'),
        ]);
    }
}
