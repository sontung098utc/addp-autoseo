@php
    $record = $getRecord();
    $others = \App\Models\ArticleVersion::where('article_id', $record->article_id)->orderByDesc('version')->get(['id', 'version', 'review_status', 'created_at']);
@endphp
<ul style="list-style:disc;padding-left:1.25rem;">
    @foreach ($others as $v)
        <li>
            @if ($v->id === $record->id)
                <strong>v{{ $v->version }} (đang xem)</strong>
            @else
                <a href="{{ \App\Filament\Resources\ArticleVersionResource::getUrl('view', ['record' => $v]) }}" style="text-decoration:underline;">v{{ $v->version }}</a>
            @endif
            — {{ \App\Filament\Resources\ArticleVersionResource::reviewLabel($v->review_status) }} — {{ $v->created_at?->format('d/m/Y H:i') }}
        </li>
    @endforeach
</ul>
