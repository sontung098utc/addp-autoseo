<?php

namespace App\Services\Images;

use App\Contracts\ImageGeneratorInterface;
use App\Models\AgentRunStep;
use App\Models\Article;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Công việc "tạo ảnh theo kế hoạch" chạy SONG SONG với bước viết. Bước 'images' của phiên được tạo ngay khi bắt đầu (kèm kế hoạch);
 * tiến trình con (artisan agent:run-images) hoặc chính tiến trình hiện tại (chế độ đồng bộ) tạo ảnh và ghi kết quả vào đúng bước đó.
 * Chế độ song song chỉ bật khi config agent.images.parallel = true (cần CSDL dùng chung được giữa hai tiến trình: không dùng với SQLite :memory:).
 */
final class ImageJob
{
    /** Chạy trong tiến trình hiện tại: tạo ảnh theo kế hoạch lưu ở $step và ghi kết quả vào $step->data['gen']. */
    public static function execute(AgentRunStep $step, ?ImageGeneratorInterface $generator = null, ?Article $article = null): void
    {
        $data = (array) $step->data;
        $article ??= Article::query()->find($data['article_id'] ?? null);
        try {
            $generator ??= ImageGeneratorResolver::resolve()['generator'];
            if (! $article) {
                throw new ImageGenerationError('Không thấy bài viết của kế hoạch ảnh.');
            }
            $gen = (new ArticleImageService)->generateSlots($article, (array) ($data['plan']['slots'] ?? []), $generator, (float) ($data['cap_vnd'] ?? 0));
        } catch (\Throwable $e) {
            $gen = ['items' => [], 'generated' => 0, 'cost_vnd' => 0.0, 'stopped' => null, 'errors' => [\App\Filament\Support\Schedule\ErrorMasker::mask($e->getMessage())]];
        }
        $step->refresh();
        $data = (array) $step->data;
        $data['gen'] = $gen + ['done' => true];
        // Thời lượng thật của việc chạy nền: từ lúc bước được ghi đến khi xong.
        $data['duration_ms'] = $step->started_at ? max(0, (int) now()->diffInMilliseconds($step->started_at, true)) : ($data['duration_ms'] ?? null);
        $step->update(['data' => $data, 'cost_vnd' => $gen['cost_vnd'], 'finished_at' => now()]);
    }

    /** Song song chỉ khi bật cấu hình VÀ CSDL dùng chung được giữa hai tiến trình (SQLite :memory: thì không). */
    public static function parallelEnabled(): bool
    {
        return (bool) config('agent.images.parallel', false) && self::dbShareable();
    }

    /** CSDL dùng chung được giữa hai tiến trình (không phải SQLite :memory:): điều kiện để chạy tiến trình con. */
    public static function dbShareable(): bool
    {
        try {
            $db = \Illuminate\Support\Facades\DB::connection();

            return ! ($db->getDriverName() === 'sqlite' && in_array((string) $db->getDatabaseName(), ['', ':memory:'], true));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Biến môi trường cho tiến trình con: CSDL, môi trường, cache và khóa ứng dụng của tiến trình ĐANG CHẠY.
     * Tiến trình con (artisan) tự nạp .env; máy chủ web (artisan serve) lại bỏ biến môi trường của container nên con không nhận được cấu hình CSDL thật và rơi về .env (mysql, host không tồn tại),
     * chết ngay và ảnh không bao giờ được tạo. Truyền tường minh từ cấu hình đang dùng thì con luôn dùng đúng CSDL với cha.
     *
     * @return array<string,string>
     */
    public static function childEnv(?string $connection = null): array
    {
        $default = $connection ?? (string) config('database.default');
        $c = (array) config('database.connections.'.$default);
        $env = ['DB_CONNECTION' => $default, 'APP_ENV' => (string) app()->environment(), 'CACHE_STORE' => (string) config('cache.default')];
        if ((string) config('app.key') !== '') {
            $env['APP_KEY'] = (string) config('app.key'); // giải mã khóa nhà cung cấp AI/ảnh; cùng một cây tiến trình nên không lộ ra ngoài
        }
        if ($default === 'sqlite') {
            $env['DB_DATABASE'] = (string) ($c['database'] ?? '');
        } else {
            foreach (['host' => 'DB_HOST', 'port' => 'DB_PORT', 'database' => 'DB_DATABASE', 'username' => 'DB_USERNAME', 'password' => 'DB_PASSWORD'] as $k => $e) {
                if (isset($c[$k]) && $c[$k] !== '') {
                    $env[$e] = (string) $c[$k];
                }
            }
        }

        return $env;
    }

    /** Bắt đầu tiến trình con; null nếu không khởi động được (nơi gọi chạy đồng bộ). */
    public static function spawn(AgentRunStep $step): ?InvokedProcess
    {
        try {
            $php = (new PhpExecutableFinder)->find(false) ?: PHP_BINARY;

            return Process::path(base_path())->env(self::childEnv())->timeout((int) config('agent.images.parallel_timeout', 900))->start([$php, 'artisan', 'agent:run-images', (string) $step->id]);
        } catch (\Throwable) {
            return null;
        }
    }
}
