<?php

namespace App\Services\Agent\Ai;

use App\Models\AiProvider;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Danh mục nhà cung cấp/model tạo sẵn (config/ai_models.php) và model ĐÃ BẬT của từng tài khoản. Chỉ để hiển thị và chọn, không đổi định tuyến.
 * Tài khoản chưa chọn model (cột models = null) dùng bộ mặc định của danh mục.
 */
final class AiModelCatalog
{
    /** @return array<string, array<string, mixed>> */
    public static function presets(): array
    {
        return (array) config('ai_models.presets', []);
    }

    /** Nhãn thương hiệu của tài khoản (khóa preset), suy từ kiểu + base URL. */
    public static function brandOf(AiProvider $p): string
    {
        if ($p->kind === 'gemini') {
            return 'gemini';
        }
        if ($p->kind === 'anthropic') {
            return 'anthropic';
        }
        $host = strtolower((string) parse_url((string) $p->base_url, PHP_URL_HOST));
        if ($host !== '' && str_ends_with($host, 'openai.com')) {
            return 'openai';
        }
        if ($host !== '' && str_ends_with($host, 'openrouter.ai')) {
            return 'openrouter';
        }

        return 'custom';
    }

    /** Khóa logo của một preset/tài khoản (google|anthropic|openai|openrouter|custom). */
    public static function logoOf(string $brand): string
    {
        return (string) (self::presets()[$brand]['logo'] ?? 'custom');
    }

    /** @return list<array{id: string, name: string, tier: string, context: ?int, price: ?array, default: bool}> */
    public static function catalog(string $brand): array
    {
        return array_map(fn (array $m) => $m + ['context' => null, 'price' => null, 'default' => false], (array) config("ai_models.models.{$brand}", []));
    }

    /** @return array<string, string> id => tên, cho CheckboxList */
    public static function options(string $brand): array
    {
        $o = [];
        foreach (self::catalog($brand) as $m) {
            $o[$m['id']] = $m['name'].' · '.(AiProvider::TIER_LABELS[$m['tier']] ?? $m['tier']);
        }

        return $o;
    }

    /** @return list<string> id model bật sẵn khi thêm tài khoản */
    public static function defaults(string $brand): array
    {
        return array_values(array_map(fn ($m) => $m['id'], array_filter(self::catalog($brand), fn ($m) => $m['default'])));
    }

    public static function validCustomName(string $name): bool
    {
        return (bool) preg_match((string) config('ai_models.custom_model_pattern'), $name);
    }

    /**
     * Model đã bật của tài khoản, kèm thông tin danh mục (model tùy chỉnh: tier null).
     *
     * @return list<array{id: string, name: string, tier: ?string, context: ?int, price: ?array, custom: bool}>
     */
    public static function enabledModels(AiProvider $p): array
    {
        $brand = self::brandOf($p);
        $cat = [];
        foreach (self::catalog($brand) as $m) {
            $cat[$m['id']] = $m;
        }
        $ids = is_array($p->models) ? $p->models : self::defaults($brand);
        $out = [];
        foreach (array_values(array_unique(array_map('strval', $ids))) as $id) {
            $m = $cat[$id] ?? null;
            $out[] = ['id' => $id, 'name' => $m['name'] ?? $id, 'tier' => $m['tier'] ?? null, 'context' => $m['context'] ?? null, 'price' => $m['price'] ?? null, 'custom' => $m === null];
        }

        return $out;
    }

    /**
     * Model KHẢ DỤNG để gán cho nhân sự: chỉ tài khoản đang bật và đã có khóa, nhóm theo tài khoản.
     *
     * @return list<array{id: int, name: string, brand: string, logo: string, models: list<array<string, mixed>>}>
     */
    public static function available(): array
    {
        try {
            if (! Schema::hasTable('ai_providers')) {
                return [];
            }
            $out = [];
            foreach (AiProvider::query()->where('enabled', true)->orderBy('name')->get() as $p) {
                if (! $p->hasKey()) {
                    continue;
                }
                $brand = self::brandOf($p);
                $out[] = ['id' => $p->id, 'name' => $p->name, 'brand' => $brand, 'logo' => self::logoOf($brand), 'models' => self::enabledModels($p)];
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    public static function contextLabel(?int $ctx): ?string
    {
        if (! $ctx) {
            return null;
        }

        return $ctx >= 1000000 ? rtrim(rtrim(number_format($ctx / 1000000, 1), '0'), '.').'M' : round($ctx / 1000).'K';
    }
}
