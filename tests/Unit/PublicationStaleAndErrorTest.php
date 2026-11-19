<?php

namespace Tests\Unit;

use App\Filament\Support\Dashboard\DashboardQueries;
use App\Filament\Support\Schedule\ErrorExplainer;
use App\Services\Publishing\PublicationStaleReaper;
use Tests\TestCase;

class PublicationStaleAndErrorTest extends TestCase
{
    public function test_explainer_names_step_and_does_not_hide_path(): void
    {
        $e = '[ERR06] Dữ liệu gửi đi hoặc phản hồi từ CMS không hợp lệ. (HTTP 400: GET /rest/default/V1/company-blog/posts/by-key/pub-1-a2-v1)';
        $out = ErrorExplainer::explain($e);
        $this->assertStringContainsString('tra cứu bài đã có trên CMS', $out);
        $this->assertStringContainsString('Bị chặn tích hợp', $out);
        $this->assertStringContainsString('by-key/pub-1-a2-v1', $out);
        $this->assertStringNotContainsString('/***', DashboardQueries::safeError($e));
    }

    public function test_explainer_masks_secrets(): void
    {
        $out = ErrorExplainer::explain('HTTP 401: GET /x token=abcSECRET123 Bearer abcdef123456');
        $this->assertStringNotContainsString('abcSECRET123', $out);
        $this->assertStringNotContainsString('abcdef123456', $out);
    }
}
