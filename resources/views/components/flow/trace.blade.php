@props(['t'])
{{--
    Sơ đồ luồng của phiên, vẽ GỌN để nhìn một lần là hiểu: mỗi bộ phận một ô nhỏ, mũi tên nối thẳng giữa các ô; chỉ khi người điều phối ra quyết định thì ô đó mới có đường nối sang khối Điều phối (cột trái).
    Tạo ảnh chạy cùng lúc nằm bên phải, nhập lại ở Hoàn tất. Quay lại nhiều lần thì hiện số lần chứ không vẽ thêm đường; chi tiết từng lần nằm ở "Diễn biến theo thứ tự" (có số) bên dưới.
    Dữ liệu từ FlowGraph::trace. Bấm một ô hoặc một dòng bước: đặt `sel` của khung cha để mở ngăn chi tiết.
--}}
@php
    use App\Filament\Support\Agent\RunFlow;
    $cards = collect($t['cards'])->keyBy('group');
    $title = $cards->map(fn ($c) => $c['title'])->all();
    $repairs = collect($t['cards'])->flatMap(fn ($c) => $c['subs'])->firstWhere('code', 'repair')['count'] ?? 0;
    $main = collect($t['cards'])->reject(fn ($c) => $c['group'] === 'images')->values();
    $hasImages = $cards->has('images');
    $ev = collect($t['events']);
    $gives = $ev->where('dir', 'give')->groupBy('card')->map->count();
    $passes = $ev->where('dir', 'direct')->groupBy(fn ($e) => $e['from'].'>'.$e['to'])->map(fn ($g) => $g->pluck('n')->all());
    $viaCoord = $ev->where('dir', 'give')->groupBy('card')->map(fn ($g) => $g->pluck('n')->all());
    $writeRow = $main->search(fn ($c) => $c['group'] === 'write');
    $imgRow = $hasImages ? ($writeRow !== false ? $writeRow : 0) : null;
    $finRow = $main->search(fn ($c) => $c['group'] === 'finish');
    $rows = $main->count();
    $stepLine = function ($e) use ($title) {
        $card = $title[$e['card'] ?? ''] ?? '';
        return match ($e['dir']) {
            'direct' => ['kind' => 'direct', 'text' => ($title[$e['from']] ?? $e['from']).' → '.($title[$e['to']] ?? $e['to'])],
            'give' => ['kind' => 'give', 'text' => 'Điều phối giao «'.$card.'»: '.$e['label']],
            'report' => ['kind' => $e['state'] === 'done' ? 'report' : 'warn', 'text' => $card.' báo lại: '.$e['label']],
            'par' => ['kind' => 'par', 'text' => 'Tạo ảnh bắt đầu chạy cùng lúc với Viết'],
            default => ['kind' => 'par', 'text' => 'Ảnh được ghép vào bài khi lắp ráp'],
        };
    };
