@php
    $isApprover = (bool) auth()->user()?->isApprover();
    // Bốn cách lập kế hoạch. Hai cách cần AI/tạo chiến dịch chỉ dành cho người duyệt (máy chủ vẫn kiểm lại ở từng thao tác).
    $methods = array_values(array_filter([
        $isApprover ? ['key' => 'file', 'icon' => 'heroicon-o-arrow-up-tray', 'title' => 'Nhập từ file', 'desc' => 'Bạn đã có kế hoạch trong Excel hoặc CSV. AI tự nhận ra cột nào là gì.'] : null,
        $isApprover ? ['key' => 'chu-de', 'icon' => 'heroicon-o-sparkles', 'title' => 'Từ một chủ đề', 'desc' => 'Nhập một chủ đề, AI đề xuất cả chiến dịch: 1 bài trụ và các bài vệ tinh.'] : null,
        ['key' => 'cum', 'icon' => 'heroicon-o-squares-2x2', 'title' => 'Từ cụm từ khóa', 'desc' => 'Tạo bài cho những từ khóa của một cụm mà chưa có bài.'],
        ['key' => 'goi-y', 'icon' => 'heroicon-o-light-bulb', 'title' => 'Gợi ý theo dữ liệu', 'desc' => 'Hệ thống chọn từ khóa nên viết trước. Không tốn AI.', 'badge' => count($proposals) ?: null],
    ]));
    $keys = array_column($methods, 'key');
    $default = $keys[0];
    $stepsFile = [['1', 'Chọn file'], ['2', 'AI đọc file'], ['3', 'Xem lại và tạo']];
@endphp
<style>
    .lk-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: .75rem; margin: .25rem 0 1rem; }
    .lk-tile { display: flex; flex-direction: row; gap: .7rem; align-items: center; text-align: left; padding: .75rem .9rem; border: 1.5px solid var(--ss-border); border-radius: .85rem; background: var(--ss-surface); color: var(--ss-text); cursor: pointer; position: relative; transition: border-color .15s, box-shadow .15s, transform .15s; }
    .lk-tile:hover { border-color: var(--ss-accent-fg); transform: translateY(-1px); }
    .lk-tile:focus-visible { outline: 2px solid var(--ss-focus); outline-offset: 2px; }
    .lk-tile.is-on { border-color: var(--ss-accent-fg); background: var(--ss-accent-bg); box-shadow: 0 0 0 3px color-mix(in srgb, var(--ss-accent-fg) 18%, transparent); }
    .lk-ico { display: inline-flex; align-items: center; justify-content: center; width: 2.2rem; height: 2.2rem; border-radius: .6rem; background: var(--ss-muted-bg); color: var(--ss-accent-fg); }
    .lk-tile.is-on .lk-ico { background: var(--ss-accent-fg); color: var(--ss-surface); }
    .lk-ico svg { width: 1.25rem; height: 1.25rem; }
    .lk-tile b { font-size: var(--ss-fs-md); font-weight: 650; flex: 1; }
    .lk-tile span.lk-d { font-size: var(--ss-fs-sm); color: var(--ss-text-muted); line-height: 1.35; }
    .lk-badge { position: static; font-size: var(--ss-fs-xs); font-weight: 650; padding: .05rem .45rem; border-radius: 999px; background: var(--ss-accent-fg); color: var(--ss-surface); }
    .lk-panel { border: 1px solid var(--ss-border); border-radius: .85rem; background: var(--ss-surface); padding: 1.1rem 1.25rem; }
    .lk-h { margin: 0; font-size: var(--ss-fs-lg); font-weight: 650; color: var(--ss-text); }
    .lk-sub { margin: .2rem 0 .9rem; color: var(--ss-text-muted); font-size: var(--ss-fs-sm); max-width: 44rem; }
    .lk-steps { display: flex; flex-wrap: wrap; gap: .6rem; margin: .6rem 0 1rem; padding: 0; list-style: none; }
    .lk-steps li { display: flex; gap: .55rem; align-items: center; padding: .45rem .8rem .45rem .5rem; border-radius: 999px; background: var(--ss-muted-bg); }
    .lk-steps li > i { flex: none; width: 1.6rem; height: 1.6rem; border-radius: 50%; background: var(--ss-accent-fg); color: var(--ss-surface); font-style: normal; font-weight: 650; display: inline-flex; align-items: center; justify-content: center; font-size: var(--ss-fs-sm); }
    .lk-steps b { display: block; font-size: var(--ss-fs-sm); }
    .lk-steps small { color: var(--ss-text-muted); font-size: var(--ss-fs-xs); }
    .lk-row { display: flex; gap: .6rem; flex-wrap: wrap; align-items: center; margin: .5rem 0; }
    .lk-in { padding: .5rem .75rem; border: 1px solid var(--ss-border); border-radius: .55rem; background: var(--ss-surface); color: var(--ss-text); font-size: var(--ss-fs-base); max-width: 100%; }
    .lk-chips { display: flex; flex-wrap: wrap; gap: .35rem; margin: .5rem 0 .8rem; }
    .lk-chip { font-size: var(--ss-fs-xs); padding: .15rem .6rem; border-radius: 999px; background: var(--ss-muted-bg); color: var(--ss-text); }
    .lk-bar { display: flex; height: .5rem; border-radius: 999px; overflow: hidden; background: var(--ss-muted-bg); max-width: 22rem; }
    .lk-bar i { display: block; background: var(--ss-ok-fg); }
    .lk-panel .ss-card { border: 0; box-shadow: none; background: transparent; margin-bottom: 0 !important; }
    .lk-panel .ss-card__b { padding: 0; }
    .lk-panel .cpz { margin-bottom: 0; }
