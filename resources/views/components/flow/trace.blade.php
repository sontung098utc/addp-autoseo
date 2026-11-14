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
<div class="ft" data-testid="flow-graph">
    @if ($repairs > 0)<span data-testid="flow-loop" class="sr-only">↻ {{ $repairs }} vòng sửa</span>@endif
    @php
        $cards = collect($t['cards'])->keyBy('group');
        $order = ['prep', 'sources', 'strategy', 'write', 'review', 'finish', 'checkpoint'];
        $rowsList = [];
        foreach ($order as $g) {
            if (! $cards->has($g)) { continue; }
            $row = [$cards[$g]];
            if ($g === 'write' && $cards->has('images')) { $row[] = $cards['images']; }
            $rowsList[] = $row;
        }
        if (! $cards->has('write') && $cards->has('images')) { $rowsList[] = [$cards['images']]; }
    @endphp

    <details class="ft-coordbox" data-testid="flow-coordinator" @if (count($t['decisions']) > 0) open @endif>
        <summary><b>Điều phối</b> điều khiển cả phiên · đã quyết định {{ count($t['decisions']) }} việc</summary>
        @forelse ($t['decisions'] as $d)
            <p data-testid="flow-decision" title="{{ $d['full'] }}">{{ $d['title'] }}</p>
        @empty
            <p class="ft-muted">Chưa có quyết định nào: các bước đi theo luồng mặc định.</p>
        @endforelse
        @if ($t['next'])<p class="ft-wait"><span class="fg-ping"></span> Đang quyết định bước tiếp theo…</p>@endif
    </details>

    @foreach ($rowsList as $ri => $row)
        @if ($ri > 0)<div class="ft-down" aria-hidden="true">↓</div>@endif
        <div class="ft-row @if (count($row) > 1) ft-row--two @endif">
            @foreach ($row as $h)
                @php [$gLabel, $gIcon] = RunFlow::STATE[$h['state']]; @endphp
                <div class="ft-hop ft-hop--{{ $h['state'] }}" data-group="{{ $h['group'] }}" data-card="{{ $h['group'] }}">
                    @if ($h['parallel'])<span class="fl-chip ft-par" data-testid="flow-parallel">∥ chạy cùng lúc với «{{ $h['parallel'] }}»</span>@endif
                    <div class="ft-hop__head"><b>{{ $h['title'] }}</b><small>{{ $h['role'] }}</small><x-filament::icon :icon="$gIcon" class="fl-stico" :title="$gLabel" /></div>
                    @if (count($h['rounds']) > 1)
                        <div class="ft-rounds" title="Điều phối giao việc cho bộ phận này {{ count($h['rounds']) }} lần">
                            <span class="ft-rounds__h">↻ Làm {{ count($h['rounds']) }} lần</span>
                            @foreach ($h['rounds'] as $k => $r)
                                <span class="ft-round ft-round--{{ $r['state'] }}"><i>{{ $k + 1 }}</i>{{ $r['ask'] }}@if ($r['result']) → <b>{{ $r['result'] }}</b>@endif</span>
                            @endforeach
                        </div>
                    @endif
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
            @endforeach
        </div>
    @endforeach
    @if ($t['cards'] === [])<p class="ft-empty">Phiên chưa có bước nào chạy.</p>@endif
</div>
@once
<style>
    .ft{position:relative;width:100%;max-width:980px;margin:0 auto;padding:.2rem 0 .4rem}
    .ft-coordbox{border:1.5px solid #7c3aed;border-radius:.8rem;padding:.5rem .8rem;margin-bottom:.8rem;background:linear-gradient(180deg,rgba(124,58,237,.07),transparent),var(--fl-card,#fff);font-size:.8rem}
    .ft-coordbox summary{cursor:pointer;list-style:none}.ft-coordbox summary b{color:#6d28d9;font-size:.9rem;margin-right:.4rem}.dark .ft-coordbox summary b{color:#c4b5fd}
    .ft-coordbox p{margin:.3rem 0 0;padding-left:.7rem;border-left:3px solid #c4b5fd;line-height:1.35}
    .ft-muted{opacity:.7}.ft-wait{display:flex;gap:.4rem;align-items:center}
    .ft-down{text-align:center;color:#059669;font-size:1.3rem;font-weight:800;line-height:1.5}
    .ft-row{display:grid;grid-template-columns:1fr;gap:1rem}
    .ft-row--two{grid-template-columns:minmax(0,1.3fr) minmax(0,1fr)}
    @media (max-width:760px){.ft-row--two{grid-template-columns:1fr}}
    .ft-rounds{display:flex;flex-direction:column;gap:.25rem;margin:.1rem .3rem .5rem;padding:.4rem .5rem;border-radius:.6rem;background:rgba(124,58,237,.07);font-size:.74rem}
    .ft-rounds__h{font-weight:800;color:#6d28d9}.dark .ft-rounds__h{color:#c4b5fd}
    .ft-round{display:flex;gap:.4rem;align-items:flex-start;line-height:1.3}
    .ft-round i{font-style:normal;flex:none;min-width:1.15rem;height:1.15rem;border-radius:999px;background:#7c3aed;color:#fff;font-size:.62rem;font-weight:800;display:inline-flex;align-items:center;justify-content:center}
    .ft-round--done b{color:#047857}.ft-round--warn b,.ft-round--failed b{color:#b45309}
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
</style>
@endonce
