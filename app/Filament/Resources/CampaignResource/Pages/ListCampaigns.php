<?php

namespace App\Filament\Resources\CampaignResource\Pages;

use App\Filament\Resources\CampaignResource;
use App\Models\CampaignWait;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

class ListCampaigns extends ListRecords
{
    protected static string $resource = CampaignResource::class;

    protected static ?string $title = 'Chiến dịch của đội AI';

    public const CONCEPT = 'Chiến dịch gom các bài cùng mục tiêu, lịch và quy tắc; bài lẻ là bài không thuộc chiến dịch nào. Chiến dịch = mục tiêu sản xuất: website nào, lấy từ khóa từ đâu, chế độ, hạn mức mỗi ngày. Mỗi nhịp hệ thống chọn bài kế tiếp và tạo MỘT phiên chạy cho bài đó. Chiến dịch không phải chuỗi phiên. Kết quả luôn là bản nháp chờ duyệt, chưa có gì tự đăng.';

    public function getHeading(): string|Htmlable
    {
        return new HtmlString(e(static::$title).' '.Blade::render('<x-help :text="$t" />', ['t' => self::CONCEPT]));
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Tạo chiến dịch')];
    }

    public function getFooter(): ?View
    {
        return view('filament.agent.campaign-panels', [
            'waits' => CampaignResource::openWaits(),
            'expired' => CampaignResource::recentExpired(),
            'budget' => CampaignResource::budget(),
        ]);
    }

    /** Bỏ qua một mục chờ bổ sung (chỉ người duyệt/admin). */
    public function dismissWait(int $id): void
    {
        abort_unless(CampaignResource::canViewAny(), 403);
        CampaignWait::query()->where('status', 'open')->whereKey($id)->update(['status' => 'dismissed']);
    }
}
