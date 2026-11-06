<?php

namespace App\Integrations\WordPress\Exceptions;

/**
 * Mã lỗi chuẩn của connector WordPress (E11). Thông điệp tiếng Việt, KHÔNG chứa credential/URL có token.
 */
final class WordPressErrorCode
{
    public const ERR01 = 'ERR01'; // timeout/mất kết nối SAU khi đã gửi => mơ hồ, phải đối soát
    public const ERR02 = 'ERR02'; // 401/403
    public const ERR03 = 'ERR03'; // 404 / URL sai / không có REST / redirect
    public const ERR04 = 'ERR04'; // 429
    public const ERR05 = 'ERR05'; // 5xx
    public const ERR06 = 'ERR06'; // payload/phản hồi không hợp lệ
    public const ERR07 = 'ERR07'; // lỗi kết nối TRƯỚC khi gửi => an toàn thử lại

    public const MESSAGES = [
        self::ERR01 => 'Mất kết nối hoặc hết thời gian chờ sau khi đã gửi yêu cầu tới WordPress; cần đối soát theo publication_key.',
        self::ERR02 => 'WordPress từ chối thông tin đăng nhập (401/403): Application Password sai, bị thu hồi hoặc thiếu quyền.',
        self::ERR03 => 'Không tìm thấy WordPress REST API (404/chuyển hướng) hoặc địa chỉ website không hợp lệ/không an toàn.',
        self::ERR04 => 'WordPress đang giới hạn tần suất yêu cầu (429); hãy thử lại sau.',
        self::ERR05 => 'WordPress gặp lỗi máy chủ (5xx).',
        self::ERR06 => 'Dữ liệu gửi đi hoặc phản hồi từ WordPress không hợp lệ.',
        self::ERR07 => 'Không thể kết nối tới WordPress (DNS/từ chối kết nối) trước khi gửi yêu cầu; an toàn để thử lại.',
    ];
}
