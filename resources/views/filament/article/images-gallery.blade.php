{{-- Nhúng: @include('filament.article.images-gallery', ['version' => $version]) với $version là App\Models\ArticleVersion. --}}
@php
    $imgs = \App\Filament\Support\Article\ImageGalleryData::forVersion($version);
    $why = $imgs === [] ? \App\Filament\Support\Article\ImageGalleryData::skipReason($version) : null;
@endphp
<div class="img-gal" style="margin-top:12px">
    <style>
        .img-gal-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px}
        .img-gal-item{position:relative;border:1px solid rgba(128,128,128,.3);border-radius:8px;overflow:hidden}
        .img-gal-item img{display:block;width:100%;aspect-ratio:4/3;object-fit:cover}
        .img-gal-tag{position:absolute;top:6px;left:6px;font-size:11px;padding:2px 6px;border-radius:4px;background:rgba(0,0,0,.7);color:#fff}
        .img-gal-meta{font-size:12px;padding:6px 8px;opacity:.85}
        .img-gal-meta summary{cursor:pointer}
    </style>
    <div style="font-weight:600;margin-bottom:6px">Hình ảnh của phiên bản này</div>
    @if ($imgs === [])
        <p style="font-size:13px;opacity:.8">Phiên bản này chưa có ảnh.{{ $why ? ' Lý do: '.$why : ' Chưa có bước tạo ảnh nào ghi lại lý do.' }}</p>
    @else
        <div class="img-gal-grid">
            @foreach ($imgs as $i)
                <div class="img-gal-item" @if ($i['ai'] && $i['prompt']) title="Prompt: {{ $i['prompt'] }}" @endif>
                    <img src="{{ $i['thumb'] ?: $i['url'] }}" alt="{{ $i['alt'] }}" loading="lazy">
                    <span class="img-gal-tag">{{ $i['ai'] ? 'AI tạo' : 'Từ thư viện' }}{{ $i['role'] === 'featured' ? ' · Ảnh bìa' : '' }}</span>
                    <div class="img-gal-meta">
                        {{ $i['alt'] }}
                        @if ($i['ai'])
                            <details><summary>Prompt</summary>{{ $i['prompt'] }}<br>Model: {{ $i['model'] }}@if ($i['cost_vnd'] !== null) · {{ number_format($i['cost_vnd'], 0, ',', '.') }}đ @endif</details>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
