@php($asset = $getRecord()->mediaAssets()->orderByPivot('is_featured', 'desc')->orderByPivot('position')->first())
@if ($asset)
    <figure>
        <img src="{{ $asset->thumbnail_url }}" alt="{{ $asset->pivot->custom_alt ?: $asset->alt_text }}" style="max-width:320px;border-radius:8px;">
        <figcaption>Alt: {{ $asset->pivot->custom_alt ?: ($asset->alt_text ?: '—') }}</figcaption>
    </figure>
@else
    <p>Chưa có ảnh.</p>
@endif
