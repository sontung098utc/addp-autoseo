@props(['tabs' => [], 'default' => null, 'persist' => null, 'label' => 'Các phần của trang'])
@php
    $tabs = array_values($tabs);
    $default ??= $tabs[0]['key'] ?? '';
    $uid = 'tabs-'.($persist ?: substr(md5(json_encode(array_column($tabs, 'key'))), 0, 6));
    $tones = ['ok', 'warn', 'danger', 'info'];
@endphp
@once
<style>
    /* Hàng tab phẳng như hàng tab cũ của trang Chi phí AI: đường kẻ mảnh dưới cả hàng, tab mờ, tab chọn đậm + gạch chân 2px. Ghi đè seo-studio.css bằng độ ưu tiên cao hơn. */
    .ss-tabs-wrap > .ss-tabs-scroller { background: transparent; border: 0; border-radius: 0; box-shadow: none; }
    .ss-tabs-wrap .ss-tabs { gap: 0.25rem; align-items: stretch; padding: 0; border-bottom: 1px solid rgba(128,128,128,.3); scrollbar-width: none; }
    .ss-tabs-wrap .ss-tabs::-webkit-scrollbar { display: none; }
    .ss-tabs-wrap .ss-tab { gap: 0.5rem; padding: 0.55rem 1rem; margin-bottom: -1px; font-size: 0.9rem; line-height: 1.4; font-weight: 400; border: 0; border-bottom: 2px solid transparent; border-radius: 0; box-shadow: none; color: inherit; background: transparent; opacity: .7; }
    .ss-tabs-wrap .ss-tab:hover { background: transparent; color: inherit; opacity: 1; }
    .ss-tabs-wrap .ss-tab.is-active { background: transparent; opacity: 1; font-weight: 600; color: #0f6b5c; border: 0; border-bottom: 2px solid #0f6b5c; box-shadow: none; }
    :root[data-theme=dark] .ss-tabs-wrap .ss-tab.is-active, .dark .ss-tabs-wrap .ss-tab.is-active { color: #5eead4; border-bottom-color: #5eead4; }
    .ss-tabs-wrap .ss-tab:focus-visible { outline: 2px solid var(--ss-focus); outline-offset: -2px; }
    .ss-tabs-wrap .ss-tab__icon { width: 1.1rem; height: 1.1rem; flex: none; }
    .ss-tabs-wrap .ss-tabs-scroller::before, .ss-tabs-wrap .ss-tabs-scroller::after { border-radius: 0; }
    .ss-tabs-wrap .ss-tabs-scroller .ss-tabs { overflow-y: hidden; }
</style>
@endonce
<div class="ss-tabs-wrap" x-data="studioTabs(@js($default), @js($persist))" data-tabs="{{ $uid }}">
    <div class="ss-tabs-scroller" x-bind:class="{ 'has-start': canStart, 'has-end': canEnd }">
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
    </div>
    <div class="ss-tabpanels" data-uid="{{ $uid }}">{{ $slot }}</div>
</div>
