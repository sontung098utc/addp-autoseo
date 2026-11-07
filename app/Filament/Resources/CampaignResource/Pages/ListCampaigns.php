<?php

namespace App\Filament\Resources\CampaignResource\Pages;

use App\Filament\Resources\CampaignResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\View\View;

class ListCampaigns extends ListRecords
{
    protected static string $resource = CampaignResource::class;

    protected static ?string $title = 'Chiến dịch của đội AI';

    public function getSubheading(): ?string
    {
        return 'Đặt từ khóa và giới hạn bài mỗi ngày; hệ thống tự giao từng bài cho đội AI. Kết quả luôn là bản nháp chờ người duyệt, chưa có gì tự đăng.';
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
        \App\Models\CampaignWait::query()->where('status', 'open')->whereKey($id)->update(['status' => 'dismissed']);
    }
}
