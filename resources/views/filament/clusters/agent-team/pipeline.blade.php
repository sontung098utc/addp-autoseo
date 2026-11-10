<x-filament-panels::page>
    <div data-testid="pipeline" class="space-y-3">
        <div class="text-sm" style="opacity:.85">Chuỗi làm ra một bài <x-studio.hint text="Mỗi mắt xích có các loại việc phục vụ nó. 'Chưa có mã' nghĩa là mới khai báo, chưa chạy được." /></div>
        <div data-testid="pipeline-chain" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(13rem,1fr));gap:.6rem;align-items:start">
            @foreach ($chain as $i => $link)
                <div data-link="{{ $link['key'] }}" class="space-y-2">
                    <div style="font-weight:600;border-bottom:2px solid rgba(128,128,128,.5);padding-bottom:.25rem">{{ $i + 1 }} · {{ $link['label'] }}</div>
                    @forelse ($link['jobs'] as $j)
                        @php($llm = collect($j['steps'])->where('kind', 'llm')->pluck('label')->all())
                        <div data-job="{{ $j['id'] }}" class="text-xs space-y-1" style="border:1px solid rgba(128,128,128,.35);border-radius:.6rem;padding:.6rem .7rem">
                            <div style="font-weight:600;font-size:.85rem">{{ $j['title'] }}</div>
                            <div style="opacity:.55">{{ $j['status'] === 'active' ? 'Đã có mã chạy' : 'Chưa có mã' }}</div>
                            <div style="opacity:.8">{{ $j['summary'] }}</div>
                            <div><strong>Ghi:</strong> {{ implode(', ', $j['writes']) ?: 'chưa khai' }}</div>
                            <div><strong>Hiện:</strong> {{ implode('; ', $j['shown_at']) ?: 'chưa khai' }}</div>
                            <div><strong>Ai dùng tiếp:</strong> {{ collect($j['used_by'])->map(fn ($u) => $names[$u] ?? $u)->implode(', ') ?: 'chưa khai' }}</div>
                            <div>{{ count($j['steps']) }} bước. {{ $llm ? 'Tốn token: '.implode(', ', $llm).'.' : 'Không tốn token.' }}</div>
                            <x-filament::button size="xs" color="gray" wire:click="mountAction('job', {job: '{{ $j['id'] }}'})">Chi tiết</x-filament::button>
                        </div>
                    @empty
                        <div class="text-xs" style="opacity:.6">Chưa có loại việc</div>
                    @endforelse
                </div>
            @endforeach
        </div>
        <details data-testid="pipeline-map9" style="margin-top:.5rem">
            <summary class="text-sm" style="cursor:pointer;font-weight:600">Bản đồ 9 giai đoạn (trạng thái bằng chứng)</summary>
        <div class="space-y-3" style="margin-top:.5rem">
        <div class="text-sm" style="opacity:.85">9 giai đoạn <x-studio.hint text="Trạng thái mỗi giai đoạn được tính từ sổ tính năng và các phiên chạy thật, không tô tay. Giai đoạn chưa chạy trong phiên của đội AI không bao giờ hiện 'Đang hoạt động'." /></div>
        <div class="space-y-2">
            @foreach ($stages as $s)
                <div data-stage="{{ $s['id'] }}" style="display:flex;flex-wrap:wrap;gap:.6rem;align-items:center;border:1px solid rgba(128,128,128,.35);border-radius:.6rem;padding:.6rem .8rem">
                    <div style="flex:1 1 14rem">
                        <div style="font-weight:600">{{ $s['n'] }} · {{ $s['title'] }}</div>
                        <div class="text-xs" style="opacity:.65">{{ $s['role'] }}</div>
                    </div>
                    <div style="flex:2 1 16rem" class="text-xs">
                        @foreach ($s['nodes'] as $node)<code style="margin-right:.3rem">{{ $node }}</code>@endforeach
                    </div>
                    <div class="text-xs" style="flex:0 0 9rem;opacity:.8">
                        @if ($s['wired'])
                            {{ $s['runs'] > 0 ? $s['runs'].' phiên (30 ngày)' : 'Chưa có phiên nào' }}
                        @else
                            Chưa chạy trong phiên
                        @endif
                    </div>
                    <x-studio.chip domain="readiness" :value="$s['readiness']" />
                    <x-filament::button size="xs" color="gray" wire:click="mountAction('stage', {stage: '{{ $s['id'] }}'})">Chi tiết</x-filament::button>
                </div>
            @endforeach
        </div>
        </div>
        </details>
        <div class="text-xs" data-testid="pipeline-controls">
            @if ($controls)
                {{-- Nút điều khiển chỉ được thêm ở đây khi job nền và API pipeline có thật. --}}
            @else
                <span style="opacity:.8">Chạy lại / Tạm dừng: chưa có</span> <x-studio.hint tone="warning" text="Chạy lại / Tạm dừng pipeline: chưa nối. Cần job nền và API pipeline (AG-B10). Chưa có nút giả." />
            @endif
        </div>
    </div>
    <x-filament-actions::modals />
</x-filament-panels::page>
