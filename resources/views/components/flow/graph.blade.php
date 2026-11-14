@props(['g'])
{{--
    Sơ đồ luồng dạng đồ thị theo làn vai trò (xem FlowGraph): ô đặt theo vị trí thật, đường nối vuông góc có nhãn (song song / nhập lại / chưa đạt / cần thêm nguồn),
    vòng Chấm ↔ Sửa. Bấm một ô: đặt `sel` của khung cha (x-data của run-flow). Tự thu nhỏ vừa khung nếu hẹp (không nhỏ hơn 70%, hẹp hơn thì cuộn ngang).
--}}
@php use App\Filament\Support\Agent\RunFlow; @endphp
<div class="fg-wrap" x-data="{ s: 1, fit() { const w = this.$refs.box.clientWidth; this.s = Math.max(0.85, Math.min(1, w / {{ $g['width'] }})); } }" x-init="fit()" x-on:resize.window.debounce.150ms="fit()" data-testid="flow-graph">
    <div x-ref="box" class="fg-scroll">
        <div class="fg-canvas" x-bind:style="'width:' + ({{ $g['width'] }} * s) + 'px;height:' + ({{ $g['height'] }} * s) + 'px'">
            <div class="fg-stage" style="width:{{ $g['width'] }}px;height:{{ $g['height'] }}px" x-bind:style="'width:{{ $g['width'] }}px;height:{{ $g['height'] }}px;transform:scale(' + s + ')'">
                @foreach ($g['lanes'] as $i => $lane)
                    <div class="fg-lane @if ($i % 2) fg-lane--alt @endif" style="top:{{ $lane['y'] }}px;height:{{ $lane['h'] }}px"><span>{{ $lane['label'] }}</span></div>
                @endforeach

                <svg class="fg-edges" width="{{ $g['width'] }}" height="{{ $g['height'] }}" viewBox="0 0 {{ $g['width'] }} {{ $g['height'] }}" aria-hidden="true">
                    <defs>
                        @foreach (['idle' => '#94a3b8', 'done' => '#059669', 'live' => '#0284c7', 'loop' => '#7c3aed'] as $k => $c)
                            <marker id="fg-ar-{{ $k }}" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z" fill="{{ $c }}"/></marker>
                        @endforeach
                    </defs>
                    @foreach ($g['edges'] as $e)
                        <path d="{{ $e['d'] }}" class="fg-edge fg-edge--{{ $e['state'] }} fg-edge--{{ $e['kind'] }}" fill="none" marker-end="url(#fg-ar-{{ $e['state'] }})" data-edge="{{ $e['from'] }}>{{ $e['to'] }}" data-edge-state="{{ $e['state'] }}"/>
                    @endforeach
                </svg>
                @foreach ($g['edges'] as $e)
                    @if ($e['label'])
                        <span class="fg-elabel fg-elabel--{{ $e['state'] }}" style="left:{{ $e['lx'] }}px;top:{{ $e['ly'] }}px" @if ($e['kind'] === 'loop' && $e['from'] === 'grade' && $e['state'] === 'loop') data-testid="flow-loop" data-loop @endif>{{ $e['label'] }}</span>
                    @endif
                @endforeach

                @foreach ($g['nodes'] as $code => $n)
                    @php
                        [$stLabel, $stIcon] = RunFlow::STATE[$n['state']];
                        $icon = ['rule' => 'heroicon-m-cog-6-tooth', 'llm' => 'heroicon-m-sparkles', 'human' => 'heroicon-m-user'][$n['kind']] ?? 'heroicon-m-cog-6-tooth';
                        $kindText = ['rule' => 'Luật', 'llm' => 'AI', 'human' => 'Người'][$n['kind']] ?? 'Luật';
                    @endphp
                    <button type="button" class="fl-node fl-node--{{ $n['state'] }} fg-node @if ($n['kind'] === 'human') fl-node--human @endif" style="left:{{ $n['x'] }}px;top:{{ $n['y'] }}px;width:{{ \App\Filament\Support\Agent\FlowGraph::NODE_W }}px;height:{{ \App\Filament\Support\Agent\FlowGraph::NODE_H }}px"
                        data-stage="{{ $code }}" data-state="{{ $n['state'] }}" @if ($n['state'] === 'running') data-live-stage="{{ $code }}" @endif
                        x-on:click="sel = '{{ $code }}'" x-bind:class="{ 'is-sel': sel === '{{ $code }}' }" title="{{ $n['title'] }} · {{ $stLabel }}">
                        <span class="fg-node__head">
                            <x-filament::icon :icon="$icon" class="fl-ico" :title="$kindText" /><small>{{ $kindText }}</small>
                            @if ($n['state'] === 'running')<span class="fg-ping"></span>@else<x-filament::icon :icon="$stIcon" class="fl-stico" :title="$stLabel" />@endif
                        </span>
                        <span class="fg-node__name">{{ $n['title'] }}</span>
                        <span class="fg-node__who">{{ $n['who']['title'] ?? '' }}</span>
                        @if ($n['state'] === 'waiting')<span class="fl-wait-pill" data-testid="flow-waiting">Chờ bạn</span>@else<span class="fg-node__foot fl-state">{{ $stLabel }}@if ($n['count'] > 1) ×{{ $n['count'] }}@endif @if ($n['seconds'] !== null)<em>{{ $n['seconds'] }}s</em>@endif</span>@endif
                    </button>
                    @if ($n['parallel'] ?? null)
                        <span class="fl-chip fg-par" data-testid="flow-parallel" style="left:{{ $n['x'] }}px;top:{{ $n['y'] - 15 }}px" title="Chạy cùng lúc, không chờ bước này xong">∥ song song với «{{ $n['parallel'] }}»</span>
                    @endif
                @endforeach
            </div>
        </div>
    </div>
    <div class="fg-legend">
        <span><i class="fg-sw fg-sw--done"></i>đã đi</span><span><i class="fg-sw fg-sw--idle"></i>không đi trong phiên này</span><span><i class="fg-sw fg-sw--loop"></i>vòng lặp</span><span><i class="fg-sw fg-sw--back"></i>quay lại tìm thêm nguồn</span>
    </div>
