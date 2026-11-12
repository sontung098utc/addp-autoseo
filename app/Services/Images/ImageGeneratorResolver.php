<?php

namespace App\Services\Images;

use App\Contracts\ImageGeneratorInterface;
use App\Models\AgentRoleAssignment;
use App\Models\AiModelRoute;
use App\Models\AiProvider;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Chọn nhà cung cấp ảnh. Thứ tự ưu tiên:
 *  1) model ảnh gán cho vai trò Họa viên (designer) ở Đội AI → Vai trò → Mô hình & kỹ thuật (chính, và dự phòng nếu có; bản gán đang bật);
 *  2) route cũ 'image_gen' (AiModelRoute) để tương thích với cấu hình đã có;
 *  3) không có gì: môi trường local dùng ảnh giả lập có nhãn, nơi khác ném ImageGeneratorUnavailable.
 * Hỗ trợ provider kiểu gemini (Imagen/Gemini ảnh) và openai_compat; kiểu khác (anthropic) không tạo ảnh.
 * Giá một ảnh: pricing['image_vnd'] của provider nếu có, không thì config('agent.images.cost_vnd_per_image') (ước tính, chưa đo thật).
 */
final class ImageGeneratorResolver
{
    public const ROLE = 'designer';

    /** @return array{generator: ImageGeneratorInterface, label: string, demo: bool, source: string} source: designer|route|mock */
    public static function resolve(): array
    {
        $a = self::assignment();
        if ($a !== null) {
            $primary = self::tryBuild($a->provider, $a->model);
            $fallback = $a->fallback_provider_id && $a->fallback_model ? self::tryBuild($a->fallbackProvider, $a->fallback_model) : null;
            if ($primary instanceof ImageGeneratorUnavailable && ! ($fallback instanceof ImageGeneratorInterface)) {
                throw $primary; // đã gán cho Họa viên nhưng dùng không được: báo rõ, không âm thầm đổi sang cấu hình khác
            }
            [$gen, $label] = $primary instanceof ImageGeneratorInterface
                ? [$primary, $a->provider->name.' / '.$a->model]
                : [$fallback, $a->fallbackProvider->name.' / '.$a->fallback_model];
            if ($primary instanceof ImageGeneratorInterface && $fallback instanceof ImageGeneratorInterface) {
                $gen = new FallbackImageGenerator($primary, $fallback);
                $label .= ' (dự phòng '.$a->fallbackProvider->name.' / '.$a->fallback_model.')';
            }

            return ['generator' => $gen, 'label' => $label, 'demo' => false, 'source' => 'designer'];
        }

        $route = Schema::hasTable('ai_model_routes') ? AiModelRoute::query()->with('primaryProvider')->where('task', 'image_gen')->first() : null;
        if (! $route) {
            if (app()->environment('local')) {
                return ['generator' => new MockImageGenerator, 'label' => 'Ảnh giả lập (môi trường local)', 'demo' => true, 'source' => 'mock'];
            }
            throw new ImageGeneratorUnavailable('Chưa cấu hình model ảnh: gán cho vai trò Họa viên (Đội AI → Vai trò → Họa viên hình ảnh → Mô hình & kỹ thuật).');
        }
        $p = $route->primaryProvider;
        if (! $route->primary_provider_id || ! $route->primary_model || ! $p) {
            throw new ImageGeneratorUnavailable('Route tạo ảnh cũ chưa chọn provider và model.');
        }
        $gen = self::build($p, $route->primary_model);

        return ['generator' => $gen, 'label' => $p->name.' / '.$route->primary_model, 'demo' => false, 'source' => 'route'];
    }

    /** Vì sao chưa tạo được ảnh; null nếu có nhà cung cấp. */
    public static function why(): ?string
    {
        try {
            self::resolve();

            return null;
        } catch (ImageGeneratorUnavailable $e) {
            return $e->getMessage();
        }
    }

    /** Dòng trạng thái cho trang cấu hình: model đang dùng và nguồn (designer|route|mock|none). @return array{source: string, label: ?string, text: string} */
    public static function status(): array
    {
        try {
            $r = self::resolve();

            return $r['source'] === 'mock'
                ? ['source' => 'mock', 'label' => null, 'text' => 'Tạo ảnh: chưa gán (đang dùng ảnh giả lập, chỉ ở môi trường local)']
                : ['source' => $r['source'], 'label' => $r['label'], 'text' => 'Tạo ảnh: '.$r['label']];
        } catch (ImageGeneratorUnavailable $e) {
            $a = self::assignment();

            return $a !== null
                ? ['source' => 'designer', 'label' => null, 'text' => 'Tạo ảnh: đã gán nhưng chưa dùng được ('.$e->getMessage().')']
                : ['source' => 'none', 'label' => null, 'text' => 'Tạo ảnh: chưa gán model ảnh cho vai trò Họa viên'];
        }
    }

    /** Bản gán model đang bật của vai trò Họa viên (có provider + model), hoặc null. */
    private static function assignment(): ?AgentRoleAssignment
    {
        try {
            if (! Schema::hasTable('agent_role_assignments')) {
                return null;
            }
            $a = AgentRoleAssignment::query()->with(['provider', 'fallbackProvider'])->where('role_key', self::ROLE)->first();

            return $a && $a->enabled && $a->provider_id && $a->model ? $a : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function tryBuild(?AiProvider $p, ?string $model): ImageGeneratorInterface|ImageGeneratorUnavailable
    {
        try {
            if ($p === null || $model === null) {
                throw new ImageGeneratorUnavailable('Nhà cung cấp ảnh đã gán không còn tồn tại.');
            }

            return self::build($p, $model);
        } catch (ImageGeneratorUnavailable $e) {
            return $e;
        }
    }

    private static function build(AiProvider $p, string $model): ImageGeneratorInterface
    {
        if (! $p->enabled) {
            throw new ImageGeneratorUnavailable("Provider \"{$p->name}\" đang tắt.");
        }
        if (! $p->hasKey()) {
            throw new ImageGeneratorUnavailable("Provider \"{$p->name}\" chưa có khóa API.");
        }
        $cost = (float) (($p->pricing['image_vnd'] ?? null) ?: config('agent.images.cost_vnd_per_image', 1000));

        return match ($p->kind) {
            'gemini' => new GeminiImageGenerator($p->api_key, $model, $cost),
            'openai_compat' => new OpenAiCompatImageGenerator($p->api_key, $model, $p->base_url, $cost),
            default => throw new ImageGeneratorUnavailable("Provider \"{$p->name}\" (kiểu {$p->kind}) không hỗ trợ tạo ảnh. Chọn Google hoặc OpenAI-tương thích."),
        };
    }
}
