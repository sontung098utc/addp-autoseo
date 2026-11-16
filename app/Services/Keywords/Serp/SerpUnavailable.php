<?php

namespace App\Services\Keywords\Serp;

/** Chưa có nguồn dữ liệu SERP. Trạng thái "Bị chặn tích hợp": không dùng dữ liệu giả thay thế. */
final class SerpUnavailable extends \RuntimeException {}
