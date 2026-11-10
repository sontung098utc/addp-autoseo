<div data-testid="pipeline-office" class="pv-desks">
    @foreach ($office as $d)
        <div class="pv-desk" data-desk="{{ $d['profile']['key'] }}">
            <div style="display:flex;gap:.6rem;align-items:center">
                <span class="pv-avatar">{{ $d['profile']['avatar'] }}</span>
                <div><div style="font-weight:700;font-size:.85rem">{{ $d['profile']['name'] }}</div><div style="opacity:.75">{{ $d['profile']['title'] }}</div></div>
            </div>
            @if ($d['working'])
                <div data-status="working"><span class="pv-dot"></span> <strong>Đang làm</strong>
                    @foreach ($d['working'] as $w)<div>{{ $w['job_title'] }}: {{ $w['current'] }} <span style="opacity:.7">({{ $w['subject'] }})</span>
                        @if (\App\Filament\Resources\AgentRunResource::canViewAny())<a class="pv-link" href="{{ \App\Filament\Resources\AgentRunResource::getUrl('view', ['record' => $w['run']]) }}">Xem</a>@endif</div>@endforeach
                </div>
            @else
                <div data-status="idle"><span class="pv-dot pv-dot--idle"></span> Rảnh</div>
            @endif
            <div><strong>Tham gia:</strong>
                @forelse ($d['joins'] as $j)<div>• {{ $j['job'] }}: {{ implode(', ', $j['steps']) }}</div>@empty<div style="opacity:.75">Chưa tham gia luồng nào.</div>@endforelse
            </div>
            <div>Hôm nay: {{ $d['runs_today'] }} phiên · {{ number_format($d['cost_today'], 0, ',', '.') }} ₫</div>
            @if ($rolesUrl)<div><a class="pv-link" href="{{ $rolesUrl }}">Mở hồ sơ</a></div>@endif
        </div>
    @endforeach
</div>
