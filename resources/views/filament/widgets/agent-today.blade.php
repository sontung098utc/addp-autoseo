<x-filament-widgets::widget>
    <x-filament::section heading="Đội AI hôm nay">
        <style>.at-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(9.5rem,1fr));gap:.6rem}.at-card{border:1px solid rgba(128,128,128,.28);border-radius:.6rem;padding:.55rem .7rem;display:block;text-decoration:none;color:inherit}.at-card b{display:block;font-size:1.35rem;line-height:1.2}.at-card span{font-size:.78rem;opacity:.75}.at-warn b{color:#b45309}</style>
        <div class="at-grid" data-testid="agent-today">
            <a class="at-card" href="{{ $urls['pipeline'] ?? '#' }}"><b>{{ $running }}</b><span>đang chạy (xem trên Pipeline)</span></a>
            <a class="at-card @if ($waiting) at-warn @endif" href="{{ $urls['review'] }}"><b>{{ $waiting }}</b><span>bài chờ duyệt</span></a>
            <a class="at-card @if ($expert) at-warn @endif" href="{{ $urls['review'] }}"><b>{{ $expert }}</b><span>chờ người có chuyên môn xem</span></a>
            <a class="at-card @if ($blocked) at-warn @endif" href="{{ $urls['runs'] }}"><b>{{ $blocked }}</b><span>phiên bị chặn hoặc lỗi hôm nay</span></a>
            <a class="at-card" href="{{ $urls['kb'] ?? '#' }}"><b>{{ $suggestions }}</b><span>gợi ý bổ sung kho</span></a>
            <a class="at-card" href="{{ $urls['cost'] ?? '#' }}"><b>{{ number_format($cost, 0, ',', '.') }} ₫</b><span>chi phí AI hôm nay ({{ $runs }} phiên)</span></a>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