@endphp
<div class="ft" data-testid="flow-graph">
    @if ($repairs > 0)<span data-testid="flow-loop" class="sr-only">↻ {{ $repairs }} vòng sửa</span>@endif

    <div class="fd">
        <aside class="fd-coord" data-coord data-testid="flow-coordinator" style="grid-row:1 / span {{ max($rows * 2 - 1, 1) }}">
            <b>Điều phối</b>
            <small>{{ count($t['decisions']) }} quyết định trong phiên</small>
            @if ($t['next'])<span class="fd-wait"><span class="fg-ping"></span> đang quyết định bước tiếp…</span>@endif
        </aside>

        @foreach ($main as $i => $h)
            @php
                [$gLabel, $gIcon] = RunFlow::STATE[$h['state']];
                $sec = collect($h['subs'])->sum(fn ($s) => $s['seconds'] ?? 0);
                $row = $i * 2 + 1;
                $g = $gives[$h['group']] ?? 0;
                $first = $h['subs'][0]['code'] ?? null;
            @endphp
            @if ($g > 0)
                <div class="fd-link" style="grid-column:2;grid-row:{{ $row }}" title="Điều phối giao việc / nhận báo cáo: bước số {{ implode(', ', $viaCoord[$h['group']] ?? []) }}">
                    <span class="fd-link__line"></span><span class="fd-link__chip">⚑ {{ $g }} lần</span>
                </div>
            @endif
            <button type="button" class="fd-node fd-node--{{ $h['state'] }}" data-group="{{ $h['group'] }}" data-card="{{ $h['group'] }}" style="grid-column:3;grid-row:{{ $row }}" @if ($first) x-on:click="sel = '{{ $first }}'" @endif title="{{ $h['title'] }} · {{ $gLabel }}">
                <span class="fd-node__t"><b>{{ $h['title'] }}</b><small>{{ $h['role'] }}</small></span>
                <span class="fd-node__m">
                    @if ($h['visits'] > 1)<i class="fd-visits" title="Số lần phiên này vào bộ phận này">↻ {{ $h['visits'] }}</i>@endif
                    @if ($sec > 0)<em>{{ round($sec) }}s</em>@endif
                    @if ($h['state'] === 'running')<span class="fg-ping"></span>@else<x-filament::icon :icon="$gIcon" class="fl-stico" :title="$gLabel" />@endif
                </span>
            </button>
            @if ($i < $rows - 1)
                @php
                    $next = $main[$i + 1];
                    $nums = $passes[$h['group'].'>'.$next['group']] ?? [];
                    $cnt = count($nums);
                @endphp
                <div class="fd-arrow @if ($cnt === 0) fd-arrow--idle @endif" style="grid-column:3;grid-row:{{ $row + 1 }}" @if ($cnt) title="Nối thẳng: bước số {{ implode(', ', $nums) }}" @endif>
                    <span class="fd-arrow__line"></span>
                    @if ($next['group'] === 'finish')<span class="fd-arrow__chip">đạt</span>@elseif ($cnt > 1)<span class="fd-arrow__chip">×{{ $cnt }}</span>@endif
                </div>
            @endif
        @endforeach

        @if ($hasImages)
            @php $im = $cards['images']; [$iLabel, $iIcon] = RunFlow::STATE[$im['state']]; $ir = $imgRow * 2 + 1; $isec = collect($im['subs'])->sum(fn ($s) => $s['seconds'] ?? 0); @endphp
            <div class="fd-par" style="grid-column:4;grid-row:{{ $ir }}"><span>∥</span></div>
            <button type="button" class="fd-node fd-node--{{ $im['state'] }} fd-node--img" data-group="images" data-card="images" style="grid-column:5;grid-row:{{ $ir }}" x-on:click="sel = '{{ $im['subs'][0]['code'] ?? 'images' }}'">
                <span class="fl-chip fd-chip" data-testid="flow-parallel">∥ chạy cùng lúc với «{{ $im['parallel'] }}»</span>
                <span class="fd-node__t"><b>{{ $im['title'] }}</b><small>{{ $im['role'] }}</small></span>
                <span class="fd-node__m">@if ($isec > 0)<em>{{ round($isec) }}s</em>@endif<x-filament::icon :icon="$iIcon" class="fl-stico" :title="$iLabel" /></span>
            </button>
            @if ($finRow !== false && $finRow > $imgRow)
                <div class="fd-join" style="grid-column:5;grid-row:{{ $ir + 1 }} / {{ $finRow * 2 + 2 }}"></div>
                <div class="fd-hjoin" style="grid-column:4;grid-row:{{ $finRow * 2 + 1 }}"><span>ảnh ghép vào bài</span></div>
            @endif
        @endif
    </div>
    <p class="fd-legend"><span class="fd-key fd-key--g"></span>nối thẳng, không cần điều phối quyết định &nbsp; <span class="fd-key fd-key--p"></span>điều phối quyết định rồi giao việc &nbsp; <span class="fd-key fd-key--b"></span>chạy cùng lúc</p>

    <details class="ft-sec" data-testid="flow-decisions" @if (count($t['decisions']) > 0) open @endif>
        <summary>Điều phối đã quyết định gì <span>{{ count($t['decisions']) }}</span></summary>
        @forelse ($t['decisions'] as $d)
            <p data-testid="flow-decision" title="{{ $d['full'] }}">@if ($d['n'])<i>#{{ $d['n'] }}</i>@endif {{ $d['title'] }}</p>
        @empty
            <p class="ft-muted">Chưa có quyết định nào: các bước đi theo luồng mặc định.</p>
        @endforelse
    </details>

    <details class="ft-sec" data-testid="flow-timeline">
        <summary>Diễn biến theo thứ tự <span>{{ $ev->count() + 1 }} bước</span></summary>
        <ol class="ft-tl">
            @if ($main->isNotEmpty())<li class="ft-tl--start"><i>•</i>Bắt đầu: {{ $main[0]['title'] }}</li>@endif
            @foreach ($ev as $e)
                @php $l = $stepLine($e); @endphp
                <li class="ft-tl--{{ $l['kind'] }}"><i>{{ $e['n'] }}</i>{{ $l['text'] }}</li>
            @endforeach
        </ol>
    </details>

    <h4 class="ft-h4">Kết quả từng bước</h4>
    <div class="ft-results">
        @foreach ($t['cards'] as $h)
            @php [$gLabel, $gIcon] = RunFlow::STATE[$h['state']]; @endphp
            <div class="ft-hop ft-hop--{{ $h['state'] }}" data-group="{{ $h['group'] }}">
                @if ($h['parallel'])<span class="fl-chip ft-par">∥ chạy cùng lúc với «{{ $h['parallel'] }}»</span>@endif
                <div class="ft-hop__head"><b>{{ $h['title'] }}</b><small>{{ $h['role'] }}</small><x-filament::icon :icon="$gIcon" class="fl-stico" :title="$gLabel" /></div>
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
    @if ($t['cards'] === [])<p class="ft-empty">Phiên chưa có bước nào chạy.</p>@endif
