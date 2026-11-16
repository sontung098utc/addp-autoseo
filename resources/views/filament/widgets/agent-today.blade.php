@php
    $cards = array_values(array_filter([
        $waiting > 0 ? ['n' => $waiting, 'l' => 'bài chờ duyệt', 'u' => $urls['review'], 'warn' => true] : null,
        $expert > 0 ? ['n' => $expert, 'l' => 'chờ người có chuyên môn xem', 'u' => $urls['review'], 'warn' => true] : null,
        $isApprover && $blocked > 0 ? ['n' => $blocked, 'l' => 'phiên bị chặn hoặc lỗi hôm nay', 'u' => $urls['runs'], 'warn' => true] : null,
        $running > 0 ? ['n' => $running, 'l' => 'đang chạy', 'u' => $urls['pipeline'] ?? '#', 'warn' => false] : null,
    ]));
@endphp
<x-filament-widgets::widget>
    @if ($cards !== [] || ($isApprover && ($cost > 0 || $suggestions > 0)))
    <x-filament::section heading="Đội AI hôm nay">
        <style>.at-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(9.5rem,1fr));gap:.6rem}.at-card{border:1px solid rgba(128,128,128,.28);border-radius:.6rem;padding:.55rem .7rem;display:block;text-decoration:none;color:inherit}.at-card b{display:block;font-size:1.35rem;line-height:1.2}.at-card span{font-size:.78rem;opacity:.75}.at-warn b{color:#b45309}.at-foot{margin-top:.6rem;font-size:.8rem;opacity:.8;display:flex;gap:1rem;flex-wrap:wrap}.at-foot a{text-decoration:underline}</style>
        <div data-testid="agent-today">
            @if ($cards !== [])
                <div class="at-grid">
                    @foreach ($cards as $c)
                        <a class="at-card @if ($c['warn']) at-warn @endif" href="{{ $c['u'] }}"><b>{{ $c['n'] }}</b><span>{{ $c['l'] }}</span></a>
                    @endforeach
                </div>
            @endif
            @if ($isApprover && ($cost > 0 || $suggestions > 0))
                <div class="at-foot">
                    @if ($cost > 0)<a href="{{ $urls['cost'] ?? '#' }}">Chi phí AI hôm nay {{ number_format($cost, 0, ',', '.') }} ₫ ({{ $runs }} phiên)</a>@endif
                    @if ($suggestions > 0)<a href="{{ $urls['kb'] ?? '#' }}">{{ $suggestions }} gợi ý bổ sung kho</a>@endif
                </div>
            @endif
        </div>
    </x-filament::section>
    @endif
</x-filament-widgets::widget>
