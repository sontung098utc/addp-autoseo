@props(['text', 'tone' => 'info'])
{{-- Ghi chú phụ: một biểu tượng (!) hoặc (i), nội dung hiện khi rê chuột/focus. Dùng thay cho đoạn chữ giải thích dài trên trang. --}}
@php
    $icon = $tone === 'warning' ? 'heroicon-o-exclamation-circle' : 'heroicon-o-information-circle';
    $color = $tone === 'warning' ? '#d97706' : 'currentColor';
@endphp
<span x-data x-tooltip="{ content: @js((string) $text), theme: $store.theme }" tabindex="0" role="img" aria-label="{{ $text }}" data-hint="{{ $tone }}"
      {{ $attributes->merge(['class' => 'ss-hint', 'style' => 'display:inline-flex;vertical-align:middle;cursor:help;opacity:.7;color:'.$color]) }}>
    <x-filament::icon :icon="$icon" style="width:1rem;height:1rem" />
</span>
