{{-- AG-S25: ảnh đã chọn + liên kết nội bộ gợi ý/đã chèn, đọc từ dữ liệu bước viết. Mọi giá trị đều qua {{ }} (escape). --}}
@php
    $imgs = $data['images']['selected'] ?? [];
    $imgUsed = $data['images']['inserted_ids'] ?? [];
    $imgWarn = $data['images']['warning'] ?? null;
    $links = $data['internal_links']['suggested'] ?? [];
    $linkUsed = $data['internal_links']['inserted'] ?? [];
@endphp
@if ($imgs !== [] || $imgWarn || $links !== [])
    <details class="rv-small" data-testid="context-assets" style="margin-top:.35rem">
        <summary>Ảnh đã chọn ({{ count($imgs) }}) · Liên kết nội bộ gợi ý ({{ count($links) }}, đã chèn {{ count($linkUsed) }})</summary>
        @if ($imgWarn)<div>{{ $imgWarn }}. Chỉ nhắc, không chặn.</div>@endif
        @if ($imgs !== [])
            <div><strong>Ảnh đã chọn</strong></div>
            <ul>@foreach ($imgs as $i)<li>#{{ $i['id'] ?? '' }} · điểm {{ $i['diem'] ?? '' }} · alt: {{ $i['alt'] ?? '' }} · {{ $i['ly_do'] ?? '' }}@if (in_array($i['id'] ?? null, $imgUsed, true)) · <em>đã chèn vào bài</em>@endif</li>@endforeach</ul>
        @endif
        @if ($links !== [])
            <div><strong>Liên kết nội bộ gợi ý</strong></div>
            <ul>@foreach ($links as $l)<li>{{ $l['anchor'] ?? '' }} — <code>{{ $l['url'] ?? '' }}</code> · điểm {{ $l['diem'] ?? '' }} · {{ $l['ly_do'] ?? '' }}@if (in_array($l['url'] ?? null, $linkUsed, true)) · <em>đã chèn</em>@endif</li>@endforeach</ul>
        @endif
    </details>
@endif
