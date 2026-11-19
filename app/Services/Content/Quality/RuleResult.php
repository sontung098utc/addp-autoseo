<?php

namespace App\Services\Content\Quality;

/** Kết quả một mục kiểm tra. Một luật có thể trả nhiều mục (cùng mã, khác nhãn). */
final class RuleResult
{
    public const P0 = 'P0';
    public const P1 = 'P1';
    public const P2 = 'P2';

    public function __construct(
        public readonly string $code,
        public readonly string $severity,
        public readonly bool $passed,
        public readonly string $label,
        public readonly string $message,
        public readonly ?string $measured = null,
        public readonly ?string $threshold = null,
        public readonly ?string $excerpt = null,
        /** Chi tiết có cấu trúc cho vòng sửa (ví dụ các đoạn chưa trích dẫn); luật nào không cần thì để trống. @var list<string> */
        public readonly array $details = [],
        /** Số liệu có cấu trúc cho máy đọc (ví dụ độ lệch so với chỉ dẫn); không hiển thị trực tiếp. @var array<string,mixed> */
        public readonly array $data = [],
    ) {
    }

    public function isBlocking(): bool
    {
        return ! $this->passed && $this->severity === self::P0;
    }
}
