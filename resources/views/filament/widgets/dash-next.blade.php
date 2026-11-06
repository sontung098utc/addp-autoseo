<x-filament-widgets::widget>
<x-filament::section heading="Giờ đăng kế tiếp">
<style>.ss-dash-row{display:flex;justify-content:space-between;gap:.75rem;padding:.5rem 0;border-bottom:1px solid rgba(128,128,128,.2)}.ss-dash-rule{font-size:.8rem;opacity:.7;margin-bottom:.5rem}.ss-dash-badge{font-size:.7rem;padding:.1rem .45rem;border-radius:999px;background:rgba(220,38,38,.15);color:#b91c1c;font-weight:600}.ss-dash-empty{opacity:.7;padding:.5rem 0}.ss-dash-sub{font-size:.8rem;opacity:.7}</style>
<div class="ss-dash-rule">Mỗi website đang hoạt động: lượt đăng đã xếp lịch gần nhất. Hiển thị giờ Việt Nam (UTC+7) và UTC.</div>
@forelse ($rows as $r)
<div class="ss-dash-row">
<span>{{ $r['website'] }}</span>
@if ($r['vn'])<span>{{ $r['vn'] }} (VN) · {{ $r['utc'] }} UTC <span class="ss-dash-sub">· còn {{ $r['in'] }}</span></span>@else<span class="ss-dash-sub">Chưa có lịch</span>@endif
</div>
@empty
<div class="ss-dash-empty">Chưa có website nào đang hoạt động.</div>
@endforelse
</x-filament::section>
</x-filament-widgets::widget>
