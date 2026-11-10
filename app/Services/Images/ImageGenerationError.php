<?php

namespace App\Services\Images;

/** Lỗi nhà cung cấp ảnh. Thông điệp chỉ gồm mã HTTP/lý do ngắn, đã qua ErrorMasker; không kèm URL hay nội dung phản hồi. */
final class ImageGenerationError extends \RuntimeException {}
