<?php

namespace App\Services\Agent\Ai;

use App\Models\AiModelRoute;
use App\Models\AiProvider;
use Illuminate\Support\Facades\Schema;

/**
 * AG-S11: cho một loại việc, trả provider + model theo bảng ai_model_routes.
 * Route chưa cấu hình, provider thiếu khóa hoặc đang tắt thì trả null (tác vụ không chạy) — KHÔNG có Mock thay thế.
 * Dự phòng chỉ là một model THẬT khác đã cấu hình; việc chuyển sang nó do AgentRunOrchestrator thực hiện và ghi vào nhật ký run.
 */
final class AiModelRouter
{
    /** Chỉ chế độ định tuyến khi đã có ít nhất một route. Chưa có thì giữ đường cũ (agent_settings/env, một Gemini). */
    public static function isConfigured(): bool
    {
        return Schema::hasTable('ai_model_routes') && AiModelRoute::query()->exists();
    }

    public function target(string $task): ?RouteTarget
    {
        $r = $this->route($task);

        return $r ? $this->build($r->primaryProvider, $r->primary_model, $r->cost_cap_vnd_per_call) : null;
    }

    /** Model dự phòng THẬT; null nếu không có, hoặc trùng đúng provider+model với model chính (vô nghĩa). */
    public function fallback(string $task): ?RouteTarget
    {
        $r = $this->route($task);
        if (! $r || ! $r->fallback_provider_id || ! $r->fallback_model) {
            return null;
        }
        if ($r->fallback_provider_id === $r->primary_provider_id && $r->fallback_model === $r->primary_model) {
            return null;
        }

        return $this->build($r->fallbackProvider, $r->fallback_model, $r->cost_cap_vnd_per_call);
    }

    /** Vì sao một loại việc chưa chạy được (tiếng Việt, không chứa khóa); null nếu chạy được. */
    public function why(string $task): ?string
    {
        $label = AiModelRoute::TASKS[$task][0] ?? $task;
        $r = $this->route($task);
        if (! $r || ! $r->primary_provider_id || ! $r->primary_model) {
            return "Loại việc \"{$label}\" chưa được cấu hình provider và model.";
        }
        $p = $r->primaryProvider;
        if (! $p || ! $p->enabled) {
            return "Provider của \"{$label}\" đang tắt hoặc đã bị xóa.";
        }
        if (! $p->hasKey()) {
            return "Provider \"{$p->name}\" chưa có khóa API nên \"{$label}\" không chạy.";
        }

        return null;
    }

    /** @return list<string> các loại việc trong $tasks chưa chạy được */
    public function missing(array $tasks): array
    {
        return array_values(array_filter($tasks, fn ($t) => $this->why($t) !== null));
    }

    private function route(string $task): ?AiModelRoute
    {
        return AiModelRoute::query()->with(['primaryProvider', 'fallbackProvider'])->where('task', $task)->first();
    }

    private function build(?AiProvider $p, ?string $model, ?float $cap): ?RouteTarget
    {
        if (! $p || ! $p->enabled || ! $p->hasKey() || ! $model) {
            return null;
        }

        return new RouteTarget(AiProviderFactory::make($p, $model), $p->id, $p->name, $p->kind, $model, $cap);
    }
}
