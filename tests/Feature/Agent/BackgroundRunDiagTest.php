<?php

namespace Tests\Feature\Agent;

use App\Models\Article;
use App\Models\Website;
use App\Services\Agent\Run\BackgroundRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** Khởi chạy nền thất bại phải để lại LÝ DO trong nhật ký (trước đây im lặng trả null nên không ai biết vì sao bài kẹt "Đang viết"). */
class BackgroundRunDiagTest extends TestCase
{
    use RefreshDatabase;

    private string $log;

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = storage_path('logs/agent-run-article.log');
        @unlink($this->log);
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
        parent::tearDown();
    }

    private function article(): Article
    {
        $site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);

        return Article::create(['website_id' => $site->id, 'primary_keyword' => 'đo đường huyết', 'workflow_status' => 'planned']);
    }

    public function test_dung_duong_dan_php_chi_dinh_va_ghi_ly_do_khi_chua_thay_phien(): void
    {
        config(['agent.php_binary' => '/khong/co/php-nao']);
        Log::spy();
        $this->assertNull(BackgroundRun::start($this->article(), null, 0));
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx) => str_contains((string) $msg, 'chưa thấy phiên') && ($ctx['php'] ?? '') === '/khong/co/php-nao')->once();
        $this->assertTrue(is_file($this->log), 'Lệnh nền đã được shell chạy nên tệp log phải tồn tại.');
        $this->assertStringContainsString('/khong/co/php-nao', (string) file_get_contents($this->log), 'Lỗi của chính lệnh nằm trong log để ghi vào nhật ký.');
    }

    public function test_de_trong_thi_tu_do_duong_dan_nhu_cu(): void
    {
        config(['agent.php_binary' => null]);
        Log::spy();
        BackgroundRun::start($this->article(), null, 0);
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx) => ! empty($ctx['php']) && $ctx['php'] !== '/khong/co/php-nao')->once();
    }
}
