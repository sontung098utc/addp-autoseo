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
            <p class="text-sm">Bản tin sẽ xuất hiện ở đây khi hệ thống có hoạt động (bản bài mới, duyệt, đăng bài). Hiện chưa có gì để báo.</p>
            <p class="text-sm mt-2">
                Bắt đầu: <a href="{{ \App\Filament\Resources\ArticleResource::getUrl('index') }}" class="underline">tạo bài viết</a>
                hoặc xem <a href="{{ \App\Filament\Pages\Dashboard::getUrl() }}" class="underline">Tổng quan</a>.
            </p>
        </x-filament::section>
    @else
        <div style="display:grid;grid-template-columns:repeat({{ min(4, max(1, count($d['stats']))) }},minmax(0,1fr));gap:.75rem">
            @foreach ($d['stats'] as $s)
                <x-filament::section>
                    <div class="text-xs" style="opacity:.7">{{ $s['label'] }}</div>
                    <div style="font-size:1.6rem;font-weight:600;line-height:1.2">{{ $s['value'] }}</div>
                    <div class="text-xs" style="opacity:.65">{{ $s['sub'] }}</div>
                </x-filament::section>
            @endforeach
        </div>

        <x-filament::section heading="Nhóm việc đáng xem" :description="$d['state'] === 'busy' ? 'Ngày nhiều việc: gom thành tối đa '.\App\Filament\Support\Digest\DigestQueries::MAX_GROUPS.' nhóm, xếp theo mức cần chú ý.' : null">
            @forelse ($d['groups'] as $g)
                <div style="display:flex;justify-content:space-between;gap:.75rem;align-items:center;padding:.5rem 0;border-bottom:1px solid rgba(128,128,128,.2)">
                    <div>
                        <div style="font-weight:600;font-size:.9rem">{{ $g['title'] }}
                            <x-filament::badge :color="$sev[$g['severity']] ?? 'gray'">{{ $g['count'] }}</x-filament::badge>
                        </div>
                        <div class="text-xs" style="opacity:.7">{{ $g['detail'] }}</div>
                    </div>
                    <a href="{{ $g['url'] }}" class="text-sm underline">Xem</a>
                </div>
            @empty
                <p class="text-sm">Không có nhóm việc nào cần chú ý.</p>
            @endforelse
        </x-filament::section>
    @endif

    <div class="text-xs" style="opacity:.75">
        @foreach ($d['notes'] as $n)
            <p style="margin:.15rem 0">• {{ $n }}</p>
        @endforeach
    </div>
</x-filament-panels::page>
