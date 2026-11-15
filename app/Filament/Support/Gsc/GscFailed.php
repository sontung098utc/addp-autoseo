<?php

namespace App\Filament\Support\Gsc;

/** Lỗi khi lấy dữ liệu Search Console thật. Thông báo không chứa khóa riêng hay token, an toàn để hiển thị. */
final class GscFailed extends \RuntimeException {}
