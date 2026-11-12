<?php

namespace App\Services\Agent\Settings;

use App\Contracts\AiProviderInterface;
use App\Models\AgentSetting;
use App\Services\Agent\Run\RunPolicy;
use App\Services\Agent\Ai\AiModelRouter;
use App\Services\Agent\Ai\RoutedAiProvider;
use App\Services\Ai\DemoScriptedAiProvider;
use App\Services\Ai\GeminiAiProvider;

/**
 * AG-S01d: MỘT chỗ duy nhất trả lời "đội AI đang dùng AI nào?". Khóa lấy từ cấu hình trong hệ thống (mã hóa),
 * nếu chưa có thì mới dùng biến môi trường GOOGLE_AI_API_KEY. Không bao giờ trả khóa ra giao diện.
 * AG-S11: khi đã có định tuyến (bảng ai_model_routes) thì mọi đường chạy đi qua AiModelRouter (nhiều provider, model theo loại việc,
 * dự phòng thật); thiếu khóa thì không chạy. Chưa có route nào thì giữ đường cũ: một Gemini từ agent_settings hoặc biến môi trường.
 * Bản đề xuất cho Dev1 rà (AG-B06).
 */
final class AgentAi
{
    public const DEFAULT_MODEL = 'gemini-3.6-flash';

    private static function key(): ?array
    {
        $s = AgentSetting::current();
        if (! empty($s->api_key)) {
            return [$s->api_key, 'db'];
        }
        $env = env('GOOGLE_AI_API_KEY');

        return ! empty($env) ? [$env, 'env'] : null;
    }

    /** @return array{connected: bool, source: string, model: string, key_hint: string, tested: ?bool, tested_at: ?string, test_message: ?string} */
    public static function status(): array
    {
        if (AiModelRouter::isConfigured()) {
            $router = new AiModelRouter();
            $write = $router->target('write');
            $p = \App\Models\AiProvider::query()->whereKey($write?->providerId)->first();

            return [
                'connected' => $router->missing(['outline', 'write']) === [], 'source' => 'router', 'model' => $write?->model ?? '', 'key_hint' => '',
                'tested' => $p?->last_test_ok, 'tested_at' => $p?->last_test_at?->toIso8601String(), 'test_message' => $p?->last_test_message,
            ];
        }
        $s = AgentSetting::current();
        $k = self::key();

        return [
            'connected' => $k !== null,
            'source' => $k[1] ?? 'none',
            'model' => $s->model ?: self::DEFAULT_MODEL,
            'key_hint' => $k ? '••••'.substr($k[0], -4) : '',
            'tested' => $s->last_test_ok,
            'tested_at' => $s->last_test_at?->toIso8601String(),
            'test_message' => $s->last_test_message,
        ];
    }

    /** AI thật nếu đã kết nối; null nếu chưa. */
    public static function real(): ?AiProviderInterface
    {
        if (AiModelRouter::isConfigured()) {
            $router = new AiModelRouter();

            return $router->missing(['outline', 'write']) === [] ? new RoutedAiProvider($router) : null;
        }
        $k = self::key();

        return $k ? new GeminiAiProvider($k[0], AgentSetting::current()->model ?: self::DEFAULT_MODEL) : null;
    }

    /** AI thật; riêng môi trường local được phép dùng bản giả lập theo kịch bản (luôn có nhãn "AI giả lập"). */
    public static function forInteractiveUse(): ?AiProviderInterface
    {
        return self::real() ?? (app()->environment('local') ? new DemoScriptedAiProvider() : null);
    }

    public static function policy(): RunPolicy
    {
        $s = AgentSetting::current();

        return new RunPolicy(maxRepairRounds: (int) $s->max_repair_rounds ?: 3, budgetVnd: (float) $s->run_budget_vnd ?: 50000.0);
    }
}
