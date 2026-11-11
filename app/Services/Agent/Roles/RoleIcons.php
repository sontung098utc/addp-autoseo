<?php

namespace App\Services\Agent\Roles;

/** Biểu tượng CỐ ĐỊNH của từng nhân sự (Heroicons outline, đơn sắc cùng nét) + màu nền nhẹ. Không có đường nào để người dùng đổi. */
final class RoleIcons
{
    /** @var array<string, array{icon: string, color: string}> */
    public const MAP = [
        'auditor' => ['icon' => 'heroicon-o-document-magnifying-glass', 'color' => '#2563eb'],
        'competitor' => ['icon' => 'heroicon-o-eye', 'color' => '#7c3aed'],
        'keyword' => ['icon' => 'heroicon-o-magnifying-glass', 'color' => '#0891b2'],
        'strategist' => ['icon' => 'heroicon-o-map', 'color' => '#ea580c'],
        'writer' => ['icon' => 'heroicon-o-pencil-square', 'color' => '#0f766e'],
        'editor' => ['icon' => 'heroicon-o-shield-check', 'color' => '#16a34a'],
        'monitor' => ['icon' => 'heroicon-o-chart-bar', 'color' => '#ca8a04'],
        'manager' => ['icon' => 'heroicon-o-briefcase', 'color' => '#be185d'],
    ];

    /** @return array{icon: string, color: string} */
    public static function for(string $role): array
    {
        return self::MAP[$role] ?? ['icon' => 'heroicon-o-user', 'color' => '#64748b'];
    }
}
