@php
    $tone = ['danger' => ['#dc2626', 'rgba(220,38,38,.08)'], 'warning' => ['#d97706', 'rgba(217,119,6,.09)'], 'info' => ['#2563eb', 'rgba(37,99,235,.07)'], 'primary' => ['#0f6b5c', 'rgba(15,107,92,.09)']];
    $fmt = fn ($n) => number_format((float) $n, 0, ',', '.');
@endphp
<x-filament-panels::page>
    <style>.ov-act{color:var(--c)}.dark .ov-act{color:color-mix(in srgb,var(--c) 45%,#fff)}</style>
    <div data-testid="agent-overview" class="space-y-4" wire:poll.{{ $pollSeconds }}s>
        {{-- 1. Trạng thái một dòng + số liệu hôm nay --}}
        <div data-testid="status-strip" style="display:flex;flex-wrap:wrap;gap:.75rem 1.5rem;align-items:center;justify-content:space-between;border:1px solid rgba(128,128,128,.3);border-radius:.7rem;padding:.7rem 1rem">
            <div>
                @if ($liveCount > 0)
                    <div class="ss-live-pill" data-testid="overview-live" style="display:inline-flex;border-radius:.5rem;padding:.25rem .6rem">
                        <x-studio.running label="Đang chạy {{ $liveCount }} phiên" />
                    </div>
                @elseif (! $ai['connected'])
                    <span style="font-weight:600;color:#d97706">Chưa kết nối AI</span>
                @else
                    <span style="font-weight:600">Đang nghỉ</span>
                @endif
            </div>
            <div class="text-sm" data-testid="today" style="display:flex;gap:1.25rem;flex-wrap:wrap">
                @if ($today['runs'] > 0)<span><strong>{{ $today['runs'] }}</strong> bài đã giao hôm nay</span>@endif
                @if ($today['toReview'] > 0)<span><strong>{{ $today['toReview'] }}</strong> chờ duyệt</span>@endif
                @if ($today['cost'] > 0)<span>Chi phí <strong>{{ $fmt($today['cost']) }} ₫</strong>@if ($today['cap'] > 0) <span style="opacity:.6">/ {{ $fmt($today['cap']) }} ₫</span>@endif</span>@endif
            </div>
        </div>

        {{-- 2. Cần bạn: chỉ hiện khi có việc, xếp theo mức nghiêm trọng --}}
        <div data-testid="attention">
            @foreach ($attention as $a)
                <a href="{{ $a['url'] }}" style="display:flex;justify-content:space-between;align-items:center;gap:1rem;text-decoration:none;color:inherit;border:1px solid {{ $tone[$a['tone']][0] }};background:{{ $tone[$a['tone']][1] }};border-radius:.6rem;padding:.6rem .9rem;margin-bottom:.4rem">
                    <span style="font-weight:600;font-size:.9rem">{{ $a['text'] }}</span>
                    <span class="ov-act" style="--c:{{ $tone[$a['tone']][0] }};font-weight:600;font-size:.82rem;white-space:nowrap">{{ $a['action'] }} <x-filament::icon icon="heroicon-m-arrow-right" style="width:.95rem;height:.95rem;display:inline;vertical-align:-.15em" /></span>
                </a>
            @endforeach
        </div>

        {{-- 3. Đang chạy: mỗi phiên một dòng (chi tiết luồng xem trong từng phiên) --}}
        @if ($live->isNotEmpty())
            <x-filament::section heading="Đang chạy" compact>
                @foreach ($live as $r)
                    @php
                        $cur = \App\Filament\Support\Agent\RunActivity::currentStageOf($r, $r->steps);
                        $curTitle = $stages[$cur]['title'] ?? 'Hoàn tất';
                    @endphp
                    <a href="{{ \App\Filament\Resources\AgentRunResource::getUrl('view', ['record' => $r]) }}" data-live-run="{{ $r->id }}" style="display:grid;grid-template-columns:minmax(12rem,1fr) auto auto;gap:1rem;align-items:center;text-decoration:none;color:inherit;padding:.45rem 0;border-bottom:1px solid rgba(128,128,128,.15)">
                        <span style="font-size:.88rem;font-weight:500">{{ $r->article?->primary_keyword ?: (\App\Services\Agent\Pipeline\JobTypeRegistry::find((string) $r->job_type)['title'] ?? $r->job_type) }}</span>
                        <x-studio.running size="sm" label="{{ $curTitle }}" />
                        <span class="text-xs" style="opacity:.6;white-space:nowrap">{{ \App\Filament\Support\Agent\RunActivity::formatElapsed(\App\Filament\Support\Agent\RunActivity::elapsedSeconds($r)) }}</span>
                    </a>
                @endforeach
                @if ($liveCount > $live->count())<div class="text-xs mt-2" style="opacity:.65">và {{ $liveCount - $live->count() }} phiên nữa. <a class="underline" href="{{ $runsUrl }}">Xem tất cả</a></div>@endif
            </x-filament::section>
        @endif

        {{-- 4. Cài đặt: gập sẵn; chỉ là gợi ý, không chiếm chỗ --}}
        @if ($stepsLeft > 0)
            <x-filament::section :heading="'Hoàn tất cài đặt: còn '.$stepsLeft.' bước'" collapsible collapsed compact>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(10.5rem,1fr));gap:.6rem">
                    @foreach ($steps as $s)
                        <{{ $s['url'] ? 'a' : 'div' }} @if ($s['url']) href="{{ $s['url'] }}" @endif data-step="{{ $s['n'] }}" data-state="{{ $s['state'] }}" style="border:1px solid rgba(128,128,128,.35);border-radius:.6rem;padding:.6rem;text-decoration:none;color:inherit;{{ $s['state'] === 'todo' ? 'border-color:#d97706;' : '' }}">
                            <div style="font-size:.7rem;opacity:.6">Bước {{ $s['n'] }}</div>
                            <div style="font-weight:600;font-size:.88rem">{{ $s['title'] }}
                                @if ($s['state'] === 'done')<x-filament::icon icon="heroicon-m-check" style="width:1rem;height:1rem;color:#16a34a;display:inline;vertical-align:-.15em" aria-label="Xong" />@elseif ($s['state'] === 'todo')<span style="color:#d97706" aria-label="Chưa làm">●</span>@endif
                            </div>
                            <div style="font-size:.72rem;opacity:.8;margin-top:.2rem">{{ $s['note'] }}</div>
                        </{{ $s['url'] ? 'a' : 'div' }}>
                    @endforeach
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
