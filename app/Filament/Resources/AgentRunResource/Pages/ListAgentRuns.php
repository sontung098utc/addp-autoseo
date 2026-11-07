<?php

namespace App\Filament\Resources\AgentRunResource\Pages;

use App\Filament\Resources\AgentRunResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

class ListAgentRuns extends ListRecords
{
    protected static string $resource = AgentRunResource::class;

    protected static ?string $title = 'Phiên chạy của đội AI';

    public function getHeading(): string|Htmlable
    {
        return new HtmlString(e(static::$title).' '.Blade::render('<x-studio.hint :text="$t" />', ['t' => 'Phiên chạy = một lần đội AI xử lý MỘT bài (do một chiến dịch giao): kiểm an toàn → phân hạng → dàn ý → viết → chấm → sửa → bàn giao bản nháp. Chưa có gì được tự đăng.']));
    }
}
