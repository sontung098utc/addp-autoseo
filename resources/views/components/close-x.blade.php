@props(['label' => 'Đóng'])
{{--
    Nút đóng hộp thoại / ngăn: biểu tượng X tròn nhỏ ở góc phải, aria-label "Đóng" (thay cho nút chữ "Đóng").
    Dùng: <x-close-x wire:click="closeItem" />  hoặc  <x-close-x x-on:click="open = false" />. Phím Esc do hộp thoại tự xử lý.
--}}
@once
    <style>
        .cx-btn{display:inline-flex;align-items:center;justify-content:center;width:1.75rem;height:1.75rem;padding:0;border:0;border-radius:999px;background:transparent;color:inherit;opacity:.65;cursor:pointer;flex:none}
        .cx-btn:hover{opacity:1;background:rgba(128,128,128,.18)}
        .cx-btn:focus-visible{outline:2px solid currentColor;outline-offset:1px;opacity:1}
    </style>
@endonce
<button type="button" {{ $attributes->class(['cx-btn']) }} aria-label="{{ $label }}" title="{{ $label }}"><x-filament::icon icon="heroicon-o-x-mark" style="width:1.1rem;height:1.1rem" /></button>
