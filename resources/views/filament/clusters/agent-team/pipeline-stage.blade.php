@if ($s)
<div data-testid="stage-detail" class="space-y-3 text-sm">
    <div><x-studio.chip domain="readiness" :value="$s['readiness']" /></div>
    <div><strong>Nguồn dữ liệu:</strong> {{ $s['source'] }}</div>
    <div><strong>Tính năng liên quan:</strong>
        @forelse ($s['feature_rows'] as $f)
            <div style="margin-left:.5rem">{{ $f['title'] }}: {{ \App\Filament\Support\StatusPresenter::label($f['readiness'], 'readiness') }}</div>
        @empty chưa có @endforelse
    </div>
    <div><strong>Phiên gần đây (30 ngày):</strong>
        @if ($s['wired'])
            {{ $s['runs'] }} phiên đi qua
            @if ($s['step_stats'])
                ({{ collect($s['step_stats'])->map(fn ($c, $st) => "$c $st")->implode(', ') }} bước)
            @endif
        @else
            Giai đoạn này chưa chạy trong phiên của đội AI nên chưa có số liệu.
        @endif
    </div>
    <div><strong>Điều kiện đạt (tự động):</strong>
        @if ($s['gate'])
            {{ $s['gate'] }}.
            {{ $s['gate_rate'] !== null ? 'Hiện '.round($s['gate_rate'] * 100).'% bước đạt.' : 'Chưa có dữ liệu.' }}
        @else
            Chưa có điều kiện tự động cho giai đoạn này.
        @endif
    </div>
    <div style="color:#b45309"><strong>Việc cần Dev1:</strong>
        @forelse ($s['dev1_tasks'] as $t)<div style="margin-left:.5rem">• {{ $t }}</div>@empty không có @endforelse
    </div>
    <div class="text-xs" style="opacity:.7">
        @if ($controls) @else Chạy lại: chưa nối, cần job nền và API pipeline. @endif
    </div>
</div>
@endif
