<?php

namespace App\Console\Commands;

use App\Models\AgentRoleAssignment;
use App\Models\AiProvider;
use App\Models\User;
use App\Services\Agent\Ai\AiProviderFactory;
use App\Services\Agent\Roles\RoleAssignments;
use App\Services\Security\UrlSafetyService;
use Illuminate\Console\Command;

/**
 * Cài đặt Claude (qua bộ nối scripts/claude_bridge.py) làm model của đội AI: tạo/cập nhật nhà cung cấp "OpenAI-tương thích" trỏ tới bộ nối và gán model
 * cho các vai trò. Chạy nhiều lần không sinh trùng. Gán vai trò đi qua RoleAssignments::saveModel (kiểm quyền admin, ghi audit); GIỮ NGUYÊN model dự phòng
 * đang có của vai trò (nếu bộ nối chưa chạy hoặc hết hạn mức thì phiên tự chuyển sang dự phòng thật). Khóa không bao giờ được in ra.
 */
class AgentClaudeSetupCommand extends Command
{
    public const PROVIDER_NAME = 'Claude CLI (cầu nối)';

    protected $signature = 'agent:claude-setup
        {--model=sonnet : model Claude (sonnet|opus|haiku hoặc tên đầy đủ)}
        {--roles=writer,strategist : vai trò gán model này, cách nhau bằng dấu phẩy}
        {--base-url=http://172.17.0.1:8771/v1 : địa chỉ bộ nối (phải nằm trong AI_PRIVATE_BASE_URL_ALLOWLIST)}
        {--token-file= : tệp chứa khóa bộ nối (mặc định ~/.config/claude-bridge/token; hoặc đặt biến CLAUDE_BRIDGE_TOKEN)}
        {--test : gọi thử bộ nối một lần trước khi gán}
        {--dry-run : chỉ nói sẽ làm gì, không ghi gì}';

    protected $description = 'Dùng Claude (qua bộ nối Claude CLI) làm model viết/lập dàn ý của đội AI.';

    public function handle(): int
    {
        $model = trim((string) $this->option('model'));
        $roles = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('roles')))));
        $url = rtrim((string) $this->option('base-url'), '/');
        if ($model === '' || ! preg_match('/^[A-Za-z0-9._:\/-]{1,80}$/', $model) || $roles === []) {
            $this->error('Model hoặc danh sách vai trò không hợp lệ.');

            return self::FAILURE;
        }
        try {
            app(UrlSafetyService::class)->validateAiBaseUrl($url);
        } catch (\InvalidArgumentException $e) {
            $this->error('Địa chỉ bộ nối chưa được phép: '.$e->getMessage());
            $this->line('Thêm '.preg_replace('#^https?://#', '', preg_replace('#/v1$#', '', $url)).' vào AI_PRIVATE_BASE_URL_ALLOWLIST trong .env rồi chạy lại.');

            return self::FAILURE;
        }
        $token = $this->token();
        if ($token === null) {
            $this->error('Không đọc được khóa bộ nối. Chạy "python3 scripts/claude_bridge.py" một lần để tạo khóa, hoặc dùng --token-file / CLAUDE_BRIDGE_TOKEN.');

            return self::FAILURE;
        }
        $admin = User::query()->where('role', 'admin')->where('is_active', true)->orderBy('id')->first();
        if ($admin === null) {
            $this->error('Chưa có tài khoản quản trị viên đang hoạt động để ghi nhật ký gán vai trò.');

            return self::FAILURE;
        }
        $this->line("Sẽ dùng model «{$model}» cho vai trò: ".implode(', ', $roles).". Bộ nối: {$url}");
        if ($this->option('dry-run')) {
            $this->info('Chạy thử (dry-run): chưa ghi gì.');

            return self::SUCCESS;
        }

        $p = AiProvider::query()->firstOrNew(['name' => self::PROVIDER_NAME]);
        $p->fill(['kind' => 'openai_compat', 'base_url' => $url, 'api_key' => $token, 'enabled' => true, 'updated_by' => $admin->id])->save();
        if ($this->option('test')) {
            $ping = AiProviderFactory::make($p, $model)->ping();
            $this->line(($ping['ok'] ? 'Bộ nối trả lời: ' : 'Bộ nối KHÔNG trả lời: ').$ping['message']);
            if (! $ping['ok']) {
                $this->warn('Đã lưu nhà cung cấp nhưng CHƯA gán vai trò. Kiểm tra bộ nối đang chạy rồi chạy lại.');

                return self::FAILURE;
            }
        }
        $svc = new RoleAssignments();
        foreach ($roles as $role) {
            $cur = AgentRoleAssignment::query()->where('role_key', $role)->first();
            try {
                $svc->saveModel($role, $p->id, $model, $cur?->fallback_provider_id, $cur?->fallback_model, true, $admin);
            } catch (\InvalidArgumentException $e) {
                $this->error("Vai trò {$role}: ".$e->getMessage());

                return self::FAILURE;
            }
            $fb = $cur?->fallback_model ? " (dự phòng giữ nguyên: {$cur->fallback_model})" : ' (chưa có model dự phòng)';
            $this->info("Vai trò {$role} → {$model}{$fb}");
        }

        return self::SUCCESS;
    }

    private function token(): ?string
    {
        $env = trim((string) getenv('CLAUDE_BRIDGE_TOKEN'));
        if ($env !== '') {
            return $env;
        }
        $file = (string) ($this->option('token-file') ?: (rtrim((string) getenv('HOME'), '/').'/.config/claude-bridge/token'));
        $t = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return strlen($t) >= 32 ? $t : null;
    }
}
