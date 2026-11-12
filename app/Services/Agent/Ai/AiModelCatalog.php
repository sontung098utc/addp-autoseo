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
    public const KIND_TEXT = 'text';

    public const KIND_IMAGE = 'image';

    public const KIND_LABELS = ['text' => 'Chữ', 'image' => 'Ảnh'];

    /** Loại model suy từ tên khi chưa khai báo: chứa image, imagen hoặc nano-banana => ảnh, còn lại => chữ. */
    public static function inferKind(string $id): string
    {
        return preg_match('/image|imagen|nano-banana/i', $id) ? self::KIND_IMAGE : self::KIND_TEXT;
    }

    /**
     * Đọc một mục trong cột models: chuỗi tên (dữ liệu cũ, loại suy từ tên) hoặc mảng {id, kind}.
     *
     * @return array{id: string, kind: ?string} kind null = chưa khai báo
     */
    public static function parseEntry(mixed $e): array
    {
        if (is_array($e)) {
            $k = $e['kind'] ?? null;

            return ['id' => (string) ($e['id'] ?? ''), 'kind' => in_array($k, ['text', 'image'], true) ? $k : null];
        }

        return ['id' => (string) $e, 'kind' => null];
    }

    /** Mục để lưu: chuỗi khi loại trùng loại suy từ tên (giữ dạng cũ), mảng {id, kind} khi người dùng đã sửa khác suy đoán. */
    public static function entryFor(string $id, string $kind): string|array
    {
        return $kind === self::inferKind($id) ? $id : ['id' => $id, 'kind' => $kind];
    }

    /** Loại model (text|image) của một model trong một tài khoản; model không có trong tài khoản thì suy từ tên. */
    public static function kindOf(AiProvider $p, string $model): string
    {
        foreach (self::enabledModels($p) as $m) {
            if ($m['id'] === $model) {
                return $m['kind'];
            }
        }

        return self::inferKind($model);
    }

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
        return array_map(fn (array $m) => $m + ['context' => null, 'price' => null, 'default' => false, 'kind' => self::inferKind((string) $m['id'])], (array) config("ai_models.models.{$brand}", []));
    }

    /** @return array<string, string> id => tên, cho CheckboxList */
    public static function options(string $brand): array
    {
        $o = [];
        foreach (self::catalog($brand) as $m) {
            $o[$m['id']] = $m['name'].' · '.($m['kind'] === self::KIND_IMAGE ? 'Ảnh' : (AiProvider::TIER_LABELS[$m['tier']] ?? $m['tier']));
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
        $entries = is_array($p->models) ? $p->models : self::defaults($brand);
        $out = [];
        $seen = [];
        foreach ($entries as $e) {
            ['id' => $id, 'kind' => $declared] = self::parseEntry($e);
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $m = $cat[$id] ?? null;
            $out[] = ['id' => $id, 'name' => $m['name'] ?? $id, 'tier' => $m['tier'] ?? null, 'context' => $m['context'] ?? null, 'price' => $m['price'] ?? null, 'custom' => $m === null,
                'kind' => $declared ?? ($m['kind'] ?? self::inferKind($id))];
        }

        return $out;
    }

    /**
     * Model KHẢ DỤNG để gán cho nhân sự: chỉ tài khoản đang bật và đã có khóa, nhóm theo tài khoản.
     *
     * @return list<array{id: int, name: string, brand: string, logo: string, models: list<array<string, mixed>>}>
     */
    public static function available(?string $kind = null): array
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
                $models = self::enabledModels($p);
                if ($kind !== null) {
                    $models = array_values(array_filter($models, fn ($m) => $m['kind'] === $kind));
                }
                $out[] = ['id' => $p->id, 'name' => $p->name, 'brand' => $brand, 'logo' => self::logoOf($brand), 'models' => $models];
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
