@props(['g'])
{{--
    Sơ đồ luồng dạng đồ thị theo làn vai trò (xem FlowGraph): ô đặt theo vị trí thật, đường nối vuông góc có nhãn (song song / nhập lại / chưa đạt / cần thêm nguồn),
    vòng Chấm ↔ Sửa. Bấm một ô: đặt `sel` của khung cha (x-data của run-flow). Tự thu nhỏ vừa khung nếu hẹp (không nhỏ hơn 70%, hẹp hơn thì cuộn ngang).
--}}
@php use App\Filament\Support\Agent\RunFlow; @endphp
<div class="fg-wrap" x-data="{ s: 1, fit() { const w = this.$refs.box.clientWidth || {{ $g['width'] }}; this.s = Math.max(0.7, Math.min(1, w / {{ $g['width'] }})); } }" x-init="fit(); if (window.ResizeObserver) new ResizeObserver(() => fit()).observe($refs.box)" x-on:resize.window.debounce.150ms="fit()" data-testid="flow-graph">
    <div x-ref="box" class="fg-scroll">
        <div class="fg-canvas" x-bind:style="'width:' + ({{ $g['width'] }} * s) + 'px;height:' + ({{ $g['height'] }} * s) + 'px'">
            <div class="fg-stage" style="width:{{ $g['width'] }}px;height:{{ $g['height'] }}px" x-bind:style="'width:{{ $g['width'] }}px;height:{{ $g['height'] }}px;transform:scale(' + s + ')'">
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
                        <span class="fg-elabel fg-elabel--{{ $e['state'] }}" style="left:{{ $e['lx'] }}px;top:{{ $e['ly'] }}px" @if ($e['kind'] === 'loop' && $e['state'] === 'loop') data-testid="flow-loop" data-loop @endif>{{ $e['label'] }}</span>
                    @endif
                @endforeach

                @foreach ($g['groups'] as $grp)
                    @php [$gLabel, $gIcon] = RunFlow::STATE[$grp['state']]; @endphp
                    <div class="fg-group fg-group--{{ $grp['state'] }}" data-group="{{ $grp['id'] }}" style="left:{{ $grp['x'] }}px;top:{{ $grp['y'] }}px;width:{{ $grp['w'] }}px;height:{{ $grp['h'] }}px">
                        <div class="fg-group__head"><b>{{ $grp['title'] }}</b><small>{{ $grp['role'] }}</small><x-filament::icon :icon="$gIcon" class="fl-stico" :title="$gLabel" /></div>
                        @if ($grp['parallel'])<span class="fl-chip fg-par" data-testid="flow-parallel" title="Chạy cùng lúc, không chờ khối này xong">∥ song song với «{{ $grp['parallel'] }}»</span>@endif
                        @foreach ($grp['subs'] as $n)
                            @php
                                [$stLabel, $stIcon] = RunFlow::STATE[$n['state']];
                                $icon = ['rule' => 'heroicon-m-cog-6-tooth', 'llm' => 'heroicon-m-sparkles', 'human' => 'heroicon-m-user'][$n['kind']] ?? 'heroicon-m-cog-6-tooth';
                                $code = $n['code'];
                            @endphp
                            <button type="button" class="fg-sub fg-sub--{{ $n['state'] }} @if ($n['state'] === 'waiting') fl-node--waiting @endif" data-stage="{{ $code }}" data-state="{{ $n['state'] }}" @if ($n['state'] === 'running') data-live-stage="{{ $code }}" @endif
                                x-on:click="sel = '{{ $code }}'" x-bind:class="{ 'is-sel': sel === '{{ $code }}' }" title="{{ $n['title'] }} · {{ $stLabel }}">
                                <x-filament::icon :icon="$icon" class="fl-ico" />
                                <span class="fg-sub__name">{{ $n['title'] }}</span>
                                @if ($n['state'] === 'waiting')<span class="fl-wait-pill" data-testid="flow-waiting">Chờ bạn</span>
                                @elseif ($n['state'] === 'running')<span class="fg-ping"></span>
                                @else<span class="fg-sub__st fl-state">{{ $stLabel }}@if ($n['count'] > 1) ×{{ $n['count'] }}@endif @if ($n['seconds'] !== null)<em>{{ $n['seconds'] }}s</em>@endif</span><x-filament::icon :icon="$stIcon" class="fl-stico" :title="$stLabel" />@endif
                            </button>
                        @endforeach
                    </div>
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
    .fg-canvas{position:relative;margin:0 auto}
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
    .fg-group{position:absolute;display:flex;flex-direction:column;background:var(--fl-card,#fff);border:1.5px solid var(--fl-line,rgba(100,116,139,.35));border-radius:.9rem;box-shadow:var(--fl-shadow,0 1px 3px rgba(15,23,42,.08));padding:0 .45rem .45rem}
    .fg-group--done{border-color:#059669}.fg-group--warn,.fg-group--stale{border-color:#d97706}.fg-group--failed,.fg-group--blocked{border-color:#dc2626}.fg-group--running{border-color:#0284c7;box-shadow:0 0 0 3px rgba(2,132,199,.18)}.fg-group--waiting{border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.2)}
    .fg-group--idle,.fg-group--pending{border-style:dashed;opacity:.65}
    .fg-group__head{display:flex;align-items:baseline;gap:.5rem;height:36px;padding:.55rem .35rem 0;min-width:0}
    .fg-group__head b{font-size:.95rem;font-weight:800;white-space:nowrap}
    .fg-group__head small{font-size:.68rem;opacity:.65;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
    .fg-group__head .fl-stico{margin-left:auto;width:1.2rem;height:1.2rem;align-self:center;flex:none}
    .fg-sub{display:flex;align-items:center;gap:.45rem;height:30px;width:100%;padding:0 .45rem;border:0;border-radius:.5rem;background:transparent;font:inherit;font-size:.82rem;color:inherit;text-align:left;cursor:pointer}
    .fg-sub:hover{background:rgba(100,116,139,.12)}.fg-sub.is-sel{background:rgba(2,132,199,.16)}
    .fg-sub__name{font-weight:600;flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .fg-sub__st{font-size:.7rem;display:inline-flex;gap:.3rem;align-items:baseline;white-space:nowrap}.fg-sub__st em{font-style:normal;font-weight:500;opacity:.7}
    .fg-sub .fl-stico{width:1rem;height:1rem;flex:none}
    .fg-sub--idle,.fg-sub--pending,.fg-sub--skipped{opacity:.55}
    .fg-sub--done .fl-stico,.fg-sub--done .fl-state{color:#059669}.fg-sub--warn .fl-stico,.fg-sub--warn .fl-state{color:#d97706}.fg-sub--failed .fl-stico,.fg-sub--failed .fl-state{color:#dc2626}
    .fg-ping{margin-left:auto;width:.5rem;height:.5rem;border-radius:50%;background:#0284c7;animation:fl-ping 1.5s ease-out infinite}
    .fg-par{position:absolute;top:-12px;left:.8rem;z-index:3;font-size:.64rem !important;white-space:nowrap;background:rgba(2,132,199,.14) !important;color:#0369a1}
    .fg-legend{display:flex;flex-wrap:wrap;gap:.9rem;margin-top:.5rem;font-size:.68rem;opacity:.75}
    .fg-legend span{display:inline-flex;align-items:center;gap:.3rem}
    .fg-sw{display:inline-block;width:1.3rem;height:0;border-top:3px solid #94a3b8}
    .fg-sw--done{border-color:#059669}.fg-sw--idle{border-top-style:dashed}.fg-sw--loop{border-color:#7c3aed}.fg-sw--back{border-color:#d97706;border-top-style:dashed}
</style>
@endonce
