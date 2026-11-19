<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Chốt chặn an toàn của cầu nối Claude (scripts/claude_bridge.py): kiểm tra tĩnh, không chạy Claude, không dùng CSDL.
 *
 * Lý do: `--tools ""` KHÔNG chặn connector (MCP) gắn với tài khoản claude.ai (ví dụ Claude Docs: tạo/xóa/xuất tài liệu).
 * Mô hình vẫn thấy và phát lời gọi công cụ; chỉ hệ thống xin quyền chặn lại. `--strict-mcp-config` cùng cấu hình MCP rỗng
 * mới gỡ hẳn chúng (đã kiểm chứng với tài khoản runner thật: 0 công cụ). Xem docs của dự án ai-gateway, mục "Phát hiện bảo mật".
 */
class ClaudeBridgeHardeningTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        parent::setUp();
        $this->src = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/claude_bridge.py');
        $this->assertNotSame('', $this->src, 'Không đọc được scripts/claude_bridge.py');
    }

    public function test_hang_so_go_connector_dung_gia_tri(): void
    {
        $this->assertMatchesRegularExpression('/^NO_MCP = \["--strict-mcp-config", "--mcp-config", \'\{"mcpServers":\{\}\}\'\]$/m', $this->src);
    }

    public function test_moi_lan_goi_claude_deu_gan_no_mcp(): void
    {
        // Mỗi danh sách đối số bắt đầu bằng `args = [CLAUDE, "-p", ...` phải kết thúc bằng `+ NO_MCP`.
        preg_match_all('/args = \[CLAUDE, "-p".*?\]( \+ NO_MCP)?\n/s', $this->src, $m, PREG_SET_ORDER);
        $this->assertGreaterThanOrEqual(2, count($m), 'Phải có ít nhất 2 chỗ gọi Claude (chat và tìm web).');
        foreach ($m as $i => $call) {
            $this->assertArrayHasKey(1, $call, 'Lần gọi Claude thứ '.($i + 1).' thiếu `+ NO_MCP`: connector của tài khoản sẽ lọt vào.');
        }
        $this->assertSame(substr_count($this->src, 'args = [CLAUDE, "-p"'), substr_count($this->src, '+ NO_MCP'));
    }

    public function test_khong_co_co_nguy_hiem(): void
    {
        foreach (['--dangerously-skip-permissions', '--permission-mode', 'bypassPermissions', '--allow-dangerously-skip-permissions'] as $bad) {
            $this->assertStringNotContainsString($bad, $this->src, "Cờ bỏ qua quyền bị cấm: {$bad}");
        }
        // Tìm web chỉ được phép hai công cụ chỉ-đọc, không thêm công cụ khác.
        $this->assertStringContainsString('"--allowedTools", "WebSearch,WebFetch"', $this->src);
    }
}
