<?php

namespace App\Services\Images;

use App\Contracts\ImageGeneratorInterface;
use App\Models\AiModelRoute;
use Illuminate\Support\Facades\Schema;

/**
 * Chọn nhà cung cấp ảnh từ route 'image_gen' (Cài đặt → Tích hợp AI → Định tuyến): provider + model, khóa mã hóa có sẵn.
 * Hỗ trợ provider kiểu gemini (Imagen/Gemini ảnh) và openai_compat; kiểu khác (anthropic) không tạo ảnh.
 * Không có route/khóa: ném ImageGeneratorUnavailable. Riêng môi trường local và CHƯA có route nào mới dùng Mock (có nhãn).
 * Giá một ảnh: pricing['image_vnd'] của provider nếu có, không thì config('agent.images.cost_vnd_per_image') (ước tính, chưa đo thật).
 */
final class ImageGeneratorResolver
{
    /** @return array{generator: ImageGeneratorInterface, label: string, demo: bool} */
    public static function resolve(): array
    {
        $route = Schema::hasTable('ai_model_routes') ? AiModelRoute::query()->with('primaryProvider')->where('task', 'image_gen')->first() : null;
        if (! $route) {
            if (app()->environment('local')) {
                return ['generator' => new MockImageGenerator, 'label' => 'Ảnh giả lập (môi trường local)', 'demo' => true];
            }
            throw new ImageGeneratorUnavailable('Chưa cấu hình nhà cung cấp tạo ảnh (route "Tạo ảnh minh họa" ở Cài đặt → Tích hợp AI).');
        }
        $p = $route->primaryProvider;
        if (! $route->primary_provider_id || ! $route->primary_model || ! $p) {
            throw new ImageGeneratorUnavailable('Route "Tạo ảnh minh họa" chưa chọn provider và model.');
        }
        if (! $p->enabled) {
            throw new ImageGeneratorUnavailable("Provider \"{$p->name}\" đang tắt.");
        }
        if (! $p->hasKey()) {
            throw new ImageGeneratorUnavailable("Provider \"{$p->name}\" chưa có khóa API.");
        }
        $cost = (float) (($p->pricing['image_vnd'] ?? null) ?: config('agent.images.cost_vnd_per_image', 1000));
        $gen = match ($p->kind) {
            'gemini' => new GeminiImageGenerator($p->api_key, $route->primary_model, $cost),
            'openai_compat' => new OpenAiCompatImageGenerator($p->api_key, $route->primary_model, $p->base_url, $cost),
            default => throw new ImageGeneratorUnavailable("Provider \"{$p->name}\" (kiểu {$p->kind}) không hỗ trợ tạo ảnh. Chọn Google hoặc OpenAI-tương thích."),
        };

        return ['generator' => $gen, 'label' => $p->name.' / '.$route->primary_model, 'demo' => false];
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
}