</div>
@once
<style>
    .fg-wrap{width:100%}
    .fg-scroll{overflow-x:auto;padding-bottom:.3rem}
    .fg-canvas{position:relative}
    .fg-stage{position:absolute;left:0;top:0;transform-origin:0 0}
    .fg-lane{position:absolute;left:0;right:0;border-radius:.6rem;background:rgba(100,116,139,.045);border:1px solid rgba(100,116,139,.10)}
    .fg-lane--alt{background:rgba(100,116,139,.09)}
    .fg-lane>span{position:absolute;left:.6rem;top:50%;transform:translateY(-50%);width:5.4rem;font-size:.66rem;font-weight:700;letter-spacing:.03em;text-transform:uppercase;opacity:.6;line-height:1.2}
    .fg-edges{position:absolute;left:0;top:0;pointer-events:none;overflow:visible}
    .fg-edge{stroke:#94a3b8;stroke-width:2;stroke-linejoin:round}
    .fg-edge--done{stroke:#059669;stroke-width:2.5}
    .fg-edge--live{stroke:#0284c7;stroke-width:2.5;stroke-dasharray:7 5;animation:fg-march .7s linear infinite}
    .fg-edge--idle{stroke-dasharray:4 5;opacity:.55}
    .fg-edge--loop{stroke:#7c3aed;stroke-width:2.5;stroke-dasharray:none;opacity:1}.fg-edge--loop.fg-edge--idle{stroke:#94a3b8;stroke-dasharray:4 5;opacity:.45}
    .fg-edge--back.fg-edge--done{stroke:#d97706;stroke-dasharray:6 4}
    @keyframes fg-march{to{stroke-dashoffset:-12}}
    @media (prefers-reduced-motion:reduce){.fg-edge--live{animation:none}}
    .fg-elabel{position:absolute;transform:translate(-50%,-50%);font-size:.7rem;font-weight:700;padding:.02rem .4rem;border-radius:999px;background:var(--fl-card,#fff);border:1px solid rgba(100,116,139,.35);color:#475569;white-space:nowrap;pointer-events:none}
    .dark .fg-elabel{color:#cbd5e1}
    .fg-elabel--loop{background:#7c3aed;color:#fff;border-color:#7c3aed}
    .fg-elabel--done{border-color:#059669;color:#047857}.dark .fg-elabel--done{color:#34d399}
    .fg-node{position:absolute;min-height:0 !important;padding:.4rem .6rem !important;gap:.12rem !important;justify-content:space-between;overflow:hidden;border-radius:.7rem}
    .fg-node__head{display:flex;align-items:center;gap:.25rem;min-width:0}
    .fg-node__head small{font-size:.64rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;opacity:.7}
    .fg-node__head .fl-stico{margin-left:auto;width:1.05rem;height:1.05rem}
    .fg-node__who{font-size:.68rem;opacity:.65;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .fg-node__name{font-weight:700;font-size:.92rem;line-height:1.15;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
    .fg-node__foot{font-size:.74rem !important;display:flex;gap:.3rem;align-items:baseline;white-space:nowrap}
    .fg-node__foot em{font-style:normal;font-weight:500;opacity:.7}
    .fg-ping{margin-left:auto;width:.5rem;height:.5rem;border-radius:50%;background:#0284c7;animation:fl-ping 1.5s ease-out infinite}
    .fg-par{position:absolute;z-index:3;font-size:.64rem !important;white-space:nowrap;background:rgba(2,132,199,.14) !important;color:#0369a1}
    .fg-legend{display:flex;flex-wrap:wrap;gap:.9rem;margin-top:.5rem;font-size:.68rem;opacity:.75}
    .fg-legend span{display:inline-flex;align-items:center;gap:.3rem}
    .fg-sw{display:inline-block;width:1.3rem;height:0;border-top:3px solid #94a3b8}
    .fg-sw--done{border-color:#059669}.fg-sw--idle{border-top-style:dashed}.fg-sw--loop{border-color:#7c3aed}.fg-sw--back{border-color:#d97706;border-top-style:dashed}
</style>
@endonce
