<div data-testid="pipeline-runs">
    @if (! $live)<div class="text-xs" style="opacity:.8;margin-bottom:.5rem">Không có phiên nào đang chạy. Dưới đây là các phiên trong 24 giờ qua.</div>@endif
    @forelse ($runs as $r)
        <div class="pv-run" data-run="{{ $r['run']->id }}" data-live="{{ $r['live'] ? 1 : 0 }}">
            <div style="flex:1 1 14rem">
                <div style="font-weight:600">{{ $r['job_title'] }}
                    @if ($r['live'])<span class="ss-live-pill"><span class="ss-spin"></span> Đang chạy</span>@else<span class="pv-tag">{{ ['running' => 'Có thể bị treo', 'ready_for_review' => 'Chờ duyệt', 'held' => 'Tạm giữ', 'blocked' => 'Bị chặn', 'failed' => 'Lỗi'][$r['status']] ?? $r['status'] }}</span>@endif
                </div>
                <div class="text-xs" style="opacity:.75">{{ $r['subject'] }}</div>
                @if ($r['live'] && $r['current'])<div class="text-xs">Bước hiện tại: <strong>{{ $r['current'] }}</strong>@if ($r['who']) · {{ $r['who']['title'] }}@endif</div>@endif
            </div>
            <div class="pv-bar" style="flex:2 1 16rem">@foreach ($r['bar'] as $b)<span class="pv-seg pv-seg--{{ $b['state'] }}">{{ $b['label'] }}</span>@endforeach</div>
            <div class="text-xs" style="flex:0 0 9rem">{{ $r['elapsed'] }}<br>{{ number_format($r['cost'], 0, ',', '.') }} ₫</div>
            <div style="display:flex;gap:.3rem;flex-wrap:wrap">
                @if (\App\Filament\Resources\AgentRunResource::canViewAny())<x-filament::button size="xs" tag="a" :href="\App\Filament\Resources\AgentRunResource::getUrl('view', ['record' => $r['run']])">Xem diễn biến</x-filament::button>@endif
                @if ($r['result_url'])<x-filament::button size="xs" color="gray" tag="a" :href="$r['result_url']">Xem kết quả</x-filament::button>@endif
            </div>
        </div>
    @empty
        <div class="text-sm" style="opacity:.7" data-testid="pipeline-runs-empty">Chưa có phiên nào trong 24 giờ qua.</div>
    @endforelse
</div>
