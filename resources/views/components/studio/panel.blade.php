@props(['key'])
<div role="tabpanel" x-show="active === @js((string) $key)" x-cloak tabindex="0">{{ $slot }}</div>
