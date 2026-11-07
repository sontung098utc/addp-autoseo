@php
    $col = ['ok' => '#16a34a', 'warn' => '#d97706', 'blocked' => '#6b7280', 'failed' => '#dc2626'];
    $lab = ['ok' => 'xong', 'warn' => 'cần chú ý', 'blocked' => 'bị chặn', 'failed' => 'lỗi'];
@endphp
<x-filament-panels::page>
    <div data-testid="flow-map" class="space-y-4">
        <x-filament::section>
            <p class="text-sm">
                Đây là đường đi của <strong>mỗi bài</strong> khi giao cho đội AI. Con số lấy từ {{ $total }} phiên chạy thật trong 30 ngày qua.
                Bấm vào một chặng để xem phần đã có và phần Dev1 cần xây. Ở bậc tin cậy T1, kết quả luôn dừng ở <strong>bản nháp chờ người duyệt</strong>.
            </p>
            @if ($total === 0)<p class="mt-2 text-xs" style="opacity:.7">Chưa có phiên chạy nào. Tạo chiến dịch hoặc bấm "Giao đội AI" ở trang Bài viết.</p>@endif
        </x-filament::section>

        <div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:stretch">
            <a href="{{ $campaignUrl }}" style="flex:1 1 9rem;border:2px dashed rgba(128,128,128,.5);border-radius:.6rem;padding:.6rem;text-decoration:none;color:inherit">
                <div style="font-size:.7rem;opacity:.6">Điểm vào</div>
                <div style="font-weight:600;font-size:.85rem">Chiến dịch</div>
                <div style="font-size:.7rem;opacity:.75">Từ khóa + giới hạn bài/ngày. Tự giao theo lịch.</div>
            </a>
            @foreach ($nodes as $n)
                <div style="flex:0 0 auto;align-self:center;opacity:.5">→</div>
                <details style="flex:1 1 9rem;border:1px solid rgba(128,128,128,.4);border-radius:.6rem;padding:.6rem" data-stage="{{ $n['stage'] }}">
                    <summary style="cursor:pointer;list-style:none">
                        <div style="font-size:.7rem;opacity:.6">{{ $n['role'] }}</div>
                        <div style="font-weight:600;font-size:.85rem">{{ $n['title'] }}</div>
                        <div style="margin:.3rem 0"><x-studio.chip domain="readiness" :value="$n['readiness']" /></div>
                        <div style="font-size:.7rem">
                            @forelse ($n['stats'] as $st => $c)<span style="color:{{ $col[$st] ?? '#6b7280' }}">{{ $c }} {{ $lab[$st] ?? $st }}</span>@if (! $loop->last) · @endif @empty<span style="opacity:.55">chưa có số liệu</span>@endforelse
                        </div>
                    </summary>
                    <div style="font-size:.75rem;margin-top:.5rem;line-height:1.35">
                        <div>{{ $n['what'] }}</div>
                        <div style="margin-top:.3rem"><strong>Đã có:</strong> {{ $n['built'] }}</div>
                        <div style="margin-top:.3rem;color:#b45309"><strong>Dev1 cần ({{ $n['task'] }}):</strong> {{ $n['dev1'] }}</div>
                    </div>
                </details>
            @endforeach
            <div style="flex:0 0 auto;align-self:center;opacity:.5">→</div>
            <a href="{{ $reviewUrl }}" style="flex:1 1 9rem;border:2px solid rgba(22,163,74,.6);border-radius:.6rem;padding:.6rem;text-decoration:none;color:inherit">
                <div style="font-size:.7rem;opacity:.6">Điểm ra</div>
                <div style="font-weight:600;font-size:.85rem">Người duyệt</div>
                <div style="font-size:.7rem;opacity:.75">Duyệt bản nháp rồi mới xếp lịch đăng.</div>
            </a>
        </div>

        <x-filament::section heading="Kết cục các phiên chạy" description="30 ngày gần nhất">
            <div style="display:flex;flex-wrap:wrap;gap:.5rem">
                @forelse ($byStatus as $st => $c)
                    <x-filament::badge :color="$statusMap[$st][1] ?? 'gray'">{{ $statusMap[$st][0] ?? $st }}: {{ $c }}</x-filament::badge>
                @empty
                    <span class="text-xs" style="opacity:.6">Chưa có dữ liệu.</span>
                @endforelse
            </div>
            <div class="mt-2 text-xs"><a class="underline" href="{{ $runsUrl }}">Xem từng phiên chạy</a></div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
