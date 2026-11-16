@php
    $col = ['ok' => '#16a34a', 'warn' => '#d97706', 'blocked' => '#6b7280', 'failed' => '#dc2626'];
    $lab = ['ok' => 'xong', 'warn' => 'cần chú ý', 'blocked' => 'bị chặn', 'failed' => 'lỗi'];
@endphp
<div data-testid="flow-modal" class="space-y-4">
    <div>
        <div class="text-sm" style="font-weight:600;margin-bottom:.4rem">Đường đi của một bài</div>
        <div style="display:flex;flex-wrap:wrap;gap:.4rem;align-items:stretch" data-testid="lifecycle">
            @foreach ($life as $l)
                @if (! $loop->first)<div style="align-self:center;opacity:.5"><x-filament::icon icon="heroicon-m-chevron-right" style="width:1rem;height:1rem" /></div>@endif
                <a href="{{ $l['url'] }}" style="flex:1 1 7rem;border:1px solid rgba(128,128,128,.35);border-radius:.6rem;padding:.55rem;text-decoration:none;color:inherit">
                    <div style="font-size:.68rem;opacity:.6">{{ $l['who'] }}</div>
                    <div style="font-size:1.3rem;font-weight:700;line-height:1.1">{{ $l['n'] }}</div>
                    <div style="font-size:.75rem">{{ $l['label'] }}</div>
                </a>
            @endforeach
        </div>
    </div>
    <div>
        <div class="text-sm" style="font-weight:600;margin-bottom:.4rem">Các chặng bên trong đội AI <x-help text="{{ $total }} phiên chạy thật trong 30 ngày qua. Bấm vào chặng để xem phần đã có và phần Dev1 cần xây." /></div>
        <div style="display:flex;flex-wrap:wrap;gap:.4rem">
            @foreach ($nodes as $n)
                @if (! $loop->first)<div style="align-self:center;opacity:.5"><x-filament::icon icon="heroicon-m-chevron-right" style="width:1rem;height:1rem" /></div>@endif
                <details style="flex:1 1 8.5rem;border:1px solid rgba(128,128,128,.4);border-radius:.6rem;padding:.55rem" data-stage="{{ $n['stage'] }}">
                    <summary style="cursor:pointer;list-style:none">
                        <div style="font-size:.68rem;opacity:.6">{{ $n['role'] }}</div>
                        <div style="font-weight:600;font-size:.82rem">{{ $n['title'] }}</div>
                        <div style="margin:.25rem 0"><x-studio.chip domain="readiness" :value="$n['readiness']" /></div>
                        <div style="font-size:.68rem">
                            @foreach ($n['stats'] as $st => $c)<span style="color:{{ $col[$st] ?? '#6b7280' }}">{{ $c }} {{ $lab[$st] ?? $st }}</span>@if (! $loop->last) · @endif @endforeach
                        </div>
                    </summary>
                    <div style="font-size:.72rem;margin-top:.5rem;line-height:1.35">
                        <div>{{ $n['what'] }}</div>
                        <div style="margin-top:.3rem"><strong>Đã có:</strong> {{ $n['built'] }}</div>
                        <div style="margin-top:.3rem;color:#b45309"><strong>Dev1 cần ({{ $n['task'] }}):</strong> {{ $n['dev1'] }}</div>
                    </div>
                </details>
            @endforeach
        </div>
    </div>
</div>
