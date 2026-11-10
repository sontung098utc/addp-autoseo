<?php

namespace App\Filament\Pages;

use App\Filament\Support\Agent\FeatureRegistry;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Illuminate\Support\Facades\Auth;
use Throwable;

/** Trang "Cách hoạt động" (AG-U02): 9 giai đoạn + mức sẵn sàng THẬT của từng tính năng, đọc từ FeatureRegistry. */
class HowItWorksPage extends Page
{
    /** Giai đoạn => id tính năng liên quan. */
    public const STAGES = [
        ['n' => 1, 'title' => 'Hiểu website', 'what' => 'Kết nối website và quét sitemap để biết hiện trạng.', 'features' => ['website.connect', 'audit.view']],
        ['n' => 2, 'title' => 'Audit', 'what' => 'Tìm lỗi kỹ thuật và on-page, xếp theo mức ưu tiên.', 'features' => ['audit.view', 'audit.rescan']],
        ['n' => 3, 'title' => 'Từ khóa', 'what' => 'Nhập từ khóa, xác định ý định, gom cụm.', 'features' => ['keywords']],
        ['n' => 4, 'title' => 'Đối thủ', 'what' => 'Tìm khoảng trống nội dung so với đối thủ.', 'features' => ['competitors']],
        ['n' => 5, 'title' => 'Kế hoạch', 'what' => 'Biến cụm thành danh sách bài và lịch viết.', 'features' => ['plan']],
        ['n' => 6, 'title' => 'Viết bài', 'what' => 'AI viết bản nháp và hệ thống chấm chất lượng.', 'features' => ['content.generate', 'content.quality']],
        ['n' => 7, 'title' => 'Duyệt', 'what' => 'Người duyệt xem, duyệt hoặc từ chối có lý do.', 'features' => ['review.flow']],
        ['n' => 8, 'title' => 'Xuất bản', 'what' => 'Xếp lịch, đăng lên CMS, đối soát, dừng khẩn cấp.', 'features' => ['publish.schedule', 'publish.stop']],
        ['n' => 9, 'title' => 'Đo lường', 'what' => 'Theo dõi kết quả SEO và chi phí AI.', 'features' => ['gsc', 'ai.usage']],
    ];

    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationLabel = 'Cách hoạt động';

    protected static ?string $navigationGroup = 'Cài đặt';

    protected static ?int $navigationSort = 40;

    protected static ?string $title = 'Cách hoạt động của SEO Studio';

    protected static ?string $slug = 'cach-hoat-dong';

    protected static string $view = 'filament.pages.how-it-works';

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return $u && $u->is_active;
    }

    protected function getViewData(): array
    {
        $stages = array_map(function (array $s): array {
            $s['items'] = array_values(array_filter(array_map(fn (string $id) => FeatureRegistry::find($id), $s['features'])));
            $s['url'] = null;

            return $s;
        }, self::STAGES);

        return [
            'stages' => $stages,
            'flow' => \App\Filament\Support\Agent\AgentFlowBlueprint::stages(),
            'automation' => array_values(array_filter(array_map(fn (string $id) => FeatureRegistry::find($id), ['ai.router', 'ai.selfrepair', 'pipeline.auto']))),
            'counts' => FeatureRegistry::counts(),
        ];
    }

    /** URL của trang/resource tương ứng; null nếu không xác định (không để trang hỏng vì một liên kết). */
    public static function urlFor(?string $route): ?string
    {
        if ($route === null || ! class_exists($route)) {
            return null;
        }
        try {
            return is_subclass_of($route, Resource::class) ? $route::getUrl('index') : $route::getUrl();
        } catch (Throwable) {
            return null;
        }
    }
}
