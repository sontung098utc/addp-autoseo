<x-filament-widgets::widget>
<x-filament::section :heading="$isApprover ? 'Cần bạn quyết định' : 'Việc của bạn'">
<style>.ss-dash-row{display:flex;justify-content:space-between;gap:.75rem;padding:.5rem 0;border-bottom:1px solid rgba(128,128,128,.2)}.ss-dash-rule{font-size:.8rem;opacity:.7;margin-bottom:.5rem}.ss-dash-badge{font-size:.7rem;padding:.1rem .45rem;border-radius:999px;background:rgba(220,38,38,.15);color:#b91c1c;font-weight:600}.ss-dash-empty{opacity:.7;padding:.5rem 0}.ss-dash-sub{font-size:.8rem;opacity:.7}</style>
<div class="ss-dash-rule">{{ $isApprover ? 'Quá hạn = chờ duyệt hơn '.$overdueHours.' giờ (tính từ lúc tạo phiên bản). Quá hạn xếp trước, rồi chờ lâu nhất.' : 'Bài của bạn bị trả lại (Cần sửa) hoặc chưa gửi duyệt.' }}</div>
@forelse ($rows as $r)
<div class="ss-dash-row">
<div>
@if ($r['version_id'])<a href="{{ \App\Filament\Resources\ArticleVersionResource::getUrl('view', ['record' => $r['version_id']]) }}">{{ $r['keyword'] }}</a>@else<span>{{ $r['keyword'] }}</span>@endif
<div class="ss-dash-sub">{{ $r['website'] }}@if ($r['note']) · Lý do: {{ $r['note'] }}@endif</div>
</div>
<div>@if ($r['overdue'])<span class="ss-dash-badge">Quá hạn</span> @endif<span class="ss-dash-sub">{{ $r['waiting_label'] }}</span></div>
</div>
@empty
<div class="ss-dash-empty">Không có việc nào cần bạn quyết.</div>
@endforelse
</x-filament::section>
</x-filament-widgets::widget>
