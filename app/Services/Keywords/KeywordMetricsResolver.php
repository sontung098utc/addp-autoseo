<?php

namespace App\Services\Keywords;

use App\Contracts\KeywordMetricsProvider;

/**
 * Chọn nguồn số liệu lượt tìm. Có DATAFORSEO_LOGIN/PASSWORD: dùng DataForSEO. Không có: chỉ môi trường local dùng bản giả lập CÓ NHÃN;
 * mọi môi trường khác ném KeywordMetricsUnavailable (không im lặng dùng số giả).
 */
final class KeywordMetricsResolver
{
    public const HOWTO = 'Đặt DATAFORSEO_LOGIN / DATAFORSEO_PASSWORD trong .env để lấy lượt tìm thật. Hoặc nhập CSV có cột volume.';

    /** @return array{provider: KeywordMetricsProvider, label: string, demo: bool} */
    public static function resolve(): array
    {
        $login = (string) config('keywords.dataforseo.login');
        $pass = (string) config('keywords.dataforseo.password');
        if (config('keywords.provider', 'dataforseo') === 'dataforseo' && $login !== '' && $pass !== '') {
            return ['provider' => new DataForSeoKeywordMetricsProvider($login, $pass), 'label' => 'DataForSEO', 'demo' => false];
        }
        if (app()->environment('local')) {
            return ['provider' => new MockKeywordMetricsProvider, 'label' => 'Số liệu giả lập (môi trường local, không phải lượt tìm thật)', 'demo' => true];
        }
        throw new KeywordMetricsUnavailable('Chưa cấu hình nguồn số liệu lượt tìm. '.self::HOWTO);
    }

    /** @return array{ok: bool, reason: ?string, label: ?string, demo: bool} */
    public static function availability(): array
    {
        try {
            $r = self::resolve();

            return ['ok' => true, 'reason' => null, 'label' => $r['label'], 'demo' => $r['demo']];
        } catch (KeywordMetricsUnavailable $e) {
            return ['ok' => false, 'reason' => $e->getMessage(), 'label' => null, 'demo' => false];
        }
    }
}