</style>

<div x-data="{ m: $persist(@js($default)).as('plan-compose-method') }" x-init="if (! @js($keys).includes(m)) m = @js($default)" x-on:plan-method.window="if (@js($keys).includes($event.detail)) m = $event.detail">
    <div class="lk-tiles" role="tablist" aria-label="Cách lập kế hoạch">
        @foreach ($methods as $t)
            <button type="button" role="tab" class="lk-tile" x-bind:class="{ 'is-on': m === @js($t['key']) }" x-bind:aria-selected="m === @js($t['key']) ? 'true' : 'false'" x-on:click="m = @js($t['key'])" data-method="{{ $t['key'] }}" title="{{ $t['desc'] }}">
                <span class="lk-ico"><x-filament::icon :icon="$t['icon']" /></span>
                <b>{{ $t['title'] }}</b>
                @if (! empty($t['badge']))<span class="lk-badge" title="{{ $t['badge'] }} gợi ý">{{ $t['badge'] }}</span>@endif
            </button>
        @endforeach
    </div>

    @if ($isApprover)
        <section class="lk-panel" x-show="m === 'file'" x-cloak role="tabpanel">
            <h3 class="lk-h">Nhập từ file
                <x-help title="Nhập kế hoạch từ file">
                    <p>Dùng khi bạn đã lập kế hoạch ở nơi khác (Excel, Google Sheets lưu ra CSV...). Mỗi người một kiểu file nên AI đọc và tự đoán cách nhập; bạn không phải cấu hình gì trước.</p>
                    <p>Bài tạo ra ở "Đã lên kế hoạch", chiến dịch mới ở "Tạm dừng"; chưa viết nội dung, chưa tốn token viết bài.</p>
                </x-help>
            </h3>
            <ol class="lk-steps">
                @foreach ($stepsFile as [$n, $t])<li><i>{{ $n }}</i><b>{{ $t }}</b></li>@endforeach
            </ol>
            @include('filament.partials.plan-template-banner')
            <x-filament::button icon="heroicon-o-arrow-up-tray" wire:click="openImport" data-import-open>Chọn file kế hoạch</x-filament::button>
        </section>

        <section x-show="m === 'chu-de'" x-cloak role="tabpanel" class="lk-panel">
            @include('filament.partials.plan-campaign-proposal')
        </section>
    @endif

    <section class="lk-panel" x-show="m === 'cum'" x-cloak role="tabpanel">
        <h3 class="lk-h">Từ cụm từ khóa
            <x-help title="Tạo bài từ cụm"><p>Cụm là nhóm từ khóa cùng chủ đề (tạo ở trang Từ khóa). Chọn một cụm, hệ thống cho biết từ khóa nào chưa có bài để bạn tạo.</p></x-help>
        </h3>
        @if (count($rows) === 0)
            <div class="ss-alert ss-alert--warn" role="status">Chưa có cụm nào. Tạo ở trang <a href="{{ \App\Filament\Pages\KeywordsPage::getUrl() }}" style="text-decoration:underline">Từ khóa</a>.</div>
        @else
            <div class="lk-row">
                <select class="lk-in" wire:change="selectCluster($event.target.value)" aria-label="Chọn cụm từ khóa">
                    @foreach ($rows as $r)
                        <option value="{{ $r['c']['name'] }}" @selected($detail && $detail['c']['name'] === $r['c']['name'])>{{ $r['c']['name'] }} ({{ count($r['cov']['free']) }} từ khóa chưa có bài)</option>
                    @endforeach
                </select>
            </div>
            @if ($detail)
                @php $total = max(1, count($detail['c']['keywords'])); $cov = count($detail['cov']['covered']); @endphp
                <div class="lk-bar" title="{{ $cov }}/{{ $total }} từ khóa đã có bài"><i style="width:{{ $cov / $total * 100 }}%"></i></div>
                <p class="lk-sub" style="margin:.35rem 0 .5rem">{{ $cov }}/{{ $total }} từ khóa đã có bài · <b>{{ count($detail['cov']['free']) }}</b> chưa có</p>
                @if (count($detail['cov']['free']))
                    <div class="lk-chips">
                        @foreach (array_slice($detail['cov']['free'], 0, 10) as $k)<span class="lk-chip">{{ $k }}</span>@endforeach
                        @if (count($detail['cov']['free']) > 10)<span class="lk-chip">+{{ count($detail['cov']['free']) - 10 }} nữa</span>@endif
                    </div>
                    <div class="lk-row">
                        <span @if (! $canCreate) title="Cụm đã có bài cho mọi từ khóa hoặc chưa chọn cụm." @endif>{{ $this->createFromClusterAction }}</span>
                        <x-filament::button color="gray" wire:click="suggest" :disabled="! $canCreate" icon="heroicon-o-sparkles">Gợi ý tiêu đề giúp tôi</x-filament::button>
                    </div>
                    @if ($suggestions)
                                                @foreach ($suggestions as $s)
                            <label class="ss-text-sm" style="display:block;margin-top:.4rem"><input type="checkbox" wire:model="suggestPick" value="{{ $s['keyword'] }}"> {{ $s['title'] }} <span class="ss-muted">— {{ $s['keyword'] }}</span></label>
                        @endforeach
                        <div class="lk-row">
                            @if ($showTarget)
                                <select class="lk-in" wire:model="planTarget" aria-label="Gán vào chiến dịch">
                                    <option value="">Đưa vào… (chọn chiến dịch hoặc Bài lẻ)</option><option value="solo">Bài lẻ — không thuộc chiến dịch</option>
                                    @foreach ($campaigns as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                                </select>
                            @endif
                            <x-filament::button wire:click="createSuggested">Tạo các bài đã chọn</x-filament::button>
                        </div>
                    @endif
                @else
                    <div class="ss-alert ss-alert--ok" role="status">Cụm này đã có bài cho mọi từ khóa.</div>
                @endif
            @endif
        @endif
    </section>

    <section class="lk-panel" x-show="m === 'goi-y'" x-cloak role="tabpanel">
        <x-chain-context step="Kế hoạch của chiến dịch" purpose="Quyết định viết gì, theo thứ tự ưu tiên. Mỗi bài thuộc một chiến dịch hoặc là bài lẻ." :inputs="[['label'=>'Từ khóa và cụm chủ đề','url'=>\App\Filament\Pages\KeywordsPage::getUrl()],['label'=>'Đề xuất làm mới từ Audit','url'=>\App\Filament\Pages\AuditPage::getUrl()]]" :outputs="[['label'=>'Bài «Đã lên kế hoạch» để đội AI viết','url'=>\App\Filament\Resources\ArticleResource::getUrl('index'),'note'=>'Bài viết']]" />
        @include('filament.partials.plan-proposals', ['proposals' => $proposals, 'pendingAudit' => $pendingAudit, 'showTarget' => $showTarget, 'targetCampaigns' => $campaigns])
    </section>
</div>
