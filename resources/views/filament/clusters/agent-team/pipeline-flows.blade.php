@php($firstId = collect($flows)->firstWhere('id', 'article_write')['id'] ?? ($flows[0]['id'] ?? ''))
<div data-testid="pipeline-flows" class="fl" x-data="{ flow: (location.hash.startsWith('#luong-') ? location.hash.slice(7) : '') || @js($firstId), pick(id) { this.flow = id; history.replaceState(null, '', '#luong-' + id) } }">
    <div class="pv-chips" role="tablist" aria-label="Chọn luồng">
        @foreach ($flows as $f)
            <button type="button" role="tab" class="pv-chipbtn" data-flow-chip="{{ $f['id'] }}" :class="{ 'is-on': flow === '{{ $f['id'] }}' }" :aria-selected="flow === '{{ $f['id'] }}' ? 'true' : 'false'" x-on:click="pick('{{ $f['id'] }}')">
                <span class="pv-dot pv-dot--{{ $f['status'] === 'active' ? 'on' : 'off' }}"></span>{{ $f['title'] }}
            </button>
        @endforeach
    </div>
    @foreach ($flows as $f)
        <section class="pv-flow" data-flow="{{ $f['id'] }}" x-show="flow === '{{ $f['id'] }}'" @if ($f['id'] !== $firstId) x-cloak @endif>
            <div class="pv-flow__head">
                <div class="pv-flow__title">
                    <h3>{{ $f['title'] }}</h3>
                    <span class="pv-tag pv-tag--{{ $f['status'] === 'active' ? 'ok' : 'off' }}">{{ $f['status'] === 'active' ? 'Chạy được' : 'Chưa chạy' }}</span>
                    @if ($f['help'])
                        <x-help title="{{ $f['title'] }}">
                            @foreach ($f['help'] as $line)<p>{{ $line }}</p>@endforeach
                        </x-help>
                    @endif
                </div>
                <div class="pv-flow__actions">
                    @if ($f['url'])<x-filament::button size="xs" tag="a" :href="$f['url']" data-testid="open-work-{{ $f['id'] }}">Mở trang làm việc</x-filament::button>@endif
                    <x-filament::button size="xs" color="gray" wire:click="mountAction('job', {job: '{{ $f['id'] }}'})">Chi tiết</x-filament::button>
                </div>
            </div>
            <p class="pv-flow__plain">{{ $f['plain'] }}</p>
            <div class="fl-scroll">
                <div class="fl-row">
                    <x-flow.end label="Đầu vào" side="in" :items="$f['inputs']" :links="$f['input_links']" data-end="input" />
                    @foreach ($f['steps'] as $s)
                        <x-flow.link :when="$s['when']" :dashed="$s['planned']" />
                        <x-flow.node :n="$s['n']" :label="$s['label']" :kind="$s['kind']" :human="$s['human']" :planned="$s['planned']" :tier="$s['tier']"
                            :avatar="$s['profile']['avatar']" :who="$s['profile']['title']"
                            :state="in_array($s['id'], $liveSteps[$f['id']] ?? [], true) ? 'running' : 'idle'"
                            data-step="{{ $s['id'] }}" data-role="{{ $s['profile']['key'] }}" wire:click="mountAction('step', {job: '{{ $f['id'] }}', step: '{{ $s['id'] }}'})">
                            <span class="fl-meta">@if ($s['planned'])Chưa chạy @elseif ($s['when'])Tùy điều kiện @endif</span>
                        </x-flow.node>
                    @endforeach
                    <x-flow.link />
                    <x-flow.end label="Kết quả" side="out" :items="$f['outputs']" :links="$f['output_links']" data-end="output" />
                </div>
            </div>
            <div class="pv-legend"><span><x-filament::icon icon="heroicon-m-cog-6-tooth" class="fl-ico" /> Luật</span><span><x-filament::icon icon="heroicon-m-sparkles" class="fl-ico" /> AI</span><span><x-filament::icon icon="heroicon-m-user" class="fl-ico" /> Người can thiệp</span><span>┄ tùy điều kiện</span><span>Nét đứt mờ: chưa chạy</span></div>
        </section>
    @endforeach
</div>
