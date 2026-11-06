{{-- Tăng/giảm có màu + mũi tên + chữ. good='up': tăng là tốt (clicks); good='down': giảm là tốt (vị trí, số lỗi). --}}
@props(['value' => null, 'good' => 'up', 'unit' => '', 'decimals' => 1])
@php
    if ($value === null || ! is_numeric($value)) {
        $cls = 'none'; $icon = ''; $text = '—'; $label = 'Chưa có dữ liệu';
    } else {
        $n = (float) $value;
        $flat = abs($n) < 1e-9;
        $up = $n > 0;
        $cls = $flat ? 'flat' : (($up === ($good === 'up')) ? 'ok' : 'danger');
        $abs = number_format(abs($n), (int) $decimals, ',', '.');
        $icon = $flat ? '■' : ($up ? '▲' : '▼');
        $text = ($flat ? '' : ($up ? '+' : '−')).$abs.$unit;
        $label = ($flat ? 'không đổi' : ($up ? 'tăng ' : 'giảm ')).$abs.$unit;
    }
@endphp
<span {{ $attributes->class(['ss-delta', 'ss-delta--'.$cls]) }} title="{{ $label }}"><span aria-hidden="true">{{ $icon }}</span><span>{{ $text }}</span><span class="sr-only">{{ $label }}</span></span>
