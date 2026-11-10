<?php

namespace App\Services\Search;

use App\Contracts\WebSearchProvider;

/**
 * Chọn nhà cung cấp tìm web. Không có khóa: production KHÔNG dùng giả im lặng (ném WebSearchUnavailable kèm hướng dẫn);
 * môi trường local/testing dùng Mock có nhãn.
 */
final class WebSearchResolver
{
    public const HINT = 'Chưa cấu hình tìm kiếm web. Đặt TAVILY_API_KEY trong .env rồi tải lại.';

    /** @return array{provider: WebSearchProvider, label: string, demo: bool} @throws WebSearchUnavailable */
    public function resolve(): array
    {
        if (! (bool) config('search.enabled', true)) {
            throw new WebSearchUnavailable('Tìm kiếm web đang tắt (SEARCH_ENABLED=false).');
        }
        $name = (string) config('search.provider', 'tavily');
        $class = config('search.providers.'.$name);
        if (! is_string($class) || ! class_exists($class)) {
            throw new WebSearchUnavailable('Nhà cung cấp tìm kiếm "'.$name.'" chưa được cài.');
        }
        if ($name === 'tavily' && trim((string) config('search.tavily.key')) === '') {
            if (app()->environment(['local', 'testing']) && (bool) config('search.allow_mock', app()->environment('local'))) {
                return ['provider' => new MockWebSearchProvider, 'label' => 'Tìm kiếm mô phỏng (môi trường local)', 'demo' => true];
            }
            throw new WebSearchUnavailable(self::HINT);
        }

        return ['provider' => app($class), 'label' => ucfirst($name), 'demo' => false];
    }

    /** @return array{ok: bool, reason: ?string, label: ?string, demo: bool} */
    public function availability(): array
    {
        try {
            $r = $this->resolve();

            return ['ok' => true, 'reason' => null, 'label' => $r['label'], 'demo' => $r['demo']];
        } catch (WebSearchUnavailable $e) {
            return ['ok' => false, 'reason' => $e->getMessage(), 'label' => null, 'demo' => false];
        } catch (\Throwable) {
            return ['ok' => false, 'reason' => self::HINT, 'label' => null, 'demo' => false];
        }
    }
}
