@props(['t'])
{{--
    Sơ đồ luồng THEO ĐƯỜNG ĐI THẬT của phiên (xem FlowGraph::trace). Điều phối ở cột trái, các bộ phận ở cột phải; KHÔNG có đường nối thẳng giữa các bộ phận:
    mỗi lần điều phối GIAO việc là một mũi tên tím đi sang phải, mỗi lần bộ phận BÁO KẾT QUẢ là một mũi tên xanh/cam đi về trái, tất cả đánh số theo thứ tự thật của phiên.
    Bấm một dòng bước: đặt `sel` của khung cha để mở ngăn chi tiết.
--}}
@php
    use App\Filament\Support\Agent\RunFlow;
    $repairs = collect($t['cards'])->flatMap(fn ($c) => $c['subs'])->firstWhere('code', 'repair')['count'] ?? 0;
@endphp
<div class="ft" data-testid="flow-graph" x-ref="ft"
    x-data="{ items: [], sig: '', draw() { const c = this.$refs.ft; const cr = c.getBoundingClientRect(); let E = []; try { E = JSON.parse(this.$refs.events.textContent || '[]'); } catch (e) {}
        const svg = this.$refs.arcs; const items = []; const specs = []; const co = c.querySelector('[data-coord]'); if (!co) { this.items = []; return; }
        const xr = co.getBoundingClientRect().right - cr.left; const slot = {}; const occ = {}; let back = 0;
        const card = (g) => c.querySelector('[data-card=&quot;' + g + '&quot;]');
        const mk = (d, kind, live) => { specs.push({ d: d, kind: kind, live: !!live }); };
        E.forEach((e, idx) => {
            if (e.dir === 'give' || e.dir === 'report') { const cd = card(e.card); if (!cd) return; const r = cd.getBoundingClientRect(); const xl = r.left - cr.left;
                const s = slot[e.card] = (slot[e.card] ?? -1) + 1; const y = r.top - cr.top + 50 + s * 24; const give = e.dir === 'give'; const kind = give ? 'give' : (['warn', 'failed', 'blocked'].includes(e.state) ? 'warn' : 'report');
                mk(give ? ('M' + xr + ' ' + y + 'H' + (xl - 1)) : ('M' + xl + ' ' + y + 'H' + (xr + 1)), kind, !!e.live);
                items.push({ k: idx, n: e.n, x: give ? xr + 16 : xl - 16, mx: xr + 34, y: y, label: (give ? 'giao: ' : 'báo: ') + e.label, kind: kind, live: !!e.live }); return; }
            const A = card(e.from), B = card(e.to); if (!A || !B) return; const ar = A.getBoundingClientRect(), br = B.getBoundingClientRect();
            if (e.dir === 'direct') {
                const order = [...c.querySelectorAll('[data-card]')].map(n => n.getAttribute('data-card')).filter(g => g !== 'images'); const ia = order.indexOf(e.from), ib = order.indexOf(e.to);
                if (ib === ia + 1) { const key = e.from + '>' + e.to; const o = occ[key] = (occ[key] ?? -1) + 1; const x = ar.left - cr.left + ar.width / 2 + (((o + 1) >> 1) * 30) * (o % 2 ? 1 : -1); const y1 = ar.bottom - cr.top, y2 = br.top - cr.top;
                    mk('M' + x + ' ' + y1 + 'V' + (y2 - 1), 'direct', !!e.live); items.push({ k: idx, n: e.n, x: x, y: (y1 + y2) / 2, mx: x + 30, label: null, kind: 'direct', live: !!e.live }); return; }
                /* Quay ngược hoặc nhảy cách: KHÔNG vẽ xuyên qua các thẻ ở giữa mà đi vòng ra lề phải, mỗi đường một làn riêng */
                const lane = back++; const xm = Math.max(ar.right, br.right) - cr.left + 16 + lane * 17; const up = ib < ia;
                const y1 = up ? ar.top - cr.top + 30 : ar.bottom - cr.top - 16, y2 = up ? br.bottom - cr.top - 16 : br.top - cr.top + 30;
                mk('M' + (ar.right - cr.left) + ' ' + y1 + 'H' + xm + 'V' + y2 + 'H' + (br.right - cr.left + 2), 'back'); items.push({ k: idx, n: e.n, x: xm, y: (y1 + y2) / 2, mx: xm, label: null, kind: 'back' }); return; }
            if (e.dir === 'par') { const x1 = ar.right - cr.left, x2 = br.left - cr.left, y = ar.top - cr.top + 50;
                mk('M' + x1 + ' ' + y + 'H' + (x2 - 1), 'par'); items.push({ k: idx, n: e.n, x: (x1 + x2) / 2, y: y, mx: (x1 + x2) / 2, label: null, kind: 'par', sub: e.label }); return; }
            if (e.dir === 'join') { const x1 = br.right - cr.left, xs = ar.left - cr.left + ar.width / 2, y1 = ar.bottom - cr.top, y2 = br.top - cr.top + 40;
                mk('M' + xs + ' ' + y1 + 'V' + y2 + 'H' + (x1 + 1), 'par'); items.push({ k: idx, n: e.n, x: xs, y: (y1 + y2) / 2, mx: xs, label: null, kind: 'par', sub: e.label }); } });
        /* Chỉ vẽ lại khi hình thật sự đổi (kích thước, bước mới): trang tự làm mới mỗi vài giây nên vẽ lại liên tục làm đường nháy và hiệu ứng chạy bị reset. */
        const sig = JSON.stringify([specs, items.map(i => [i.n, i.x, i.y, i.label, i.live])]);
        if (sig === this.sig && svg.querySelectorAll('.ft-arc').length === specs.length) return;
        this.sig = sig; svg.querySelectorAll('.ft-arc').forEach(n => n.remove());
        for (const sp of specs) { const el = document.createElementNS('http://www.w3.org/2000/svg', 'path'); el.setAttribute('d', sp.d); el.setAttribute('fill', 'none'); el.setAttribute('class', 'ft-arc ft-arc--' + sp.kind + (sp.live ? ' ft-arc--live' : '')); el.setAttribute('marker-end', 'url(#ft-ar-' + sp.kind + ')'); el.setAttribute('data-testid', 'flow-arc'); svg.appendChild(el); }
        this.items = items; } }"
    x-init="$nextTick(() => draw()); if (window.ResizeObserver) new ResizeObserver(() => draw()).observe($refs.ft); document.fonts && document.fonts.ready.then(() => draw()); new MutationObserver(() => $nextTick(() => draw())).observe($refs.events, { childList: true, characterData: true, subtree: true })" x-on:resize.window.debounce.100ms="draw()">
    <script type="application/json" x-ref="events" @if ($t['events'] !== []) data-testid="flow-edges" @endif>{!! json_encode($t['events'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
    @if ($repairs > 0)<span data-testid="flow-loop" class="sr-only">↻ {{ $repairs }} vòng sửa</span>@endif
    <div class="ft-overlay" wire:ignore>
    <svg class="ft-arcs" x-ref="arcs" aria-hidden="true">
        <defs>
            @foreach (['give' => '#7c3aed', 'report' => '#059669', 'warn' => '#d97706', 'direct' => '#059669', 'back' => '#059669', 'par' => '#0284c7'] as $k => $col)
                <marker id="ft-ar-{{ $k }}" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto"><path d="M0 0L10 5L0 10z" fill="{{ $col }}"/></marker>
            @endforeach
        </defs>
    </svg>
    <template x-for="it in items" :key="'b' + it.k">
        <span :class="'ft-badge ft-badge--' + it.kind + (it.live ? ' ft-badge--live' : '')" :style="'left:' + it.x + 'px;top:' + it.y + 'px'"><b x-text="it.n"></b></span>
    </template>
    <template x-for="it in items.filter(i => i.label || i.sub)" :key="'l' + it.k">
        <span :class="'ft-chip ft-chip--' + it.kind" :style="'left:' + (it.kind === 'par' ? it.x - 40 : (it.kind === 'direct' ? it.x : it.mx)) + 'px;top:' + (it.kind === 'par' ? it.y - 16 : it.y) + 'px'" x-text="it.label || it.sub"></span>
    </template>
    </div>

    <div class="ft-grid">
        <aside class="ft-coord" data-coord data-testid="flow-coordinator">
            <div class="ft-coord__in">
                <div class="ft-coord__head"><b>Điều phối</b><small>mọi việc đi qua đây</small></div>
                @forelse ($t['decisions'] as $d)
                    <div class="ft-dec" data-testid="flow-decision" title="{{ $d['full'] }}">
                        @if ($d['n'])<span class="ft-dec__n">{{ $d['n'] }}</span>@else<span class="ft-dec__n ft-dec__n--x">•</span>@endif
                        <span class="ft-dec__t">{{ $d['title'] }}</span>
                    </div>
                @empty
                    <p class="ft-coord__empty">Chưa có quyết định nào: các bước đi theo luồng mặc định.</p>
                @endforelse
                @if ($t['next'])<div class="ft-dec ft-dec--wait"><span class="fg-ping"></span><span class="ft-dec__t">Đang quyết định bước tiếp theo…</span></div>@endif
            </div>
        </aside>
        <div class="ft-gap"></div>
        @php
            $rowOf = []; $r = 0;
            foreach ($t['cards'] as $c0) { if ($c0['group'] !== 'images') { $r++; $rowOf[$c0['group']] = $r; } }
            $rowOf['images'] = $rowOf['write'] ?? ($r + 1);
        @endphp
        <div class="ft-cards">
            @foreach ($t['cards'] as $h)
                @php [$gLabel, $gIcon] = RunFlow::STATE[$h['state']]; @endphp
                <div class="ft-hop ft-hop--{{ $h['state'] }}" data-group="{{ $h['group'] }}" data-card="{{ $h['group'] }}" style="min-height:{{ $h['minh'] }}px;grid-column:{{ $h['group'] === 'images' ? 2 : 1 }};grid-row:{{ $rowOf[$h['group']] }}">
                    @if ($h['parallel'])<span class="fl-chip ft-par" data-testid="flow-parallel">∥ song song với «{{ $h['parallel'] }}»</span>@endif
                    <div class="ft-hop__head"><b>{{ $h['title'] }}</b>@if ($h['state'] === 'running')<span class="fg-run fg-run--head"><i></i>ĐANG CHẠY</span>@endif<small>{{ $h['role'] }}</small>@if ($h['visits'] > 1)<span class="ft-visits" title="Số lần điều phối giao việc cho bộ phận này">↻ {{ $h['visits'] }} lần</span>@endif<x-filament::icon :icon="$gIcon" class="fl-stico" :title="$gLabel" /></div>
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
                                @elseif ($n['state'] === 'running')<span class="fg-run"><i></i>Đang chạy</span>
                                @else<span class="fg-sub__st fl-state">{{ $stLabel }}@if ($n['count'] > 1) ×{{ $n['count'] }}@endif @if ($n['seconds'] !== null)<em>{{ $n['seconds'] }}s</em>@endif</span><x-filament::icon :icon="$stIcon" class="fl-stico" :title="$stLabel" />@endif
                            </span>
                            @if ($n['summary'])<span class="fg-sub__sum" data-testid="step-summary">{{ $n['summary'] }}</span>@endif
                        </button>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>
    @if ($t['cards'] === [])<p class="ft-empty">Phiên chưa có bước nào chạy.</p>@endif
    <div class="fg-legend"><span><i class="fg-sw fg-sw--done"></i>bước nối thẳng nhau (không cần điều phối quyết định)</span><span><i class="fg-sw fg-sw--loop"></i>điều phối giao việc</span><span><i class="fg-sw fg-sw--done"></i>bộ phận báo kết quả</span><span><i class="fg-sw fg-sw--back"></i>báo kết quả cần chú ý</span><span>Số là thứ tự thật trong phiên; quay lại một bộ phận thì thêm một cặp giao/báo, không thêm thẻ.</span></div>
</div>
@once
<style>
    .ft-overlay{position:absolute;left:0;top:0;width:100%;height:100%;pointer-events:none;z-index:2}
    .ft{position:relative;width:100%;max-width:1180px;margin:0 auto;padding:.2rem 0 .4rem}
    .ft-grid{display:grid;grid-template-columns:210px 270px minmax(0,1fr);align-items:stretch}
    .ft-arcs{position:absolute;left:0;top:0;width:100%;height:100%;pointer-events:none;overflow:visible;z-index:2}
    .ft-arc{stroke-width:2.2;stroke-linejoin:round}.ft-arc--direct{stroke:#059669}.ft-arc--back{stroke:#059669;stroke-dasharray:6 4}.ft-arc--par{stroke:#0284c7;stroke-dasharray:6 4}.ft-arc--give{stroke:#7c3aed}.ft-arc--report{stroke:#059669}.ft-arc--warn{stroke:#d97706}
    .ft-badge{position:absolute;z-index:4;transform:translate(-50%,-50%);pointer-events:none}
    .ft-badge b{min-width:1.3rem;height:1.3rem;padding:0 .25rem;border-radius:999px;color:#fff;font-size:.66rem;font-weight:800;display:inline-flex;align-items:center;justify-content:center;box-shadow:0 0 0 2px var(--fl-card,#fff)}
    .ft-badge--direct b,.ft-badge--back b{background:#059669}.ft-badge--par b{background:#0284c7}.ft-badge--give b{background:#7c3aed}.ft-badge--report b{background:#059669}.ft-badge--warn b{background:#d97706}
    .ft-chip{position:absolute;z-index:3;transform:translate(0,-50%);font-size:.66rem;font-weight:700;padding:.02rem .5rem;border-radius:999px;white-space:nowrap;background:var(--fl-card,#fff);border:1px solid rgba(100,116,139,.4);color:#475569;pointer-events:none;max-width:215px;overflow:hidden;text-overflow:ellipsis}
    .ft-chip--par{border-color:#0284c7;color:#0369a1}.ft-chip--give{border-color:#7c3aed;color:#6d28d9}.ft-chip--report{border-color:#059669;color:#047857}.ft-chip--warn{border-color:#d97706;color:#b45309}
    .dark .ft-chip{color:#cbd5e1}
    .ft-coord{position:relative;border:1.5px solid #7c3aed;border-radius:.9rem;background:linear-gradient(180deg,rgba(124,58,237,.08),transparent 30%),var(--fl-card,#fff);padding:0}
    .ft-coord__in{position:sticky;top:5rem;padding:.55rem .6rem .7rem;display:flex;flex-direction:column;gap:.3rem;max-height:calc(100vh - 7rem);overflow:auto}
    .ft-coord__head{display:flex;flex-direction:column;margin-bottom:.2rem}.ft-coord__head b{font-size:1rem;font-weight:800;color:#6d28d9}.dark .ft-coord__head b{color:#c4b5fd}.ft-coord__head small{font-size:.66rem;opacity:.65}
    .ft-coord__empty{font-size:.72rem;opacity:.7;margin:0}
    .ft-dec{display:flex;gap:.4rem;align-items:flex-start;font-size:.72rem;line-height:1.3;padding:.25rem .3rem;border-radius:.5rem;background:rgba(124,58,237,.07)}
    .ft-dec__n{flex:none;min-width:1.2rem;height:1.2rem;border-radius:999px;background:#7c3aed;color:#fff;font-size:.62rem;font-weight:800;display:inline-flex;align-items:center;justify-content:center}.ft-dec__n--x{background:rgba(124,58,237,.35)}
    .ft-dec__t{display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
    .ft-cards{display:grid;grid-template-columns:minmax(0,1fr) 215px;column-gap:5.6rem;row-gap:3.6rem;align-items:start}
    .ft-hop{position:relative;display:flex;flex-direction:column;background:var(--fl-card,#fff);border:1.5px solid var(--fl-line,rgba(100,116,139,.35));border-radius:.9rem;box-shadow:var(--fl-shadow,0 1px 3px rgba(15,23,42,.08));padding:0 .45rem .45rem}
    .ft-hop--done{border-color:#059669}.ft-hop--warn,.ft-hop--stale{border-color:#d97706}.ft-hop--failed,.ft-hop--blocked{border-color:#dc2626}.ft-hop--running{border-color:#0284c7;box-shadow:0 0 0 3px rgba(2,132,199,.18)}.ft-hop--waiting{border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.2)}
    .ft-visits{font-size:.64rem;font-weight:800;padding:.02rem .45rem;border-radius:999px;background:rgba(124,58,237,.14);color:#6d28d9;align-self:center;white-space:nowrap}
    .ft-hop__head{display:flex;align-items:baseline;gap:.5rem;height:36px;padding:.55rem .35rem 0;min-width:0}
    .ft-hop__head b{font-size:.95rem;font-weight:800;white-space:nowrap}
    .ft-hop__head small{font-size:.68rem;opacity:.65;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
    .ft-hop__head .fl-stico{margin-left:auto;width:1.2rem;height:1.2rem;align-self:center;flex:none}
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
    .fg-sw{display:inline-block;width:1.3rem;height:0;border-top:3px solid #94a3b8}.fg-sw--done{border-color:#059669}.fg-sw--loop{border-color:#7c3aed}.fg-sw--back{border-color:#d97706}

    /* Hiệu ứng ĐANG CHẠY: viền thẻ có vệt sáng chạy quanh, dòng bước nhấp nháy có nhãn, đường dẫn tới bước đang chạy có nét đứt chạy theo hướng mũi tên */
    @property --fl-a{syntax:"<angle>";inherits:false;initial-value:0deg}
    @keyframes ft-orbit{to{--fl-a:360deg}}
    @keyframes ft-flow{to{stroke-dashoffset:-20}}
    @keyframes ft-glow{0%,100%{box-shadow:0 0 0 0 rgba(2,132,199,.45)}50%{box-shadow:0 0 0 6px rgba(2,132,199,0)}}
    @keyframes ft-blink{0%,100%{opacity:1}50%{opacity:.35}}
    .ft-hop--running{border:2px solid transparent;background:linear-gradient(var(--fl-card,#fff),var(--fl-card,#fff)) padding-box,conic-gradient(from var(--fl-a),rgba(2,132,199,.12) 0,#0284c7 18%,rgba(2,132,199,.12) 40%,rgba(2,132,199,.12) 60%,#38bdf8 78%,rgba(2,132,199,.12) 100%) border-box;animation:ft-orbit 2.2s linear infinite}
    .fg-sub--running{background:rgba(2,132,199,.10);box-shadow:inset 3px 0 0 #0284c7;animation:ft-glow 1.6s ease-out infinite}
    .fg-run{margin-left:auto;display:inline-flex;align-items:center;gap:.35rem;font-size:.68rem;font-weight:800;color:#0369a1;white-space:nowrap}
    .fg-run i{width:.55rem;height:.55rem;border-radius:50%;background:#0284c7;animation:ft-blink 1s ease-in-out infinite}
    .ft-hop--running .ft-hop__head small{display:none}
    .fg-run--head{margin-left:.4rem;padding:.02rem .5rem;border-radius:999px;background:rgba(2,132,199,.14)}
    .ft-arc--live{stroke:#0284c7 !important;stroke-width:3.2;stroke-dasharray:9 7 !important;animation:ft-flow .55s linear infinite}
    .ft-badge--live b{background:#0284c7 !important;animation:ft-glow 1.4s ease-out infinite}
    @media (prefers-reduced-motion:reduce){.ft-hop--running,.fg-sub--running,.fg-run i,.ft-arc--live,.ft-badge--live b{animation:none}}
    @media (max-width:900px){.ft-cards{grid-template-columns:1fr}.ft-cards>*{grid-column:1 !important;grid-row:auto !important}.ft-grid{grid-template-columns:1fr}.ft-gap{display:none}.ft-coord__in{position:static;max-height:none}.ft-arcs,.ft-badge,.ft-chip{display:none}}
</style>
@endonce
