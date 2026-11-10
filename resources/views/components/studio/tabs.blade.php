@props(['tabs' => [], 'default' => null, 'persist' => null, 'label' => 'Các phần của trang'])
@php
    $tabs = array_values($tabs);
    $default ??= $tabs[0]['key'] ?? '';
    $uid = 'tabs-'.($persist ?: substr(md5(json_encode(array_column($tabs, 'key'))), 0, 6));
    $tones = ['ok', 'warn', 'danger', 'info'];
@endphp
<div class="ss-tabs-wrap" x-data="studioTabs(@js($default), @js($persist))" data-tabs="{{ $uid }}">
    <div class="ss-tabs-scroller" x-bind:class="{ 'has-start': canStart, 'has-end': canEnd }">
    <button type="button" class="ss-tabs__arrow ss-tabs__arrow--start" tabindex="-1" aria-hidden="true" x-on:click="nudge(-1)" x-cloak>‹</button>
    <div class="ss-tabs" role="tablist" aria-label="{{ $label }}" x-ref="strip" x-on:keydown="onKey($event)" x-on:scroll.passive="measure()">
        @foreach ($tabs as $t)
            @php $k = (string) $t['key']; @endphp
            <button type="button" role="tab" class="ss-tab" data-key="{{ $k }}" id="{{ $uid }}-tab-{{ $k }}" aria-controls="{{ $uid }}-panel-{{ $k }}"
                x-bind:class="{ 'is-active': active === @js($k) }" x-bind:aria-selected="active === @js($k) ? 'true' : 'false'"
                x-bind:tabindex="active === @js($k) ? 0 : -1" x-on:click="set(@js($k))">
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
