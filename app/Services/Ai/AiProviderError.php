<?php

namespace App\Services\Ai;

use RuntimeException;

/** Lỗi CỨNG từ nhà cung cấp AI (khóa bị từ chối, yêu cầu sai…): thử lại không giúp ích. Thông điệp đã được che, không chứa khóa. */
class AiProviderError extends RuntimeException
{
}
