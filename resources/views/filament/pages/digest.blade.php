@php
    $sev = ['danger' => 'danger', 'warning' => 'warning', 'info' => 'info'];
@endphp
<x-filament-panels::page>
    @if ($d['stop'])
        <div style="border:1px solid rgba(220,38,38,.4);background:rgba(220,38,38,.08);border-radius:.5rem;padding:.6rem .8rem;font-size:.85rem">
            <strong>Đang dừng xuất bản.</strong> Mọi lượt đăng đang tạm hoãn. Lý do: {{ $d['stop']['reason'] ?? 'không ghi' }}.
        </div>
    @endif

    @if ($d['state'] === 'empty')
        <x-filament::section>
            <div style="display:flex;gap:1rem;align-items:center;justify-content:space-between;flex-wrap:wrap">
                <div class="text-sm">Chưa có hoạt động nào để báo.
                    <x-help title="Bản tin hiện khi nào"><p>Bản tin xuất hiện khi hệ thống có hoạt động (bản bài mới, duyệt, đăng bài).</p>@foreach ($d['notes'] as $n)<p>• {{ $n }}</p>@endforeach</x-help></div>
                <x-filament::button tag="a" :href="\App\Filament\Resources\ArticleResource::getUrl('index')">Mở danh sách bài viết</x-filament::button>
            </div>
        </x-filament::section>
    @else
        @php
            $mainStats = array_slice($d['stats'], 0, 4);
            $moreStats = array_filter(array_slice($d['stats'], 4), fn ($s) => ! in_array(trim((string) $s['value']), ['0', '—', '0 ₫', '0 / 0'], true));
        @endphp
        <div style="display:grid;grid-template-columns:repeat({{ min(4, max(1, count($mainStats))) }},minmax(0,1fr));gap:.75rem">
            @foreach ($mainStats as $s)
                <x-filament::section>
                    <div class="text-xs" style="opacity:.7">{{ $s['label'] }}</div>
                    <div style="font-size:1.6rem;font-weight:600;line-height:1.2">{{ $s['value'] }}</div>
                    @if ($s['sub'] !== '')<div class="text-xs" style="opacity:.65">{{ $s['sub'] }}</div>@endif
                </x-filament::section>
            @endforeach
        </div>
        @if ($moreStats !== [])
            <div class="text-xs" style="display:flex;flex-wrap:wrap;gap:.25rem 1rem;opacity:.8">
                @foreach ($moreStats as $s)<span>{{ $s['label'] }}: <strong>{{ $s['value'] }}</strong></span>@endforeach
            </div>
        @endif

        <x-filament::section>
            <x-slot name="heading">Nhóm việc đáng xem
                @if ($d['state'] === 'busy' || $d['notes'] !== [])
                    <x-help title="Về bản tin">
                        @if ($d['state'] === 'busy')<p>Ngày nhiều việc: gom thành tối đa {{ \App\Filament\Support\Digest\DigestQueries::MAX_GROUPS }} nhóm, xếp theo mức cần chú ý.</p>@endif
                        @foreach ($d['notes'] as $n)<p>• {{ $n }}</p>@endforeach
                    </x-help>
                @endif
            </x-slot>
            @forelse ($d['groups'] as $g)
                <div style="display:flex;justify-content:space-between;gap:.75rem;align-items:center;padding:.5rem 0;border-bottom:1px solid rgba(128,128,128,.2)">
                    <div>
                        <div style="font-weight:600;font-size:.9rem">{{ $g['title'] }}
                            <x-filament::badge :color="$sev[$g['severity']] ?? 'gray'">{{ $g['count'] }}</x-filament::badge>
                        </div>
                        @if ($g['detail'] !== '')<div class="text-xs" style="opacity:.7">{{ $g['detail'] }}</div>@endif
                    </div>
                    <a href="{{ $g['url'] }}" class="text-sm underline">Xem</a>
                </div>
            @empty
                <p class="text-sm">Không có nhóm việc nào cần chú ý.</p>
            @endforelse
        </x-filament::section>
    @endif

</x-filament-panels::page>
