<?php

namespace App\Services\Search;

use App\Contracts\WebSearchProvider;

/**
 * Chọn nhà cung cấp tìm web. Không có khóa: production KHÔNG dùng giả im lặng (ném WebSearchUnavailable kèm hướng dẫn);
 * môi trường local/testing dùng Mock có nhãn.
 */
final class WebSearchResolver
{
    public const HINT = 'Chưa cấu hình tìm kiếm web. Đặt TAVILY_API_KEY trong .env hoặc cài cầu nối Claude (php artisan agent:claude-setup) hoặc bật một nhà cung cấp Gemini có khóa, rồi tải lại.';

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
        if ($name === 'tavily' && trim((string) config('search.tavily.key')) !== '') {
            // Có khóa Tavily: dùng Tavily VÀ AI tự tìm (cầu nối Claude nếu có, không thì Gemini) cùng lúc, gộp kết quả.
            $ai = ClaudeBridgeSearchProvider::configured() ? [app(ClaudeBridgeSearchProvider::class), 'Claude'] : (GeminiSearchProvider::configured() ? [app(GeminiSearchProvider::class), 'Gemini'] : null);
            if ($ai !== null) {
                return ['provider' => new CombinedSearchProvider([app($class), $ai[0]]), 'label' => 'Tavily + AI tự tìm ('.$ai[1].')', 'demo' => false];
            }
        }
        if ($name === 'tavily' && trim((string) config('search.tavily.key')) === '' && ClaudeBridgeSearchProvider::configured()) {
            return ['provider' => app(ClaudeBridgeSearchProvider::class), 'label' => 'Tìm web qua Claude', 'demo' => false]; // không có khóa Tavily: dùng cầu nối Claude
        }
        if ($name === 'tavily' && trim((string) config('search.tavily.key')) === '' && GeminiSearchProvider::configured()) {
            return ['provider' => app(GeminiSearchProvider::class), 'label' => 'Tìm web qua Gemini (Google Search)', 'demo' => false]; // không có Tavily và cầu nối: Gemini tự tìm bằng khóa đã lưu
        }
        if ($name === 'tavily' && trim((string) config('search.tavily.key')) === '') {
            if (\App\Filament\Support\Demo\DemoMode::enabled() && (bool) config('search.allow_mock', true)) {
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
