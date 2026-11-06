@php($sources = $getRecord()->sources_cited ?? [])
<p style="font-size:.8rem;margin-bottom:.5rem;"><strong>AI đề xuất</strong> — nguồn trích dưới đây do AI nêu, cần người kiểm chứng.</p>
@if (empty($sources))
    <p>Chưa có nguồn trích.</p>
@else
    <ul style="list-style:disc;padding-left:1.25rem;">
        @foreach ($sources as $source)
            <li>{{ is_array($source) ? implode(' — ', array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $source)) : (string) $source }}</li>
        @endforeach
    </ul>
@endif
