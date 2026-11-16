<?php

namespace App\Services\Knowledge;

use App\Filament\Pages\BrainBuildPage;

/**
 * Các LOẠI Trí tuệ khi bấm "Tạo trí tuệ". Thêm loại mới = thêm một dòng vào all(); mỗi loại có khóa, tên, mô tả một dòng,
 * biểu tượng và nơi cấu hình riêng: 'form' = biểu mẫu ngay trong hộp thoại, 'flow' = luồng riêng (trang con, không phải tab cụm).
 */
final class BrainKinds
{
    public const DOMAIN = 'domain';

    public const ENTERPRISE = 'enterprise';

    /**
     * @return array<string,array{key:string,name:string,description:string,icon:string,mode:string,flow:?string}>
     */
    public static function all(): array
    {
        return [
            self::DOMAIN => [
                'key' => self::DOMAIN, 'name' => 'Trí tuệ theo lĩnh vực', 'icon' => 'heroicon-o-academic-cap', 'mode' => 'form', 'flow' => null,
                'description' => 'Tạo từ một mẫu lĩnh vực (Sức khỏe, Dược, Giáo dục…) với giọng văn, thuật ngữ và quy tắc lập luận riêng.',
            ],
            self::ENTERPRISE => [
                'key' => self::ENTERPRISE, 'name' => 'Trí tuệ doanh nghiệp', 'icon' => 'heroicon-o-building-office-2', 'mode' => 'flow', 'flow' => BrainBuildPage::class,
                'description' => 'Xây kho từ website công ty: đọc trang, khám phá nguồn, điền hồ sơ doanh nghiệp, rồi duyệt.',
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** Địa chỉ luồng tạo của loại 'flow'; null nếu không có hoặc người dùng không đủ quyền vào luồng đó. */
    public static function flowUrl(string $key): ?string
    {
        $k = self::get($key);
        $page = $k['flow'] ?? null;

        return $page && $page::canAccess() ? $page::getUrl() : null;
    }
}
