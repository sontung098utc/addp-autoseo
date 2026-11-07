<?php

namespace App\Filament\Clusters\AgentTeam\Pages;

use App\Filament\Clusters\AgentTeam;
use App\Models\User;
use App\Services\Agent\Trust\RoleMatrix;
use App\Services\Agent\Trust\TrustGate;
use App\Services\Agent\Trust\TrustMetrics;
use Filament\Pages\Page;
use Filament\Pages\SubNavigationPosition;
use Illuminate\Support\Facades\Auth;

/**
 * AG-S12: tab "Chính sách" của Đội AI. CHỈ ĐỌC: không có nút nâng bậc nào. Mức 2 bị khóa cứng (TrustGate)
 * tới khi có văn bản chính sách của người dùng và connector hỗ trợ gỡ bài.
 */
class TrustPolicy extends Page
{
    protected static ?string $cluster = AgentTeam::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Chính sách';

    protected static ?string $title = 'Chính sách tin cậy';

    protected static ?string $slug = 'chinh-sach';

    protected static ?int $navigationSort = 8;

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    protected static string $view = 'filament.clusters.agent-team.trust-policy';

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return $u instanceof User && $u->is_active && $u->isApprover();
    }

    protected function getViewData(): array
    {
        $metrics = TrustMetrics::compute(now()->subDays(30));
        $topics = require resource_path('playbooks/risk/topics.php');

        return [
            'metrics' => $metrics,
            'unmeasured' => TrustMetrics::unmeasured(),
            'gate' => TrustGate::evaluate($metrics),
            'topics' => $topics['topics'],
            'topicsReviewed' => $topics['reviewed_at'] ?? null,
            'roles' => RoleMatrix::ROLES,
            'matrix' => RoleMatrix::rows(),
            'minSample' => TrustGate::MIN_SAMPLE,
            'tiers' => [
                ['t' => 'T0', 'name' => 'Chỉ lưu nội bộ', 'does' => 'Không đẩy gì ra website.', 'up' => 'Bậc thấp nhất; có thể hạ về bất cứ lúc nào.', 'state' => 'Có thể hạ về'],
                ['t' => 'T1', 'name' => 'Đẩy nháp lên website', 'does' => 'AI viết, tự sửa rồi chỉ tạo bản nháp (đẩy nháp CMS ở chế độ Cân bằng). Người duyệt đăng.', 'up' => 'Mặc định của mọi website.', 'state' => 'Đang áp dụng'],
                ['t' => 'T2', 'name' => 'Tự đăng bài an toàn', 'does' => 'Tự đăng chủ đề hạng thấp, kiểm mẫu sau đăng, tự gỡ khi lỗi.', 'up' => 'Cần mọi điều kiện bên dưới, gồm văn bản chính sách của bạn và connector gỡ bài.', 'state' => 'Bị khóa'],
                ['t' => 'T3', 'name' => 'Mở rộng', 'does' => 'Thêm nhóm chủ đề và nhịp đăng dựa trên Search Console.', 'up' => 'Chỉ xét sau khi T2 chạy ổn; Search Console chưa kết nối.', 'state' => 'Ý tưởng'],
            ],
        ];
    }
}
