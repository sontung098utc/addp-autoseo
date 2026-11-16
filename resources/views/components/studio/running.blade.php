@props(['label' => 'Đang chạy', 'size' => 'md'])
{{-- AG-S07: chỉ báo "đang chạy". Chỉ render khi có dữ liệu thật; reduced-motion thì còn nhãn chữ. --}}
<span class="ss-running ss-running--{{ $size }}" data-running role="status"><span class="ss-spin" aria-hidden="true"></span><span>{{ $label }}</span></span>
