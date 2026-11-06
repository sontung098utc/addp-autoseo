@php
    use App\Filament\Support\Gsc\PageArticleLinker;
    $link = $selected ? ($selDim === 'page' ? PageArticleLinker::find($selected['key'], $links) : PageArticleLinker::find($selected['page'], $links)) : null;
    $tones = ['tut-hang' => 'danger', 'ctr-thap' => 'warn', 'co-hoi-trang-1' => 'info'];
@endphp
<div class="ss-card" aria-live="polite">
    @if ($selected)
        <div class="ss-card__h">{{ $selected['key'] }}</div>
        <div class="ss-card__b" style="display:grid;gap:.75rem">
            <div class="ss-text-sm">Clicks {{ $n($selected['cur']['clicks']) }} · Impressions {{ $n($selected['cur']['impressions']) }} · CTR {{ $pct($selected['cur']['ctr']) }} · Vị trí {{ $n($selected['cur']['position'], 1) }}</div>
            @if ($selDim === 'page' || $selDim === 'query')
                <div class="ss-text-sm">Bài liên kết: @if ($link)<a href="{{ $link['url'] }}">{{ $link['title'] }}</a>@else <span class="ss-muted">Chưa liên kết bài</span> @endif</div>
            @endif
            @foreach ($selectedOpps as $o)
                <div class="ss-text-sm"><x-studio.badge :tone="$tones[$o['rule']] ?? 'muted'">{{ $o['label'] }}</x-studio.badge> {{ $o['reason'] }}</div>
            @endforeach
            <div>
                <x-studio.badge tone="accent">AI đề xuất</x-studio.badge>
                <ul class="ss-text-sm" style="margin:.5rem 0 0;padding-left:1.2rem;display:grid;gap:.25rem">@foreach (\App\Filament\Support\Gsc\AiSuggestion::for($selected['key'], array_column($selectedOpps, 'rule')) as $sg)<li>{{ $sg }}</li>@endforeach</ul>
                <div class="ss-text-xs ss-muted" style="margin-top:.5rem">Nguồn: số liệu Search Console của mục này</div>
            </div>
        </div>
    @else
        <div class="ss-card__b ss-text-sm ss-muted">Chọn một dòng để xem chi tiết.</div>
    @endif
</div>
