@php
    $p = $campaignProposal;
    $srcTone = ['keyword' => 'info', 'competitor' => 'accent', 'gsc' => 'ok', 'serp' => 'accent', 'autocomplete' => 'info', 'ai' => 'warn'];
    $partLabels = ['volume' => 'Lượt tìm', 'gsc' => 'Search Console', 'sources' => 'Độ đa nguồn', 'kho' => 'Có trong kho', 'rank' => 'Thứ hạng gợi ý', 'kho_nguon' => 'Kho có nguồn', 'trend' => 'Xu hướng', 'off_topic' => 'Lệch lĩnh vực'];
    // Trạng thái các nguồn dữ liệu: bật = đã dùng thật trong đề xuất này; tắt = nói rõ cần gì để bật.
    $providers = $p['providers'] ?? [];
    $sources = $p ? [
        ['on' => in_array('autocomplete', $providers, true), 'name' => 'Gợi ý Google', 'hint' => 'Câu người ta thật sự gõ quanh chủ đề. Không cần khóa.'],
        ['on' => $p['gsc_used'], 'name' => 'Search Console', 'hint' => 'Truy vấn thật của website: vị trí, lượt hiển thị. Cần nối Search Console.'],
        ['on' => in_array('dataforseo', $providers, true), 'name' => 'DataForSEO', 'hint' => 'Mọi người cũng hỏi, top 10 để kiểm trùng bài, lượt tìm. Cần khóa DataForSEO.'],
    ] : [];
    $chosen = count($campaignPick);
    $total = $p ? count($p['satellites']) : 0;
