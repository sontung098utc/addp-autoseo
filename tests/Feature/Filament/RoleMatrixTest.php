<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages as P;
use App\Filament\Resources as R;
use App\Models\User;
use Filament\Resources\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AG-U26: ma trận quyền theo vai trò (admin / approver / writer) — TEST ĐẶC TẢ hành vi hiện tại.
 * Khóa lại để các task giao diện sau không âm thầm mở/đóng quyền. Mỗi ô: có thể xem (Y) / tạo (C) / xóa (D).
 *
 * Hai điểm KHÔNG phải quyết định thiết kế mà là hành vi hiện tại cần chủ dự án quyết (xem PR):
 *  - MediaAssetResource không có Policy: biên tập viên cũng tạo/xóa được ảnh thư viện công ty.
 *  - UiKitPage (trang kỹ thuật) hiện mọi vai trò đều thấy.
 */
class RoleMatrixTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{class: class-string, admin: string, approver: string, writer: string}> */
    private static function matrix(): array
    {
        return [
            'Website kết nối' => ['class' => R\WebsiteResource::class, 'admin' => 'YC', 'approver' => '-', 'writer' => '-'],
            'Bài viết' => ['class' => R\ArticleResource::class, 'admin' => 'YCD', 'approver' => 'YC', 'writer' => 'YC'],
            'Duyệt bài (phiên bản)' => ['class' => R\ArticleVersionResource::class, 'admin' => 'Y', 'approver' => 'Y', 'writer' => 'Y'],
            'Lịch xuất bản' => ['class' => R\PublicationResource::class, 'admin' => 'Y', 'approver' => 'Y', 'writer' => '-'],
            'Thư viện ảnh (CHƯA có Policy)' => ['class' => R\MediaAssetResource::class, 'admin' => 'YCD', 'approver' => 'YCD', 'writer' => 'YCD'],
            'Tổng quan' => ['class' => P\Dashboard::class, 'admin' => 'Y', 'approver' => 'Y', 'writer' => 'Y'],
            'Audit website' => ['class' => P\AuditPage::class, 'admin' => 'Y', 'approver' => 'Y', 'writer' => 'Y'],
            'Chi phí AI' => ['class' => P\AiUsagePage::class, 'admin' => 'Y', 'approver' => 'Y', 'writer' => '-'],
            'Từ khóa' => ['class' => P\KeywordsPage::class, 'admin' => 'Y', 'approver' => 'Y', 'writer' => 'Y'],
            'Kế hoạch nội dung' => ['class' => P\ContentPlanPage::class, 'admin' => 'Y', 'approver' => 'Y', 'writer' => 'Y'],
            'Đối thủ' => ['class' => P\CompetitorsPage::class, 'admin' => 'Y', 'approver' => 'Y', 'writer' => '-'],
            'Search Console' => ['class' => P\SearchConsolePage::class, 'admin' => 'Y', 'approver' => 'Y', 'writer' => '-'],
            'Lịch tuần' => ['class' => P\WeeklySchedule::class, 'admin' => 'Y', 'approver' => 'Y', 'writer' => '-'],
            'Thành phần UI (trang kỹ thuật)' => ['class' => P\UiKitPage::class, 'admin' => 'Y', 'approver' => 'Y', 'writer' => 'Y'],
        ];
    }

    private function actual(string $class): string
    {
        if (is_subclass_of($class, Resource::class)) {
            $v = $class::canViewAny();

            return ($v ? 'Y' : '-').($class::canCreate() ? 'C' : '').($v && $class::canDeleteAny() ? 'D' : '');
        }

        return $class::canAccess() ? 'Y' : '-';
    }

    private function as(string $role, bool $active = true): User
    {
        $u = User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => $active]);
        $this->actingAs($u);

        return $u;
    }

    public function test_ma_tran_quyen_theo_vai_tro_khong_bi_doi_lang_le(): void
    {
        foreach (['admin', 'approver', 'writer'] as $role) {
            $this->as($role);
            foreach (self::matrix() as $name => $row) {
                $this->assertSame($row[$role], $this->actual($row['class']), "Quyền của {$role} với '{$name}' đã thay đổi");
            }
        }
    }

    public function test_quyen_tang_dan_theo_vai_tro_cho_man_nghiep_vu(): void
    {
        // Mọi thứ writer xem được thì approver và admin cũng xem được (không có quyền "ngược").
        foreach (self::matrix() as $name => $row) {
            if (str_contains($row['writer'], 'Y')) {
                $this->assertStringContainsString('Y', $row['approver'], $name);
                $this->assertStringContainsString('Y', $row['admin'], $name);
            }
            if (str_contains($row['approver'], 'Y')) {
                $this->assertStringContainsString('Y', $row['admin'], $name);
            }
        }
    }

    public function test_website_chi_admin_va_lich_xuat_ban_khong_cho_bien_tap_vien(): void
    {
        $this->as('approver');
        $this->assertFalse(R\WebsiteResource::canViewAny());
        $this->as('writer');
        $this->assertFalse(R\PublicationResource::canViewAny());
        $this->assertFalse(R\WebsiteResource::canCreate());
    }

    public function test_chi_admin_xoa_duoc_bai_viet(): void
    {
        foreach (['approver', 'writer'] as $role) {
            $this->as($role);
            $this->assertFalse(R\ArticleResource::canDeleteAny(), $role);
        }
        $this->as('admin');
        $this->assertTrue(R\ArticleResource::canDeleteAny());
    }

    public function test_nguoi_dung_bi_khoa_khong_vao_duoc_man_quan_tri(): void
    {
        $this->as('approver', false);

        $this->assertFalse(P\AiUsagePage::canAccess());
        $this->assertFalse(P\AuditPage::canAccess());
    }
}
