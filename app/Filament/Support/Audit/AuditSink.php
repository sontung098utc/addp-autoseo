<?php

namespace App\Filament\Support\Audit;

/** Nơi ghi entry audit (log hệ thống hiện tại; bảng audit_logs sẽ do Dev1 bổ sung). */
interface AuditSink
{
    public function write(array $entry): void;
}
