<?php

namespace App\Filament\Support\Competitors;

use App\Filament\Support\Demo\DemoMode;
use App\Filament\Support\Demo\DemoStore;
use App\Models\Website;

/**
 * Điểm ghép nối duy nhất của màn Đối thủ: chọn nguồn, lưu danh sách đối thủ + ảnh chụp dữ liệu (DemoStore).
 * Production: không có nguồn => màn hiện "chưa kết nối" thật, không số mẫu.
 */
final class CompetitorService
{
    /** Nguồn hiện hành; null = chưa kết nối. Test/triển khai thật có thể app()->instance(KeywordGapSource::class, ...). */
    public static function source(bool $failing = false): ?KeywordGapSource
    {
        if (app()->bound(KeywordGapSource::class)) {
            return app(KeywordGapSource::class);
        }

        return DemoMode::enabled() ? new DemoKeywordGapSource($failing) : null;
    }

    /** @return list<string> */
    public static function domains(Website $w): array
    {
        return array_values((array) DemoStore::get("competitors.domains.{$w->id}", []));
    }

    /** @param list<string> $domains */
    public static function saveDomains(Website $w, array $domains): void
    {
        DemoStore::put("competitors.domains.{$w->id}", array_values($domains));
    }

    /** @return ?array{fetched_at:string, domains:list<string>, rows:list<array<string,mixed>>} */
    public static function snapshot(Website $w): ?array
    {
        $s = DemoStore::get("competitors.snapshot.{$w->id}");

        return is_array($s) ? $s : null;
    }

    /** @param list<array<string,mixed>> $rows */
    public static function saveSnapshot(Website $w, array $domains, array $rows): void
    {
        DemoStore::put("competitors.snapshot.{$w->id}", ['fetched_at' => now()->toIso8601String(), 'domains' => $domains, 'rows' => $rows]);
    }
}
