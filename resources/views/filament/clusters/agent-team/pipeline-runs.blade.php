<div data-testid="pipeline-runs">
    @forelse ($runs as $r)
        <div class="pv-run" data-run="{{ $r['run']->id }}" data-live="{{ $r['live'] ? 1 : 0 }}">
            <div style="flex:1 1 14rem">
                <div style="font-weight:600">{{ $r['job_title'] }}
                    @if ($r['live'])<span class="ss-live-pill"><span class="ss-spin"></span> Đang chạy</span>@else<span class="pv-tag">{{ ['running' => 'Có thể bị treo', 'ready_for_review' => 'Chờ duyệt', 'held' => 'Tạm giữ', 'blocked' => 'Bị chặn', 'failed' => 'Lỗi'][$r['status']] ?? $r['status'] }}</span>@endif
                </div>
                <div class="text-xs" style="opacity:.75">{{ $r['subject'] }}</div>
                @if ($r['live'] && $r['current'])<div class="text-xs">Bước hiện tại: <strong>{{ $r['current'] }}</strong>@if ($r['who']) · {{ $r['who']['title'] }}@endif</div>@endif
            </div>
            {{-- Dải chặng chỉ có nghĩa khi phiên đang chạy; phiên đã xong đã có nhãn kết quả ở tiêu đề (chuỗi chặng xám lặp ở mọi dòng chỉ là nhiễu). --}}
            @if ($r['live'])<div class="pv-bar" style="flex:2 1 16rem">@foreach ($r['bar'] as $b)<span class="pv-seg pv-seg--{{ $b['state'] }}">{{ $b['label'] }}</span>@endforeach</div>@endif
            <div class="text-xs" style="flex:0 0 7rem;margin-left:auto;text-align:right">{{ $r['elapsed'] }}<br>{{ number_format($r['cost'], 0, ',', '.') }} ₫</div>
            <div style="display:flex;gap:.3rem;flex-wrap:wrap;flex:0 0 auto">
                @if (\App\Filament\Resources\AgentRunResource::canViewAny())<x-filament::button size="xs" tag="a" :href="\App\Filament\Resources\AgentRunResource::getUrl('view', ['record' => $r['run']])">Xem diễn biến</x-filament::button>@endif
                @if ($r['result_url'])<x-filament::button size="xs" color="gray" tag="a" :href="$r['result_url']">Xem kết quả</x-filament::button>@endif
            </div>
        </div>
    @empty
        <div class="text-sm" data-testid="pipeline-runs-empty"><a class="underline" href="{{ \App\Filament\Resources\AgentRunResource::getUrl('index') }}">Xem tất cả phiên chạy</a></div>
    @endforelse
</div>
