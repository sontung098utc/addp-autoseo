@if ($stop)
    <div role="alert" data-testid="stop-banner" style="border:1px solid rgba(220,38,38,.45);background:rgba(220,38,38,.09);border-radius:.5rem;padding:.6rem .85rem;margin-bottom:1rem;font-size:.875rem">
        <strong>Đang dừng xuất bản
            @if ($stop['scope'] === 'sites') ({{ implode(', ', $stop['sites']) }}) @else (toàn hệ thống) @endif.</strong>
        Mọi lượt đăng liên quan đang tạm hoãn cho tới khi khôi phục. Lý do: {{ $stop['reason'] }}@if ($stop['by']) · bởi {{ $stop['by'] }}@endif.
        @if ($canOpenSchedule)
            <a href="{{ \App\Filament\Resources\PublicationResource::getUrl('index') }}" class="underline">Mở Lịch xuất bản để khôi phục</a>
        @endif
    </div>
@endif