@endphp
<style>
    .cpz { --cpz-r: .75rem; margin-bottom: 1rem; }
    .cpz-title { font-size: var(--ss-fs-lg); font-weight: 650; margin: 0; color: var(--ss-text); }
    .cpz-sub { color: var(--ss-text-muted); font-size: var(--ss-fs-sm); margin: .15rem 0 0; max-width: 46rem; }
    .cpz-form { display: flex; gap: .5rem; flex-wrap: wrap; align-items: center; margin-top: .85rem; }
    .cpz-in { flex: 1 1 18rem; min-width: 0; padding: .55rem .8rem; border: 1px solid var(--ss-border); border-radius: .5rem; background: var(--ss-surface); color: var(--ss-text); font-size: var(--ss-fs-base); }
    .cpz-in:focus { outline: 2px solid var(--ss-focus); outline-offset: 1px; }
    .cpz-srcs { display: flex; gap: .4rem; flex-wrap: wrap; margin-top: .85rem; }
    .cpz-src { display: inline-flex; align-items: center; gap: .35rem; font-size: var(--ss-fs-xs); padding: .2rem .6rem; border-radius: 999px; border: 1px solid var(--ss-border); color: var(--ss-text-muted); background: var(--ss-surface); cursor: help; }
    .cpz-src::before { content: ""; width: .5rem; height: .5rem; border-radius: 50%; border: 1.5px solid var(--ss-text-muted); }
    .cpz-src.is-on { color: var(--ss-ok-fg); background: var(--ss-ok-bg); border-color: transparent; }
    .cpz-src.is-on::before { background: var(--ss-ok-fg); border-color: var(--ss-ok-fg); }
    .cpz-sum { display: flex; gap: .5rem 1.25rem; flex-wrap: wrap; margin: 1.1rem 0 .75rem; font-size: var(--ss-fs-sm); color: var(--ss-text-muted); }
    .cpz-sum b { color: var(--ss-text); font-size: var(--ss-fs-md); }
    .cpz-missing { display: flex; gap: .6rem; align-items: flex-start; padding: .65rem .85rem; margin-bottom: .85rem; border-radius: .6rem; background: var(--ss-warn-bg); color: var(--ss-warn-fg); font-size: var(--ss-fs-sm); }
    .cpz-missing ul { margin: .1rem 0 0; padding-left: 1rem; }
    .cpz-hub { padding: 1rem 1.15rem; border-radius: var(--cpz-r); background: var(--ss-accent-bg); border: 1px solid var(--ss-accent-fg); }
    .cpz-hub__tag { display: inline-block; font-size: var(--ss-fs-xs); font-weight: 650; letter-spacing: .04em; text-transform: uppercase; color: var(--ss-accent-fg); }
    .cpz-hub h4 { margin: .15rem 0 .25rem; font-size: var(--ss-fs-lg); color: var(--ss-text); }
    .cpz-hub p { margin: .15rem 0; font-size: var(--ss-fs-sm); color: var(--ss-text-muted); }
    .cpz-stem { width: 2px; height: 1.1rem; margin: 0 auto; background: var(--ss-accent-fg); opacity: .5; }
    .cpz-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(17.5rem, 1fr)); gap: .75rem; }
    .cpz-card { display: flex; flex-direction: column; gap: .5rem; padding: .85rem .95rem; border-radius: var(--cpz-r); background: var(--ss-surface); border: 1px solid var(--ss-border); box-shadow: var(--ss-shadow); transition: border-color .15s, opacity .15s; }
    .cpz-card:hover { border-color: var(--ss-accent-fg); }
    .cpz-card.is-off { opacity: .55; }
    .cpz-card__top { display: flex; gap: .55rem; align-items: flex-start; }
    .cpz-card__top input { margin-top: .22rem; accent-color: var(--ss-accent-fg); width: 1rem; height: 1rem; flex: none; }
    .cpz-card__kw { font-weight: 650; color: var(--ss-text); line-height: 1.3; cursor: pointer; }
    .cpz-bar { display: flex; align-items: center; gap: .55rem; }
    .cpz-bar__track { flex: 1; height: .4rem; border-radius: 999px; background: var(--ss-muted-bg); overflow: hidden; }
    .cpz-bar__fill { height: 100%; border-radius: 999px; background: var(--ss-accent-fg); }
    .cpz-bar__num { font-size: var(--ss-fs-xs); font-weight: 650; color: var(--ss-text); min-width: 3.6rem; text-align: right; font-variant-numeric: tabular-nums; }
    .cpz-head-line { font-size: var(--ss-fs-xs); color: var(--ss-text-muted); }
    .cpz-chips { display: flex; gap: .3rem; flex-wrap: wrap; }
    .cpz-more { font-size: var(--ss-fs-xs); border-top: 1px dashed var(--ss-border); padding-top: .4rem; margin-top: .25rem; }
    .cpz-more summary { cursor: pointer; color: var(--ss-accent-fg); font-weight: 600; list-style: none; }
    .cpz-more summary::-webkit-details-marker { display: none; }
    .cpz-more summary::before { content: "▸ "; }
    .cpz-more[open] summary::before { content: "▾ "; }
    .cpz-more dl { margin: .45rem 0 0; display: grid; gap: .4rem; color: var(--ss-text-muted); }
    .cpz-more dt { color: var(--ss-text); font-weight: 650; }
    .cpz-more dd { margin: 0; }
    .cpz-parts { display: flex; gap: .25rem .6rem; flex-wrap: wrap; }
    .cpz-merged { margin-top: .85rem; padding: .6rem .85rem; border-radius: .6rem; background: var(--ss-muted-bg); color: var(--ss-text-muted); font-size: var(--ss-fs-xs); }
    .cpz-bar-act { position: sticky; bottom: .5rem; z-index: 2; display: flex; gap: .6rem; flex-wrap: wrap; align-items: center; margin-top: 1rem; padding: .7rem .85rem; border-radius: var(--cpz-r); background: var(--ss-surface); border: 1px solid var(--ss-border); box-shadow: 0 6px 18px rgb(18 20 26 / .12); }
    .cpz-bar-act .cpz-in { flex: 1 1 14rem; }
    .cpz-count { font-size: var(--ss-fs-sm); color: var(--ss-text-muted); }
    .cpz-count b { color: var(--ss-text); }
    .cpz-note { font-size: var(--ss-fs-xs); color: var(--ss-text-muted); margin-top: .3rem; }
    @media (prefers-reduced-motion: reduce) { .cpz-card { transition: none; } }
