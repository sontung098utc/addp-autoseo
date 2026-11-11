<x-filament-widgets::widget>
@if ($rows->isNotEmpty())
<x-filament::section heading="Hoạt động gần đây">
<style>.ss-dash-row{display:flex;justify-content:space-between;gap:.75rem;padding:.5rem 0;border-bottom:1px solid rgba(128,128,128,.2)}.ss-dash-rule{font-size:.8rem;opacity:.7;margin-bottom:.5rem}.ss-dash-badge{font-size:.7rem;padding:.1rem .45rem;border-radius:999px;background:rgba(220,38,38,.15);color:#b91c1c;font-weight:600}.ss-dash-empty{opacity:.7;padding:.5rem 0}.ss-dash-sub{font-size:.8rem;opacity:.7}</style>
@foreach ($rows as $r)
<div class="ss-dash-row">
<div>{{ $r['reviewer'] ?? '—' }}: {{ \App\Filament\Support\StatusPresenter::label($r['status']) }} · <a href="{{ \App\Filament\Resources\ArticleResource::getUrl('view', ['record' => $r['article_id']]) }}">{{ $r['keyword'] }}</a>@if ($r['note'])<div class="ss-dash-sub">{{ $r['note'] }}</div>@endif</div>
<span class="ss-dash-sub">{{ $r['at'] }}</span>
</div>
@endforeach
</x-filament::section>
@endif
</x-filament-widgets::widget>
