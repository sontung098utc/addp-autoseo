<?php

namespace App\Services\Ai;

/** Phản hồi AI không đúng cấu trúc JSON yêu cầu. Không bịa dữ liệu thay thế; bộ định tuyến có thể chuyển model dự phòng. */
class AiSchemaError extends AiProviderError
{
}
