@props(['t'])
{{--
    Sơ đồ luồng THEO ĐƯỜNG ĐI THẬT của phiên (xem FlowGraph::trace): chỉ chặng đã chạy hoặc đang chạy; giữa hai chặng là quyết định của người điều phối (cột trái);
    Tạo ảnh song song nằm cạnh khối Viết (cột phải). Bấm một dòng bước: đặt `sel` của khung cha để mở ngăn chi tiết.
--}}
@php use App\Filament\Support\Agent\RunFlow; @endphp
<div class="ft" data-testid="flow-graph" x-ref="ft"
    x-data="{ paths: [], draw() { const c = this.$refs.ft; const cr = c.getBoundingClientRect(); const out = []; let E = []; try { E = JSON.parse(this.$refs.edges.textContent || '[]'); } catch (e) {}
        for (const e of E) { const a = c.querySelector('[data-card=&quot;' + e.from + '&quot;]'), b = c.querySelector('[data-card=&quot;' + e.to + '&quot;]'); if (!a || !b) continue;
            const ar = a.getBoundingClientRect(), br = b.getBoundingClientRect(); const x1 = ar.right - cr.left, x2 = br.right - cr.left; const xm = Math.max(x1, x2) + 18 + e.lane * 24;
            const y1 = ar.top - cr.top + ar.height * 0.62, y2 = br.top - cr.top + br.height * 0.78;
            out.push({ d: 'M' + x1 + ' ' + y1 + 'H' + xm + 'V' + y2 + 'H' + (x2 + 2), lx: xm, ly: (y1 + y2) / 2, label: e.label, kind: e.kind, k: e.from + '>' + e.to }); }
        this.paths = out;
        const svg = this.$refs.arcs; svg.querySelectorAll('.ft-arc').forEach(n => n.remove());
        for (const p of out) { const el = document.createElementNS('http://www.w3.org/2000/svg', 'path'); el.setAttribute('d', p.d); el.setAttribute('fill', 'none'); el.setAttribute('class', 'ft-arc ft-arc--' + p.kind); el.setAttribute('marker-end', 'url(#ft-ar-' + p.kind + ')'); el.setAttribute('data-testid', 'flow-arc'); svg.appendChild(el); } } }"
    x-init="$nextTick(() => draw()); if (window.ResizeObserver) new ResizeObserver(() => draw()).observe($refs.ft)" x-on:resize.window.debounce.100ms="draw()">
    <script type="application/json" x-ref="edges" @if ($t['back'] !== []) data-testid="flow-loop" @endif>{!! json_encode($t['back'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
    <svg class="ft-arcs" x-ref="arcs" aria-hidden="true">
        <defs>
            <marker id="ft-ar-loop" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="8" markerHeight="8" orient="auto"><path d="M0 0L10 5L0 10z" fill="#7c3aed"/></marker>
            <marker id="ft-ar-back" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="8" markerHeight="8" orient="auto"><path d="M0 0L10 5L0 10z" fill="#d97706"/></marker>
        </defs>
    </svg>
    <template x-for="p in paths" :key="'l' + p.k"><span :class="'ft-arclabel ft-arclabel--' + p.kind" :style="'left:' + p.lx + 'px;top:' + p.ly + 'px'" x-text="p.label"></span></template>

    @foreach ($t['cards'] as $i => $h)
        @php [$gLabel, $gIcon] = RunFlow::STATE[$h['state']]; @endphp
        @if ($i > 0)
            <div class="ft-row ft-row--edge"><div></div>
                <div class="ft-link ft-link--main @if ($h['state'] === 'running') ft-link--live @endif">@if ($h['in']['label'])<span class="ft-elabel">{{ $h['in']['label'] }}</span>@endif</div>
                <div></div></div>
        @endif
        <div class="ft-row">
            <div class="ft-dec">
                @foreach ($h['decisions'] as $d)
                    <div class="ft-card" data-testid="flow-decision"><b>{{ $d['title'] }}</b>@if ($d['detail'])<p>{{ $d['detail'] }}</p>@endif @if ($d['routes'])<span class="ft-routes">@foreach ($d['routes'] as $r)<i>→ {{ $r }}</i>@endforeach</span>@endif</div>
                @endforeach
            </div>
            <div class="ft-hop ft-hop--{{ $h['state'] }}" data-group="{{ $h['group'] }}" data-card="{{ $h['group'] }}">
                <div class="ft-hop__head"><b>{{ $h['title'] }}</b><small>{{ $h['role'] }}</small>@if ($h['visits'] > 1)<span class="ft-visits" title="Số lần phiên này đi vào khối">↻ {{ $h['visits'] }} lần</span>@endif<x-filament::icon :icon="$gIcon" class="fl-stico" :title="$gLabel" /></div>
                @foreach ($h['subs'] as $n)
                    @php
                        [$stLabel, $stIcon] = RunFlow::STATE[$n['state']];
                        $icon = ['rule' => 'heroicon-m-cog-6-tooth', 'llm' => 'heroicon-m-sparkles', 'human' => 'heroicon-m-user'][$n['kind']] ?? 'heroicon-m-cog-6-tooth';
                        $code = $n['code'];
                    @endphp
                    <button type="button" class="fg-sub fg-sub--{{ $n['state'] }} @if ($n['state'] === 'waiting') fl-node--waiting @endif" data-stage="{{ $code }}" data-state="{{ $n['state'] }}" @if ($n['state'] === 'running') data-live-stage="{{ $code }}" @endif
                        x-on:click="sel = '{{ $code }}'" x-bind:class="{ 'is-sel': sel === '{{ $code }}' }" title="{{ $n['title'] }} · {{ $stLabel }}">
                        <span class="fg-sub__top">
                            <x-filament::icon :icon="$icon" class="fl-ico" />
                            <span class="fg-sub__name">{{ $n['title'] }}</span>
                            @if ($n['state'] === 'waiting')<span class="fl-wait-pill" data-testid="flow-waiting">Chờ bạn</span>
                            @elseif ($n['state'] === 'running')<span class="fg-ping"></span>
                            @else<span class="fg-sub__st fl-state">{{ $stLabel }}@if ($n['count'] > 1) ×{{ $n['count'] }}@endif @if ($n['seconds'] !== null)<em>{{ $n['seconds'] }}s</em>@endif</span><x-filament::icon :icon="$stIcon" class="fl-stico" :title="$stLabel" />@endif
                        </span>
                        @if ($n['summary'])<span class="fg-sub__sum" data-testid="step-summary">{{ $n['summary'] }}</span>@endif
                    </button>
                @endforeach
            </div>
            <div class="ft-side">
                @if ($h['side'])
                    @php $sd = $h['side']; $n = $sd['subs'][0]; [$stLabel, $stIcon] = RunFlow::STATE[$n['state']]; @endphp
                    <span class="ft-hlink"></span>
                    <div class="ft-hop ft-hop--{{ $sd['state'] }}" data-group="images" data-card="images">
                        <span class="fl-chip ft-par" data-testid="flow-parallel">∥ song song với «{{ $sd['parallel'] }}»</span>
                        <div class="ft-hop__head"><b>{{ $sd['title'] }}</b><small>{{ $sd['role'] }}</small></div>
                        <button type="button" class="fg-sub fg-sub--{{ $n['state'] }}" data-stage="{{ $n['code'] }}" data-state="{{ $n['state'] }}" x-on:click="sel = '{{ $n['code'] }}'" x-bind:class="{ 'is-sel': sel === '{{ $n['code'] }}' }">
                            <span class="fg-sub__top"><x-filament::icon icon="heroicon-m-cog-6-tooth" class="fl-ico" /><span class="fg-sub__name">{{ $n['title'] }}</span>
                            <span class="fg-sub__st fl-state">{{ $stLabel }}@if ($n['seconds'] !== null) <em>{{ $n['seconds'] }}s</em>@endif</span><x-filament::icon :icon="$stIcon" class="fl-stico" :title="$stLabel" /></span>
                            @if ($n['summary'])<span class="fg-sub__sum" data-testid="step-summary">{{ $n['summary'] }}</span>@endif
                        </button>
                    </div>
                @endif
            </div>
        </div>
    @endforeach
    @if ($t['trailing'])
        <div class="ft-row ft-row--edge"><div class="ft-dec">@foreach ($t['trailing'] as $d)<div class="ft-card"><b>{{ $d['title'] }}</b>@if ($d['detail'])<p>{{ $d['detail'] }}</p>@endif</div>@endforeach</div><div class="ft-link ft-link--main"></div><div></div></div>
    @endif
    @if ($t['next'])
        <div class="ft-row ft-row--edge"><div></div><div class="ft-link ft-link--live"></div><div></div></div>
        <div class="ft-row"><div></div><div class="ft-hop ft-hop--pending ft-hop--next">Điều phối đang quyết định bước tiếp theo…</div><div></div></div>
    @endif
    @if ($t['cards'] === [])<p class="ft-empty">Phiên chưa có bước nào chạy.</p>@endif
    <div class="fg-legend"><span><i class="fg-sw fg-sw--done"></i>đã đi</span><span><i class="fg-sw fg-sw--loop"></i>quay lại theo quyết định điều phối</span><span><i class="fg-sw fg-sw--back"></i>quay lại bổ sung nguồn</span><span>Chỉ hiện chặng đã chạy; quay lại một khối thì tăng số lần chứ không thêm thẻ.</span></div>
</div>
@once
<style>
    .ft{position:relative;width:100%;max-width:1160px;margin:0 auto;padding:.2rem 0 .4rem}
    .ft-arcs{position:absolute;left:0;top:0;width:100%;height:100%;pointer-events:none;overflow:visible;z-index:2}
    .ft-arc{stroke-width:2.5;stroke-linejoin:round}.ft-arc--loop{stroke:#7c3aed}.ft-arc--back{stroke:#d97706;stroke-dasharray:7 5}
    .ft-arclabel{position:absolute;z-index:3;transform:translate(10px,-50%);font-size:.68rem;font-weight:700;padding:.1rem .5rem;border-radius:999px;white-space:nowrap;background:#7c3aed;color:#fff;box-shadow:0 1px 4px rgba(0,0,0,.18)}
    .ft-arclabel--back{background:#fff;color:#b45309;border:1.5px solid #d97706}
    .ft-visits{font-size:.64rem;font-weight:800;padding:.02rem .45rem;border-radius:999px;background:rgba(124,58,237,.14);color:#6d28d9;align-self:center;white-space:nowrap}
    .ft-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,360px) minmax(0,1fr);column-gap:1.6rem;align-items:start}
    .ft-row--edge{min-height:46px;align-items:center}
    .ft-link{position:relative;justify-self:center;width:3px;height:100%;min-height:46px;background:#059669;border-radius:2px}
    .ft-link::after{content:"";position:absolute;left:50%;bottom:-1px;transform:translateX(-50%);border:6px solid transparent;border-top:8px solid #059669;border-bottom:0}
    .ft-link--loop{background:#7c3aed}.ft-link--loop::after{border-top-color:#7c3aed}
    .ft-link--back{background:repeating-linear-gradient(180deg,#d97706 0 6px,transparent 6px 10px)}.ft-link--back::after{border-top-color:#d97706}
    .ft-link--live{background:repeating-linear-gradient(180deg,#0284c7 0 7px,rgba(2,132,199,.25) 7px 14px);background-size:100% 14px;animation:ft-march .7s linear infinite}.ft-link--live::after{border-top-color:#0284c7}
    @keyframes ft-march{to{background-position:0 14px}}
    @media (prefers-reduced-motion:reduce){.ft-link--live{animation:none}}
    .ft-elabel{position:absolute;left:14px;top:50%;transform:translateY(-50%);font-size:.7rem;font-weight:700;padding:.05rem .5rem;border-radius:999px;background:var(--fl-card,#fff);border:1px solid rgba(100,116,139,.4);white-space:nowrap;color:#475569}
    .ft-link--loop .ft-elabel{background:#7c3aed;border-color:#7c3aed;color:#fff}.ft-link--back .ft-elabel{border-color:#d97706;color:#b45309}
    .ft-dec{display:flex;flex-direction:column;gap:.4rem;justify-self:end;max-width:340px;position:relative}
    .ft-dec:not(:empty)::after{content:"";position:absolute;right:-1.6rem;top:50%;width:1.6rem;border-top:2px dotted #7c3aed}
    .ft-card{border:1.5px solid #7c3aed;border-radius:.7rem;padding:.45rem .65rem;background:linear-gradient(180deg,rgba(124,58,237,.08),transparent 70%),var(--fl-card,#fff);font-size:.74rem;line-height:1.3}
    .ft-card b{color:#6d28d9;font-size:.78rem}.dark .ft-card b{color:#c4b5fd}
    .ft-card p{margin:.2rem 0 0;opacity:.8}
    .ft-routes{display:flex;flex-wrap:wrap;gap:.3rem;margin-top:.3rem}.ft-routes i{font-style:normal;font-weight:700;font-size:.66rem;padding:.03rem .45rem;border-radius:999px;background:rgba(124,58,237,.14);color:#6d28d9}
    .ft-hop{position:relative;display:flex;flex-direction:column;background:var(--fl-card,#fff);border:1.5px solid var(--fl-line,rgba(100,116,139,.35));border-radius:.9rem;box-shadow:var(--fl-shadow,0 1px 3px rgba(15,23,42,.08));padding:0 .45rem .45rem}
    .ft-hop--done{border-color:#059669}.ft-hop--warn,.ft-hop--stale{border-color:#d97706}.ft-hop--failed,.ft-hop--blocked{border-color:#dc2626}.ft-hop--running{border-color:#0284c7;box-shadow:0 0 0 3px rgba(2,132,199,.18)}.ft-hop--waiting{border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.2)}
    .ft-hop--pending{border-style:dashed;opacity:.7;padding:.9rem;font-size:.8rem;text-align:center}
    .ft-hop__head{display:flex;align-items:baseline;gap:.5rem;height:36px;padding:.55rem .35rem 0;min-width:0}
    .ft-hop__head b{font-size:.95rem;font-weight:800;white-space:nowrap}
    .ft-hop__head small{font-size:.68rem;opacity:.65;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
    .ft-hop__head .fl-stico{margin-left:auto;width:1.2rem;height:1.2rem;align-self:center;flex:none}
    .ft-side{position:relative;display:flex;align-items:flex-start;gap:0;padding-left:3.4rem}.ft-side .ft-hop{flex:1 1 auto;min-width:0;max-width:250px}
    .ft-hlink{position:absolute;left:-1.6rem;width:5rem;margin-top:1.1rem;border-top:3px solid #059669;position:relative}.ft-hlink::after{content:"";position:absolute;right:-1px;top:-7px;border:6px solid transparent;border-left:8px solid #059669;border-right:0}
    .ft-par{position:absolute;top:-12px;left:.8rem;z-index:3;font-size:.64rem !important;white-space:nowrap;background:rgba(2,132,199,.14) !important;color:#0369a1}
    .ft-empty{text-align:center;opacity:.7;font-size:.85rem}
    .fg-sub{display:flex;flex-direction:column;align-items:stretch;gap:.1rem;min-height:30px;width:100%;padding:0 .45rem;border:0;border-radius:.5rem;background:transparent;font:inherit;font-size:.82rem;color:inherit;text-align:left;cursor:pointer}
    .fg-sub__top{display:flex;align-items:center;gap:.45rem;min-height:30px}
    .fg-sub__sum{font-size:.7rem;line-height:1.3;opacity:.72;padding:0 .15rem .3rem 1.5rem;white-space:normal}
    .fg-sub:hover{background:rgba(100,116,139,.12)}.fg-sub.is-sel{background:rgba(2,132,199,.16)}
    .fg-sub__name{font-weight:600;flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .fg-sub__st{font-size:.7rem;display:inline-flex;gap:.3rem;align-items:baseline;white-space:nowrap}.fg-sub__st em{font-style:normal;font-weight:500;opacity:.7}
    .fg-sub .fl-stico{width:1rem;height:1rem;flex:none}
    .fg-sub--done .fl-stico,.fg-sub--done .fl-state{color:#059669}.fg-sub--warn .fl-stico,.fg-sub--warn .fl-state{color:#d97706}.fg-sub--failed .fl-stico,.fg-sub--failed .fl-state{color:#dc2626}
    .fg-ping{margin-left:auto;width:.5rem;height:.5rem;border-radius:50%;background:#0284c7;animation:fl-ping 1.5s ease-out infinite}
    .fg-legend{display:flex;flex-wrap:wrap;gap:.9rem;margin-top:.8rem;font-size:.68rem;opacity:.75}
    .fg-legend span{display:inline-flex;align-items:center;gap:.3rem}
    .fg-sw{display:inline-block;width:1.3rem;height:0;border-top:3px solid #94a3b8}.fg-sw--done{border-color:#059669}.fg-sw--loop{border-color:#7c3aed}.fg-sw--back{border-color:#d97706;border-top-style:dashed}
    @media (max-width:760px){.ft-row{grid-template-columns:1fr}.ft-dec{justify-self:stretch;max-width:none}.ft-dec:not(:empty)::after{display:none}.ft-link{min-height:30px}.ft-side .ft-hop{max-width:none}}
</style>
@endonce