</style>
<div class="ss-card cpz" data-campaign-proposal><div class="ss-card__b">
    <h3 class="cpz-title">Đề xuất chiến dịch</h3>
    <p class="cpz-sub">Nhập một chủ đề, hệ thống đề xuất một <b>bài trụ</b> và các <b>bài vệ tinh</b> dẫn về nó. Bấm đề xuất chưa tạo gì; bạn chọn bài rồi mới tạo chiến dịch, và chiến dịch tạo ra ở trạng thái tạm dừng.</p>
    <form wire:submit="proposeCampaign" class="cpz-form">
        <input class="cpz-in" type="text" wire:model="campaignSeed" maxlength="120" placeholder="Chủ đề chiến dịch, ví dụ: tiểu đường" aria-label="Chủ đề chiến dịch" data-campaign-seed>
        <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="proposeCampaign">
            <span wire:loading.remove wire:target="proposeCampaign">Đề xuất chiến dịch</span>
            <span wire:loading wire:target="proposeCampaign">Đang tra gợi ý…</span>
        </x-filament::button>
    </form>

    @if ($p)
        <div class="cpz-srcs" aria-label="Nguồn dữ liệu đã dùng">
            @foreach ($sources as $s)
                <span class="cpz-src {{ $s['on'] ? 'is-on' : '' }}" title="{{ $s['hint'] }}">{{ $s['name'] }}: {{ $s['on'] ? 'đã dùng' : 'chưa có' }}</span>
            @endforeach
        </div>

        <div class="cpz-sum" data-campaign-result>
            <span><b>1</b> bài trụ</span>
            <span><b>{{ $total }}</b> bài vệ tinh</span>
            @if (count($p['merged']))<span><b>{{ count($p['merged']) }}</b> cụm gộp vào bài trụ</span>@endif
            <span>Chi phí tra cứu: <b>{{ number_format($p['cost_vnd'], 0, ',', '.') }} đ</b></span>
        </div>

        @if ($p['notes'])
            <div class="cpz-missing" role="note">
                <span aria-hidden="true">⚠</span>
                <div><b>Còn thiếu để đề xuất chính xác hơn</b>
                    <ul>@foreach ($p['notes'] as $n)<li>{{ $n }}</li>@endforeach</ul>
                </div>
            </div>
        @endif

        <div class="cpz-hub" data-pillar>
            <span class="cpz-hub__tag">Bài trụ</span>
            <h4>{{ $p['pillar']['keyword'] }}</h4>
            <p>{{ $p['pillar']['why'] }}</p>
            @if ($p['pillar']['secondary'])
                <div class="cpz-chips" style="margin-top:.4rem">@foreach ($p['pillar']['secondary'] as $k)<x-studio.badge tone="muted">{{ $k }}</x-studio.badge>@endforeach</div>
                <p class="cpz-note">Từ khóa phụ của bài trụ: đi vào bài khi viết.</p>
            @endif
            <details class="cpz-more" style="border-color:var(--ss-accent-fg)">
                <summary>Nếu sai thì thấy gì</summary>
                <dl><dd>{{ $p['pillar']['if_wrong'] }}</dd></dl>
            </details>
        </div>
        @if ($total)<div class="cpz-stem" aria-hidden="true"></div>@endif

        <div class="cpz-grid">
            @forelse ($p['satellites'] as $s)
                @php $id = 'cpz-'.md5($s['keyword']); $on = in_array($s['keyword'], $campaignPick, true); @endphp
                <article class="cpz-card {{ $on ? '' : 'is-off' }}" wire:key="{{ $id }}" data-satellite>
                    <div class="cpz-card__top">
                        <input type="checkbox" id="{{ $id }}" value="{{ $s['keyword'] }}" wire:model.live="campaignPick" aria-label="Chọn {{ $s['keyword'] }}">
                        <label for="{{ $id }}" class="cpz-card__kw">{{ $s['keyword'] }}</label>
                    </div>
                    <div class="cpz-bar" title="Điểm ưu tiên 0–100">
                        <div class="cpz-bar__track"><div class="cpz-bar__fill" style="width: {{ max(3, (int) $s['score']) }}%"></div></div>
                        <span class="cpz-bar__num">{{ $s['score'] }}/100</span>
                    </div>
                    <div class="cpz-head-line">{{ $s['headline'] ?? '' }}</div>
                    <div class="cpz-chips">
                        @foreach ($s['sources'] as $src)<x-studio.badge :tone="$srcTone[$src['type']] ?? 'muted'">{{ $src['label'] }}</x-studio.badge>@endforeach
                        @if ($s['secondary'])<x-studio.badge tone="accent">+{{ count($s['secondary']) }} từ khóa phụ</x-studio.badge>@endif
                        @if (($s['trend']['direction'] ?? 'unknown') === 'up')<span title="{{ implode('; ', array_column($s['trend']['evidence'], 'text')) }}"><x-studio.badge tone="ok">↑ Đang tăng</x-studio.badge></span>@elseif (($s['trend']['direction'] ?? 'unknown') === 'down')<span title="{{ implode('; ', array_column($s['trend']['evidence'], 'text')) }}"><x-studio.badge tone="warn">↓ Đang giảm</x-studio.badge></span>@endif
                        @if (($s['kho_matches'] ?? null) === 0)<span title="Kho kiến thức chưa có mục đã duyệt khớp chủ đề này: nên bổ sung nguồn đáng tin trước khi viết."><x-studio.badge tone="warn">Kho chưa có nguồn</x-studio.badge></span>@elseif (($s['kho_matches'] ?? 0) > 0)<x-studio.badge tone="ok">Kho có nguồn</x-studio.badge>@endif
                    </div>
                    <details class="cpz-more">
                        <summary>Vì sao và cách kiểm tra</summary>
                        <dl>
                            <div><dt>Vì sao đề xuất</dt><dd>{{ $s['why'] }}</dd></div>
                            <div><dt>Nếu sai thì thấy gì</dt><dd>{{ $s['if_wrong'] }}</dd></div>
                            @if ($s['secondary'])<div><dt>Từ khóa phụ đi kèm</dt><dd>{{ implode('; ', $s['secondary']) }}</dd></div>@endif
                            <div><dt>Điểm gồm</dt><dd class="cpz-parts">@foreach ($s['score_parts'] as $k => $v)@if ($v !== 0)<span>{{ $partLabels[$k] ?? $k }} {{ $v > 0 ? '+' : '' }}{{ $v }}</span>@endif @endforeach</dd></div>
                        </dl>
                    </details>
                </article>
            @empty
                <p class="ss-muted ss-text-sm">Chưa tìm được bài vệ tinh nào cho chủ đề này. Thử chủ đề rộng hơn hoặc thêm từ khóa vào kho.</p>
            @endforelse
        </div>

        @if ($p['merged'])
            <div class="cpz-merged">@foreach ($p['merged'] as $m)<div data-merged>Không viết riêng «{{ $m['keyword'] }}»: {{ $m['why'] }}</div>@endforeach</div>
        @endif

        @if ($total)
            <div class="cpz-bar-act">
                <span class="cpz-count">Đã chọn <b>{{ $chosen }}</b>/{{ $total }} vệ tinh + bài trụ</span>
                <input class="cpz-in" type="text" wire:model="campaignName" maxlength="120" aria-label="Tên chiến dịch" placeholder="Tên chiến dịch">
                <x-filament::button wire:click="createCampaignFromProposal" :disabled="$chosen === 0" data-create-campaign>Tạo chiến dịch (tạm dừng)</x-filament::button>
                <x-filament::button color="gray" wire:click="discardCampaignProposal">Bỏ đề xuất</x-filament::button>
            </div>
        @endif
    @endif
</div></div>
