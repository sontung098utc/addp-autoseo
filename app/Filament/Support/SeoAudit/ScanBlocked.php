<?php

namespace App\Filament\Support\SeoAudit;

/** Luật quét (allowlist/tần suất/quyền) không cho phép; message là lý do hiển thị cho người dùng. */
final class ScanBlocked extends \RuntimeException {}
