<?php

namespace Tests\Feature\Agent;

use App\Filament\Resources\ArticleResource\Pages\ViewArticle;
use App\Filament\Support\Agent\RunFlow;
use App\Services\Agent\Pipeline\JobTypeRegistry;
use Tests\TestCase;

/** G1: danh sách chặng của trang bài và trang phiên lấy từ registry, không còn hằng số viết cứng. */
class RunFlowStagesTest extends TestCase
{
    public function test_trang_bai_hien_du_bay_nhan_dung_thu_tu(): void
    {
        $this->assertSame(
            ['guard' => 'Kiểm điều kiện', 'risk' => 'Xét rủi ro', 'brief' => 'Lập dàn ý', 'write' => 'Viết', 'grade' => 'Chấm điểm', 'repair' => 'Sửa', 'handoff' => 'Bàn giao'],
            ViewArticle::runStages(),
        );
    }

    public function test_trang_bai_va_trang_phien_khop_registry(): void
    {
        $labels = JobTypeRegistry::stageLabels('article_write');
        $this->assertSame($labels, ViewArticle::runStages());
        $this->assertSame(array_keys($labels), RunFlow::main());
    }

    public function test_buoc_planned_khong_ve_nhung_buoc_dang_chay_thi_tu_co(): void
    {
        $this->assertArrayNotHasKey('research', ViewArticle::runStages());
        $steps = JobTypeRegistry::find('article_write')['steps'];
        $active = array_values(array_map(fn ($s) => $s['id'], array_filter($steps, fn ($s) => empty($s['planned']))));
        $this->assertSame($active, RunFlow::main());
    }
}
