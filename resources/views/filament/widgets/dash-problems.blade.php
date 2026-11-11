<x-filament-widgets::widget>
@if ($visible && $rows->isNotEmpty())
<x-filament::section heading="Lỗi & chờ đối soát">
<style>.ss-dash-row{display:flex;justify-content:space-between;gap:.75rem;padding:.5rem 0;border-bottom:1px solid rgba(128,128,128,.2)}.ss-dash-rule{font-size:.8rem;opacity:.7;margin-bottom:.5rem}.ss-dash-badge{font-size:.7rem;padding:.1rem .45rem;border-radius:999px;background:rgba(220,38,38,.15);color:#b91c1c;font-weight:600}.ss-dash-empty{opacity:.7;padding:.5rem 0}.ss-dash-sub{font-size:.8rem;opacity:.7}</style>
@foreach ($rows as $r)
<div class="ss-dash-row">
<div>
@if ($r['article_id'] ?? null)<a href="{{ \App\Filament\Resources\ArticleResource::getUrl('view', ['record' => $r['article_id']]) }}#tab-publish">{{ $r['keyword'] ?? '—' }}</a>@else<span>{{ $r['keyword'] ?? '—' }}</span>@endif · <a href="{{ \App\Filament\Resources\PublicationResource::getUrl('view', ['record' => $r['id']]) }}">lượt đăng</a>
<div class="ss-dash-sub">{{ $r['website'] }}@if ($r['error']) · {{ $r['error'] }}@endif</div>
</div>
<x-filament::badge :color="\App\Filament\Support\StatusPresenter::color($r['status'], 'publication')">{{ \App\Filament\Support\StatusPresenter::label($r['status'], 'publication') }}</x-filament::badge>
</div>
@endforeach
<div class="ss-dash-sub"><a href="{{ \App\Filament\Resources\PublicationResource::listUrl() }}">Xem tất cả lượt đăng</a></div>
</x-filament::section>
@endif
</x-filament-widgets::widget>
