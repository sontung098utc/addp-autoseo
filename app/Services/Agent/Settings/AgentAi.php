<?php

namespace App\Services\Agent\Settings;

use App\Contracts\AiProviderInterface;
use App\Models\AgentSetting;
use App\Services\Agent\Run\RunPolicy;
use App\Services\Ai\DemoScriptedAiProvider;
use App\Services\Ai\GeminiAiProvider;

/**
 * AG-S01d: MỘT chỗ duy nhất trả lời "đội AI đang dùng AI nào?". Khóa lấy từ cấu hình trong hệ thống (mã hóa),
 * nếu chưa có thì mới dùng biến môi trường GOOGLE_AI_API_KEY. Không bao giờ trả khóa ra giao diện.
 * Định tuyến theo vai trò (dàn ý/viết/biên tập dùng model khác nhau) là AG-B06 của Dev1; hiện một nhà cung cấp, một model.
 */
final class AgentAi
{
    public const DEFAULT_MODEL = 'gemini-1.5-flash';

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
