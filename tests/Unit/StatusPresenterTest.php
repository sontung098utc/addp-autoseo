<?php

namespace Tests\Unit;

use App\Filament\Support\StatusPresenter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StatusPresenterTest extends TestCase
{
    public static function bangTrangThai(): array
    {
        return [
            ['article', 'planned', 'Đã lên kế hoạch', 'gray'],
            ['article', 'drafting', 'Đang viết (AI)', 'info'],
            ['article', 'needs_review', 'Chờ duyệt', 'info'],
            ['article', 'rejected', 'Cần sửa', 'warning'],
            ['article', 'approved', 'Đã duyệt', 'success'],
            ['article', 'scheduled', 'Đã xếp lịch', 'info'],
            ['article', 'publishing', 'Đang đăng', 'warning'],
            ['article', 'published', 'Đã đăng', 'success'],
            ['article', 'failed', 'Lỗi', 'danger'],
            ['article', 'needs_reconcile', 'Chờ đối soát', 'danger'],
            ['article', 'held', 'Tạm giữ', 'gray'],
            ['publication', 'published', 'Đã đăng', 'success'],
            ['publication', 'needs_reconcile', 'Chờ đối soát', 'danger'],
            ['website', 'active', 'Hoạt động', 'success'],
            ['website', 'pending', 'Chờ kiểm tra', 'gray'],
            ['website', 'revoked', 'Đã thu hồi', 'gray'],
            ['website', 'error', 'Lỗi kết nối', 'warning'],
        ];
    }

    #[DataProvider('bangTrangThai')]
    public function test_map_nhan_mau_icon(string $domain, string $value, string $label, string $color): void
    {
        $this->assertSame($label, StatusPresenter::label($value, $domain));
        $this->assertSame($color, StatusPresenter::color($value, $domain));
        $this->assertStringStartsWith('heroicon-', StatusPresenter::icon($value, $domain));
    }

    public function test_gia_tri_la_khong_nem_loi(): void
    {
        $this->assertSame('xyz_la', StatusPresenter::label('xyz_la'));
        $this->assertSame('gray', StatusPresenter::color('xyz_la'));
        $this->assertStringStartsWith('heroicon-', StatusPresenter::icon('xyz_la'));
        $this->assertSame('active', StatusPresenter::label('active', 'article'));
        $this->assertSame('gray', StatusPresenter::color('x', 'domain_la'));
    }

    public function test_null_va_rong(): void
    {
        $this->assertSame('—', StatusPresenter::label(null));
        $this->assertSame('gray', StatusPresenter::color(''));
    }
}
