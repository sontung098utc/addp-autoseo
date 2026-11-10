<?php

namespace App\Services\Search;

/** Không tìm web được (chưa có khóa ở production, hoặc nhà cung cấp lỗi). Thông báo luôn ẩn khóa. */
final class WebSearchUnavailable extends \RuntimeException {}
