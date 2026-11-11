<?php

namespace App\Contracts;

/**
 * Mã lỗi chuẩn dùng chung giữa các CMS connector (WordPress, Magento 2) và tầng Job/UI (PR-D).
 * Thông điệp tiếng Việt rõ ràng, KHÔNG chứa credential/URL có token.
 */
final class CmsErrorCode
{
    public const ERR01 = 'ERR01'; // Timeout / đứt kết nối SAU khi đã gửi yêu cầu ghi => mơ hồ, phải đối soát
    public const ERR02 = 'ERR02'; // 401/403 xác thực hoặc phân quyền
    public const ERR03 = 'ERR03'; // 404 / sai URL / không có REST API / redirect / chặn SSRF
    public const ERR04 = 'ERR04'; // 429 quá giới hạn tần suất (có thể thử lại)
    public const ERR05 = 'ERR05'; // 5xx lỗi máy chủ CMS
    public const ERR06 = 'ERR06'; // Dữ liệu gửi đi hoặc phản hồi không hợp lệ
    public const ERR07 = 'ERR07'; // Lỗi kết nối TRƯỚC khi gửi yêu cầu (DNS/từ chối kết nối) => an toàn thử lại

    public const MESSAGES = [
        self::ERR01 => 'Mất kết nối hoặc hết thời gian chờ sau khi đã gửi yêu cầu tới CMS; cần đối soát theo publication_key.',
        self::ERR02 => 'CMS từ chối thông tin xác thực (401/403): token/password sai, bị thu hồi hoặc thiếu quyền.',
        self::ERR03 => 'Không tìm thấy endpoint CMS (404/chuyển hướng) hoặc địa chỉ website không hợp lệ/không an toàn.',
        self::ERR04 => 'CMS đang giới hạn tần suất yêu cầu (429); hãy thử lại sau.',
        self::ERR05 => 'CMS gặp lỗi máy chủ (5xx).',
        self::ERR06 => 'Dữ liệu gửi đi hoặc phản hồi từ CMS không hợp lệ.',
        self::ERR07 => 'Không thể kết nối tới CMS (DNS/từ chối kết nối) trước khi gửi yêu cầu; an toàn để thử lại.',
    ];

    public const CATEGORIES = [
        self::ERR01 => 'network',
        self::ERR02 => 'auth',
        self::ERR03 => 'not_found',
        self::ERR04 => 'rate_limit',
        self::ERR05 => 'server',
        self::ERR06 => 'payload',
        self::ERR07 => 'network',
    ];

    public static function messageFor(string $code): string
    {
        return self::MESSAGES[$code] ?? 'Lỗi kết nối hoặc xử lý từ CMS.';
    }

    public static function categoryFor(string $code): string
    {
        return self::CATEGORIES[$code] ?? 'unknown';
    }
}
