@props(['when' => null, 'dashed' => false, 'done' => false, 'loop' => null])
{{-- Đường nối giữa hai nút. when = nối "tùy điều kiện" (nét đứt + chấm nhỏ); loop = nhãn vòng lặp (ví dụ "↻ 2 vòng"). --}}
<span {{ $attributes->class(['fl-link', 'fl-link--dashed' => $dashed || $when, 'fl-link--dot' => (bool) $when, 'fl-link--done' => $done, 'fl-link--loop' => $loop !== null]) }} @if ($when) title="{{ $when }}" data-when @endif aria-hidden="true">@if ($loop !== null)<span class="fl-loop" data-loop>{{ $loop }}</span>@endif</span>
