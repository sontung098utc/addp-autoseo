<?php

namespace App\Filament\Support\Gsc;

use App\Filament\Support\Demo\GscDemoData;
use App\Models\Publication;
use Carbon\CarbonImmutable;

/** Nguồn MINH HỌA: số liệu sinh theo ngày; trang đầu tiên dùng URL bài đã đăng thật nếu DB có (để liên kết trang -> bài chạy thật). */
final class GscDemoSource implements GscSource
{
    /** @var list<string>|null */
    private ?array $realUrls = null;

    public function __construct(private readonly ?CarbonImmutable $today = null) {}

    public function rows(string $dimension, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $this->realUrls ??= Publication::query()->where('status', 'published')->whereNotNull('published_url')
            ->orderBy('id')->limit(3)->pluck('published_url')->all();

        return GscDemoData::rows($dimension, $from, $to, $this->today ?? CarbonImmutable::now(), $this->realUrls);
    }

    public function isEmpty(): bool
    {
        return false;
    }
}
