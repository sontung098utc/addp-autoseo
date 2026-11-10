<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ArticleResource;
use App\Filament\Resources\ArticleResource\Pages\ListArticles;
use App\Filament\Resources\ArticleVersionResource;
use App\Filament\Resources\MediaAssetResource;
use App\Filament\Resources\MediaAssetResource\Pages\ListMediaAssets;
use App\Filament\Resources\PublicationResource;
use App\Filament\Resources\PublicationResource\Pages\ListPublications;
use App\Filament\Resources\WebsiteResource;
use App\Filament\Resources\WebsiteResource\Pages\ListWebsites;
use App\Filament\Widgets\DecisionsWidget;
use App\Filament\Widgets\NextPublicationsWidget;
use App\Filament\Widgets\PublicationProblemsWidget;
use App\Filament\Widgets\RecentActivityWidget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AG-H05: tiêu đề trang danh sách phải khớp tên mục menu, và các widget Tổng quan không được
 * tải lười (khung trống lúc đầu) hay vẽ khung rỗng cho người không đủ quyền.
 */
class PageTitlesAndWidgetsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid() . '@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    public function test_tieu_de_trang_danh_sach_khop_ten_menu(): void
    {
        $this->actingAs($this->user('admin'));

        $cases = [
            [WebsiteResource::class, ListWebsites::class],
            [ArticleResource::class, ListArticles::class],
            [PublicationResource::class, ListPublications::class],
            [MediaAssetResource::class, ListMediaAssets::class],
        ];

        foreach ($cases as [$resource, $page]) {
            $this->assertSame(
                $resource::getNavigationLabel(),
                (string) app($page)->getTitle(),
                "Tiêu đề {$page} phải khớp tên menu"
            );
        }
    }

    public function test_widget_tong_quan_khong_tai_luoi(): void
    {
        foreach ([DecisionsWidget::class, PublicationProblemsWidget::class, NextPublicationsWidget::class, RecentActivityWidget::class] as $w) {
            $this->assertFalse($w::isLazy(), "{$w} không được tải lười (tránh khung trống lúc đầu)");
        }
    }

    public function test_widget_loi_doi_soat_chi_hien_tu_approver_tro_len(): void
    {
        $this->actingAs($this->user('writer'));
        $this->assertFalse(PublicationProblemsWidget::canView());

        $this->actingAs($this->user('approver'));
        $this->assertTrue(PublicationProblemsWidget::canView());

        $this->actingAs($this->user('admin'));
        $this->assertTrue(PublicationProblemsWidget::canView());
    }
}
