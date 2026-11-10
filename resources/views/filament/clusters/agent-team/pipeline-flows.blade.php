<div data-testid="pipeline-flows">
    <div class="text-xs" style="opacity:.8;margin-bottom:.6rem">Mỗi loại việc là một luồng. Nút ở các trang chính chạy đúng một luồng trong đây. Bấm vào một bước để xem chi tiết và số liệu 30 ngày. Viền đứt: chưa chạy. Viền vàng: có người can thiệp.</div>
    @foreach ($flows as $f)
        <section class="pv-flow" data-flow="{{ $f['id'] }}">
            <div class="pv-flow__head">
                <strong>{{ $f['title'] }}</strong>
                <span class="pv-tag {{ $f['status'] === 'active' ? '' : 'pv-tag--warn' }}">{{ $f['status'] === 'active' ? 'Chạy được' : 'Chưa chạy' }}</span>
                <x-filament::button size="xs" color="gray" wire:click="mountAction('job', {job: '{{ $f['id'] }}'})">Chi tiết loại việc</x-filament::button>
            </div>
            <div class="pv-scroll">
                <div class="pv-row">
                    <div class="pv-end pv-end--in" data-end="input">
                        <h4>ĐẦU VÀO</h4>
                        <ul>@forelse ($f['inputs'] as $in)<li>{{ $in }}</li>@empty<li>Chưa khai</li>@endforelse</ul>
                        <div>@foreach ($f['input_links'] as $l)<a href="{{ $l['url'] }}" data-record="input">{{ $l['label'] }}</a>@endforeach</div>
                    </div>
                    @foreach ($f['steps'] as $s)
                        <div class="pv-arrow {{ $s['when'] ? 'pv-arrow--when' : '' }}">@if ($s['when'])<span title="{{ $s['when'] }}">tùy điều kiện</span>@endif</div>
                        <button type="button" data-step="{{ $s['id'] }}" data-role="{{ $s['profile']['key'] }}" wire:click="mountAction('step', {job: '{{ $f['id'] }}', step: '{{ $s['id'] }}'})"
                            class="pv-node {{ $s['planned'] ? 'pv-node--planned' : '' }} {{ $s['human'] ? 'pv-node--human' : '' }} {{ in_array($s['id'], $liveSteps[$f['id']] ?? [], true) ? 'pv-node--live' : '' }}">
                            <span class="pv-node__top"><span class="pv-num">{{ $s['n'] }}</span><span class="pv-label">{{ $s['label'] }}</span></span>
                            <span class="pv-kind">
                                @if ($s['kind'] === 'llm')
                                    <span>✨ AI{{ $s['tier'] ? ' · '.$s['tier'] : '' }}</span>
                                @else
                                    <span>⚙ Luật</span>
                                @endif
                                @if ($s['human'])
                                    <span>· 👤 Người duyệt</span>
                                @endif
                            </span>
                            <span class="pv-who"><span class="pv-chip" data-avatar>{{ $s['profile']['avatar'] }}</span><span>{{ $s['profile']['title'] }}</span></span>
                            @if ($s['planned'])<span class="pv-tag pv-tag--warn">Chưa chạy</span>@endif
                        </button>
                    @endforeach
                    <div class="pv-arrow"></div>
                    <div class="pv-end pv-end--out" data-end="output">
                        <h4>KẾT QUẢ</h4>
                        <ul>@forelse ($f['outputs'] as $o)<li>{{ $o }}</li>@empty<li>Chưa khai</li>@endforelse</ul>
                        <div>@foreach ($f['output_links'] as $l)<a href="{{ $l['url'] }}" data-record="{{ $l['type'] }}">{{ $l['label'] }}{{ $l['type'] === 'report' ? ' (báo cáo)' : '' }}</a>@endforeach</div>
                    </div>
                </div>
            </div>
        </section>
    @endforeach
</div>
