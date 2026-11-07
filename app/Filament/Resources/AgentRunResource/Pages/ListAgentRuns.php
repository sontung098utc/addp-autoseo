<?php

namespace App\Filament\Resources\AgentRunResource\Pages;

use App\Filament\Resources\AgentRunResource;
use Filament\Resources\Pages\ListRecords;

class ListAgentRuns extends ListRecords
{
    protected static string $resource = AgentRunResource::class;

    protected static ?string $title = 'Phiên chạy của đội AI';

    public function getSubheading(): ?string
    {
        return 'Mỗi dòng là một lần đội AI nhận một bài: kiểm an toàn → phân hạng → dàn ý → viết → chấm → sửa → bàn giao bản nháp. Chưa có gì được tự đăng.';
    }
}
