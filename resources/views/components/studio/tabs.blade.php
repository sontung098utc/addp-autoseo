@props(['tabs' => [], 'default' => null, 'persist' => null, 'label' => 'Các phần của trang'])
@php
    $tabs = array_values($tabs);
    $default ??= $tabs[0]['key'] ?? '';
    $uid = 'tabs-'.($persist ?: substr(md5(json_encode(array_column($tabs, 'key'))), 0, 6));
    $tones = ['ok', 'warn', 'danger', 'info'];
@endphp
@once
<style>
    /* Kiểu tab ngang giống hàng tab cụm Đội AI (Filament sub-navigation): thanh bo tròn, ô bo tròn, nền nhạt + chữ đậm khi chọn. Ghi đè seo-studio.css bằng độ ưu tiên cao hơn. */
    .ss-tabs-wrap > .ss-tabs-scroller { background: var(--ss-surface); border: 1px solid var(--ss-border); border-radius: 0.75rem; box-shadow: 0 1px 2px rgb(0 0 0 / .04); }
    .ss-tabs-wrap .ss-tabs { gap: 0.25rem; align-items: center; padding: 0.375rem; border-bottom: 0; scrollbar-width: none; }
    .ss-tabs-wrap .ss-tabs::-webkit-scrollbar { display: none; }
    .ss-tabs-wrap .ss-tab { gap: 0.5rem; padding: 0.5rem 0.75rem; margin-bottom: 0; font-size: 0.875rem; line-height: 1.25rem; font-weight: 500; border: 0; border-radius: 0.5rem; box-shadow: none; color: var(--ss-text-muted); background: transparent; transition: background-color .15s, color .15s; }
    .ss-tabs-wrap .ss-tab:hover { background: var(--ss-surface-sunk); color: var(--ss-text); }
    .ss-tabs-wrap .ss-tab.is-active { background: var(--ss-accent-bg); color: var(--ss-accent-fg); font-weight: 600; border: 0; box-shadow: none; }
    .ss-tabs-wrap .ss-tab:focus-visible { outline: 2px solid var(--ss-focus); outline-offset: 1px; }
    .ss-tabs-wrap .ss-tab__icon { width: 1.25rem; height: 1.25rem; flex: none; }
    .ss-tabs-wrap .ss-tab.is-active .ss-tab__count { background: var(--ss-surface); }
    .ss-tabs-wrap .ss-tabs-scroller::before, .ss-tabs-wrap .ss-tabs-scroller::after { bottom: 0; border-radius: 0.75rem; --ss-bg-edge: var(--ss-surface); }
    .ss-tabs-wrap [class*=ss-tabs__arrow] { bottom: 0; padding: 0; }
    @media (prefers-reduced-motion: reduce) { .ss-tabs-wrap .ss-tab { transition: none; } }
</style>
@endonce
<div class="ss-tabs-wrap" x-data="studioTabs(@js($default), @js($persist))" data-tabs="{{ $uid }}">
    <div class="ss-tabs-scroller" x-bind:class="{ 'has-start': canStart, 'has-end': canEnd }">
    <button type="button" class="ss-tabs__arrow ss-tabs__arrow--start" tabindex="-1" aria-hidden="true" x-on:click="nudge(-1)" x-cloak>‹</button>
    <div class="ss-tabs" role="tablist" aria-label="{{ $label }}" x-ref="strip" x-on:keydown="onKey($event)" x-on:scroll.passive="measure()">
        @foreach ($tabs as $t)
            @php $k = (string) $t['key']; @endphp
            <button type="button" role="tab" class="ss-tab" data-key="{{ $k }}" id="{{ $uid }}-tab-{{ $k }}" aria-controls="{{ $uid }}-panel-{{ $k }}"
                x-bind:class="{ 'is-active': active === @js($k) }" x-bind:aria-selected="active === @js($k) ? 'true' : 'false'"
                x-bind:tabindex="active === @js($k) ? 0 : -1" x-on:click="set(@js($k))">
                @if (! empty($t['icon']))<x-filament::icon :icon="$t['icon']" class="ss-tab__icon" />@endif
                <span>{{ $t['label'] }}</span>
                @if (isset($t['count']) && $t['count'] !== null && $t['count'] !== '')
                    <span class="ss-tab__count">{{ $t['count'] }}</span>
                @endif
                @if (! empty($t['tone']) && in_array($t['tone'], $tones, true))
                    <span class="ss-dot ss-dot--{{ $t['tone'] }}" aria-hidden="true"></span><span class="sr-only">cần chú ý</span>
                @endif
            </button>
        @endforeach
    </div>
    <button type="button" class="ss-tabs__arrow ss-tabs__arrow--end" tabindex="-1" aria-hidden="true" x-on:click="nudge(1)" x-cloak>›</button>
    </div>
    <div class="ss-tabpanels" data-uid="{{ $uid }}">{{ $slot }}</div>
</div>