</div>
@once
<style>
    .ft{width:100%;max-width:980px;margin:0 auto;padding:.2rem 0 .4rem}
    /* Sơ đồ gọn */
    .fd{display:grid;grid-template-columns:150px 78px minmax(0,360px) 64px 210px;justify-content:center;align-items:center;padding:.4rem 0}
    .fd-coord{display:flex;flex-direction:column;align-items:center;justify-content:flex-start;gap:.2rem;padding:.8rem .4rem;border:1.5px solid #7c3aed;border-radius:.9rem;background:linear-gradient(180deg,rgba(124,58,237,.10),rgba(124,58,237,.03));grid-column:1;text-align:center;align-self:stretch}
    .fd-coord b{color:#6d28d9;font-size:1rem;font-weight:800}.dark .fd-coord b{color:#c4b5fd}.fd-coord small{font-size:.68rem;opacity:.7}
    .fd-wait{margin-top:.5rem;display:flex;gap:.3rem;align-items:center;font-size:.66rem}
    .fd-link{display:flex;align-items:center;justify-content:center;position:relative;height:100%}
    .fd-link__line{position:absolute;left:0;right:0;top:50%;border-top:2.5px solid #7c3aed}
    .fd-link__line::before,.fd-link__line::after{content:"";position:absolute;top:-6px;border:6px solid transparent}
    .fd-link__line::before{left:-1px;border-right:8px solid #7c3aed;border-left:0}.fd-link__line::after{right:-1px;border-left:8px solid #7c3aed;border-right:0}
    .fd-link__chip{position:relative;z-index:1;font-size:.62rem;font-weight:800;padding:.05rem .4rem;border-radius:999px;background:#7c3aed;color:#fff;white-space:nowrap}
    .fd-node{display:flex;align-items:center;justify-content:space-between;gap:.5rem;padding:.55rem .75rem;border-radius:.8rem;border:1.8px solid #94a3b8;background:var(--fl-card,#fff);box-shadow:var(--fl-shadow,0 1px 3px rgba(15,23,42,.1));font:inherit;text-align:left;color:inherit;cursor:pointer;min-height:54px}
    .fd-node:hover{box-shadow:0 0 0 3px rgba(2,132,199,.25)}
    .fd-node__t{display:flex;flex-direction:column;min-width:0}.fd-node__t b{font-size:.92rem;font-weight:800}.fd-node__t small{font-size:.66rem;opacity:.65}
    .fd-node__m{display:flex;align-items:center;gap:.4rem;flex:none}.fd-node__m em{font-style:normal;font-size:.68rem;opacity:.7}.fd-node__m .fl-stico{width:1.25rem;height:1.25rem}
    .fd-visits{font-style:normal;font-size:.66rem;font-weight:800;padding:.02rem .4rem;border-radius:999px;background:rgba(124,58,237,.15);color:#6d28d9}
    .fd-node--done{border-color:#059669;background:linear-gradient(180deg,rgba(5,150,105,.09),transparent),var(--fl-card,#fff)}
    .fd-node--warn,.fd-node--stale{border-color:#d97706;background:linear-gradient(180deg,rgba(217,119,6,.10),transparent),var(--fl-card,#fff)}
    .fd-node--failed,.fd-node--blocked{border-color:#dc2626}.fd-node--running{border-color:#0284c7;box-shadow:0 0 0 3px rgba(2,132,199,.2)}
    .fd-node--img{position:relative;border-style:dashed;border-color:#0284c7;padding-top:.8rem}
    .fd-chip{position:absolute;top:-10px;left:.6rem;font-size:.6rem !important;background:rgba(2,132,199,.15) !important;color:#0369a1;white-space:nowrap}
    .fd-arrow{position:relative;height:30px;display:flex;justify-content:center}
    .fd-arrow__line{width:0;height:100%;border-left:2.5px solid #059669;position:relative}
    .fd-arrow__line::after{content:"";position:absolute;left:-7px;bottom:-1px;border:6px solid transparent;border-top:8px solid #059669;border-bottom:0}
    .fd-arrow--idle .fd-arrow__line{border-left-style:dashed;border-color:#94a3b8}.fd-arrow--idle .fd-arrow__line::after{border-top-color:#94a3b8}
    .fd-arrow__chip{position:absolute;left:calc(50% + 12px);top:50%;transform:translateY(-50%);font-size:.66rem;font-weight:800;padding:.02rem .45rem;border-radius:999px;background:#059669;color:#fff}
    .fd-par{display:flex;align-items:center;justify-content:center;height:100%;position:relative}
    .fd-par::before{content:"";position:absolute;left:0;right:0;top:50%;border-top:2.5px dashed #0284c7}.fd-par span{position:relative;background:var(--fl-card,#fff);color:#0284c7;font-weight:800;padding:0 .2rem}
    .fd-join{justify-self:center;width:0;border-left:2.5px dashed #0284c7;height:100%}
    .fd-hjoin{display:flex;align-items:center;justify-content:center;height:100%;position:relative}
    .fd-hjoin::before{content:"";position:absolute;left:0;right:-105px;top:50%;border-top:2.5px dashed #0284c7}.fd-hjoin span{position:relative;white-space:nowrap;font-size:.6rem;font-weight:700;color:#0369a1;background:var(--fl-card,#fff);padding:0 .25rem;transform:translateY(-14px)}
    .fd-legend{margin:.4rem 0 .8rem;font-size:.68rem;opacity:.75;text-align:center}
    .fd-key{display:inline-block;width:1.2rem;border-top:3px solid;vertical-align:middle;margin-right:.2rem}.fd-key--g{border-color:#059669}.fd-key--p{border-color:#7c3aed}.fd-key--b{border-color:#0284c7;border-top-style:dashed}
    /* Phần chữ bên dưới */
    .ft-sec{border:1px solid rgba(100,116,139,.3);border-radius:.7rem;padding:.45rem .8rem;margin-bottom:.6rem;font-size:.8rem}
    .ft-sec summary{cursor:pointer;font-weight:800}.ft-sec summary span{font-weight:700;font-size:.68rem;padding:.02rem .45rem;border-radius:999px;background:rgba(100,116,139,.18);margin-left:.4rem}
    .ft-sec p{margin:.35rem 0 0;padding-left:.7rem;border-left:3px solid #c4b5fd;line-height:1.35}.ft-sec p i{font-style:normal;font-weight:800;color:#7c3aed}
    .ft-muted{opacity:.7}
    .ft-tl{list-style:none;margin:.5rem 0 0;padding:0;display:flex;flex-direction:column;gap:.25rem}
    .ft-tl li{display:flex;gap:.5rem;align-items:flex-start;line-height:1.3}
    .ft-tl i{font-style:normal;flex:none;min-width:1.35rem;height:1.35rem;border-radius:999px;color:#fff;font-size:.66rem;font-weight:800;display:inline-flex;align-items:center;justify-content:center;background:#059669}
    .ft-tl--give i{background:#7c3aed}.ft-tl--warn i{background:#d97706}.ft-tl--par i{background:#0284c7}.ft-tl--start i{background:#64748b}.ft-tl--report i{background:#059669}
    .ft-h4{margin:1rem 0 .5rem;font-size:.9rem;font-weight:800}
    .ft-results{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:.9rem;align-items:start}
    .ft-hop{position:relative;display:flex;flex-direction:column;background:var(--fl-card,#fff);border:1.5px solid var(--fl-line,rgba(100,116,139,.35));border-radius:.9rem;box-shadow:var(--fl-shadow,0 1px 3px rgba(15,23,42,.08));padding:0 .45rem .45rem}
    .ft-hop--done{border-color:#059669}.ft-hop--warn,.ft-hop--stale{border-color:#d97706}.ft-hop--failed,.ft-hop--blocked{border-color:#dc2626}.ft-hop--running{border-color:#0284c7;box-shadow:0 0 0 3px rgba(2,132,199,.18)}.ft-hop--waiting{border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.2)}
    .ft-hop__head{display:flex;align-items:baseline;gap:.5rem;height:36px;padding:.55rem .35rem 0;min-width:0}
    .ft-hop__head b{font-size:.95rem;font-weight:800;white-space:nowrap}.ft-hop__head small{font-size:.68rem;opacity:.65;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
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
    @media (max-width:900px){.fd{grid-template-columns:1fr}.fd>*{grid-column:1 !important}.fd-coord{grid-row:auto !important}.fd-link,.fd-par,.fd-join,.fd-hjoin{display:none}}
</style>
@endonce
