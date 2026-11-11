@props(['label', 'side' => 'in', 'items' => [], 'links' => []])
{{-- Đầu vào / Kết quả của cả luồng: danh sách ngắn + tối đa hai liên kết. --}}
<div {{ $attributes->class(['fl-end', 'fl-end--'.$side]) }}>
    <b>{{ $label }}</b>
    @if ($items)<ul>@foreach ($items as $it)<li>{{ $it }}</li>@endforeach</ul>@else<span>Chưa khai</span>@endif
    @foreach (array_slice($links, 0, 2) as $l)<a href="{{ $l['url'] }}" data-record="{{ $l['type'] ?? $side }}">{{ $l['label'] }} →</a>@endforeach
</div>
