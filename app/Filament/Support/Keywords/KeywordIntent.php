<?php

namespace App\Filament\Support\Keywords;

/** Ý định tìm kiếm: 4 giá trị khớp enum bài viết (PlannedArticleCreator). Nhận cả nhãn tiếng Việt khi nhập CSV. */
final class KeywordIntent
{
    public const DEFAULT = 'informational';

    /** @var array<string, string> */
    public const LABELS = [
        'informational' => 'Thông tin',
        'commercial' => 'Thương mại',
        'transactional' => 'Giao dịch',
        'navigational' => 'Điều hướng',
    ];

    public static function label(?string $intent): string
    {
        return self::LABELS[$intent ?? ''] ?? '—';
    }

    /** Chuỗi người nhập -> khóa chuẩn, hoặc null nếu không hợp lệ. */
    public static function normalize(string $raw): ?string
    {
        $v = mb_strtolower(trim($raw));
        if (isset(self::LABELS[$v])) {
            return $v;
        }
        foreach (self::LABELS as $key => $label) {
            if (mb_strtolower($label) === $v) {
                return $key;
            }
        }

        return null;
    }
}
