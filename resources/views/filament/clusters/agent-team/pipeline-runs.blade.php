<div data-testid="pipeline-runs">
    <div class="text-sm" style="margin-bottom:.5rem" data-testid="pipeline-runs-summary">
        {{ count($runs) }} phiên gần đây (đang chạy và 24 giờ qua) ·
        <strong data-testid="pipeline-live-count">{{ $liveCount }} phiên đang thật sự chạy</strong>
    </div>
    @forelse ($runs as $r)
        <div class="pv-run" data-run="{{ $r['run']->id }}" data-live="{{ $r['live'] ? 1 : 0 }}">
            <div style="flex:1 1 14rem">
                <div style="font-weight:600">{{ $r['job_title'] }}
                    @if ($r['live'])<span class="ss-live-pill"><span class="ss-spin"></span> Đang chạy</span>@else<span class="pv-tag {{ in_array($r['status'], ['failed', 'blocked'], true) ? 'pv-tag--bad' : '' }}" @if (in_array($r['status'], ['failed', 'blocked'], true)) data-testid="run-failed-tag" @endif>{{ ['running' => 'Có thể bị treo', 'ready_for_review' => 'Chờ duyệt', 'held' => 'Tạm giữ', 'blocked' => 'Bị chặn', 'failed' => 'Lỗi', 'published' => 'Đã đăng', 'approved' => 'Đã duyệt'][$r['status']] ?? $r['status'] }}</span>@endif
                </div>
                <div class="text-xs" style="opacity:.75">{{ $r['subject'] }}</div>
                @if (in_array($r['status'], ['failed', 'blocked'], true))
                    <details class="text-xs" data-testid="run-reason"><summary style="cursor:pointer;color:#b91c1c;font-weight:600">Xem lý do</summary>{{ $r['reason'] !== '' ? $r['reason'] : 'Phiên không ghi lý do; mở "Xem diễn biến" để biết bước nào dừng.' }}</details>
                @endif
                @if ($r['live'] && $r['current'])<div class="text-xs">Bước hiện tại: <strong>{{ $r['current'] }}</strong>@if ($r['who']) · {{ $r['who']['title'] }}@endif</div>@endif
            </div>
            {{-- Dải chặng chỉ có nghĩa khi phiên đang chạy; phiên đã xong đã có nhãn kết quả ở tiêu đề (chuỗi chặng xám lặp ở mọi dòng chỉ là nhiễu). --}}
            @if ($r['live'])<div class="pv-bar" style="flex:2 1 16rem">@foreach ($r['bar'] as $b)<span class="pv-seg pv-seg--{{ $b['state'] }}">{{ $b['label'] }}</span>@endforeach</div>@endif
            <div class="text-xs" style="flex:0 0 7rem;margin-left:auto;text-align:right">{{ $r['elapsed'] }}<br>{{ number_format($r['cost'], 0, ',', '.') }} ₫</div>
            <div style="display:flex;gap:.3rem;flex-wrap:wrap;flex:0 0 auto">
                @if (\App\Filament\Resources\AgentRunResource::canViewAny())<x-filament::button size="xs" tag="a" :href="\App\Filament\Resources\AgentRunResource::viewUrl($r['run'])">Xem diễn biến</x-filament::button>@endif
                @if ($r['result_url'])<x-filament::button size="xs" color="gray" tag="a" :href="$r['result_url']">Xem kết quả</x-filament::button>@endif
            </div>
        </div>
    @empty
        <div class="text-sm" data-testid="pipeline-runs-empty"><a class="underline" href="{{ \App\Filament\Resources\AgentRunResource::getUrl('index') }}">Xem tất cả phiên chạy</a></div>
    @endforelse
</div>
