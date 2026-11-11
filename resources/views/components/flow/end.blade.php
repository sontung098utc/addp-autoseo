@props(['label', 'side' => 'in', 'items' => [], 'links' => []])
{{-- Đầu vào / Kết quả của luồng: một dòng chữ + tối đa hai liên kết. --}}
<div {{ $attributes->class(['fl-end', 'fl-end--'.$side]) }}>
    <b>{{ $label }}</b>
    <span class="t" title="{{ implode('; ', $items) }}">{{ $items[0] ?? 'Chưa khai' }}@if (count($items) > 1) +{{ count($items) - 1 }}@endif</span>
    @foreach (array_slice($links, 0, 2) as $l)<a href="{{ $l['url'] }}" data-record="{{ $l['type'] ?? $side }}">{{ $l['label'] }}</a>@endforeach
</div>
