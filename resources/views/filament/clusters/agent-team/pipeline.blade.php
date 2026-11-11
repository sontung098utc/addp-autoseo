<x-filament-panels::page>
    <div data-testid="pipeline" class="space-y-3" @if ($live) wire:poll.{{ $pollSeconds }}s @endif>
        @include('filament.clusters.agent-team.pipeline-style')
        <div style="display:flex;align-items:center;gap:.4rem;flex-wrap:wrap">
            <span data-testid="pipeline-tally" class="text-sm" style="font-weight:600">{{ $tally['ready'] }}/{{ $tally['total'] }} việc dùng được, {{ $tally['partial'] }} dùng được một phần, {{ $tally['none'] }} chưa có.</span>
            <x-help title="Chuỗi làm ra một bài">
                <p>Mỗi loại việc là một luồng; nút ở các trang chính chạy đúng một luồng trong đây. Mỗi mắt xích có các loại việc phục vụ nó. Bấm "Chi tiết" để xem các bước và phần kỹ thuật.</p>
                <ul><li>Bấm vào một bước để xem chi tiết và số liệu 30 ngày.</li><li>Viền đứt: chưa chạy. Viền vàng: có người can thiệp.</li></ul>
                <p>Chạy lại / Tạm dừng pipeline: chưa nối. Cần job nền và API pipeline (AG-B10), nên chưa có nút.</p>
            </x-help>
        </div>
        <x-studio.tabs persist="pipeline-view" :default="$live ? 'runs' : 'flows'" label="Cách xem pipeline" :tabs="[
            ['key' => 'flows', 'label' => 'Các luồng', 'count' => count($flows)],
            ['key' => 'runs', 'label' => 'Đang chạy', 'count' => count($runs) ?: null, 'tone' => $live ? 'info' : null],
        ]">
            <x-studio.panel key="flows">
                @include('filament.clusters.agent-team.pipeline-flows')
                <details style="margin-top:1rem">
                    <summary class="text-sm" style="cursor:pointer;font-weight:600">Chuỗi 7 mắt xích (dạng thẻ)</summary>
                    <div style="margin-top:.5rem">
        <div data-testid="pipeline-chain" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(13rem,1fr));gap:.6rem;align-items:start">
            @foreach ($chain as $i => $link)
                <div data-link="{{ $link['key'] }}" class="space-y-2">
                    <div style="font-weight:600;border-bottom:2px solid rgba(128,128,128,.5);padding-bottom:.25rem">{{ $i + 1 }} · {{ $link['label'] }}</div>
                    @forelse ($link['jobs'] as $j)
                        <div data-job="{{ $j['id'] }}" data-usability="{{ $j['usability'] }}" class="text-xs space-y-1" style="border:1px solid rgba(128,128,128,.35);border-radius:.6rem;padding:.6rem .7rem">
                            <div style="font-weight:600;font-size:.85rem">{{ $j['title'] }}</div>
                            <div style="opacity:.7">{{ ['ready' => 'Dùng được', 'partial' => 'Dùng được một phần', 'none' => 'Chưa có'][$j['usability']] }}</div>
                            <div style="opacity:.85">{{ $j['plain'] }}</div>
                            @if ($j['human'])<div><strong>Việc bạn cần làm:</strong> {{ implode('; ', $j['human']) }}</div>@endif
                            <div style="display:flex;gap:.4rem;flex-wrap:wrap;margin-top:.3rem">
                                @if ($j['url'])<x-filament::button size="xs" tag="a" :href="$j['url']" data-testid="open-work-{{ $j['id'] }}">Mở trang làm việc này</x-filament::button>@endif
                                <x-filament::button size="xs" color="gray" wire:click="mountAction('job', {job: '{{ $j['id'] }}'})">Chi tiết</x-filament::button>
                            </div>
                        </div>
                    @empty
                        <div class="text-xs" style="opacity:.6">Chưa có loại việc</div>
                    @endforelse
                </div>
            @endforeach
        </div>
                    </div>
                </details>
            </x-studio.panel>
            <x-studio.panel key="runs">
                @include('filament.clusters.agent-team.pipeline-runs')
            </x-studio.panel>
        </x-studio.tabs>
        <details data-testid="pipeline-map9" style="margin-top:.5rem">
            <summary class="text-sm" style="cursor:pointer;font-weight:600">Bản đồ 9 giai đoạn (trạng thái bằng chứng)</summary>
        <div class="space-y-3" style="margin-top:.5rem">
        <div class="text-sm" style="opacity:.85">9 giai đoạn <x-help title="Trạng thái giai đoạn" text="Tính từ sổ tính năng và các phiên chạy thật, không tô tay. Giai đoạn chưa chạy trong phiên của đội AI không bao giờ hiện 'Đang hoạt động'." /></div>
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
    </div>
    <x-filament-actions::modals />
</x-filament-panels::page>
