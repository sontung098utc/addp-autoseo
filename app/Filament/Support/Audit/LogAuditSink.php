<?php

namespace App\Filament\Support\Audit;

use Illuminate\Support\Facades\Log;

/** Sink mặc định: ghi vào log hệ thống. */
class LogAuditSink implements AuditSink
{
    public function write(array $entry): void
    {
        Log::info('audit', $entry);
    }
}
