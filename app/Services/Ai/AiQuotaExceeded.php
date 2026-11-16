<?php

namespace App\Services\Ai;

/**
 * 429 do hết hạn mức THEO NGÀY (không phải theo phút): chờ vài giây không giúp ích nên không thử lại, báo rõ để người dùng đổi model,
 * chờ sang ngày mới (hạn mức thường đặt lại lúc nửa đêm giờ Thái Bình Dương) hoặc bật thanh toán.
 */
class AiQuotaExceeded extends AiProviderError
{
}
