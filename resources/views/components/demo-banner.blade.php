@props(['source' => 'nguồn dữ liệu thật', 'real' => null])
@if (\App\Filament\Support\Demo\DemoMode::enabled())
    <div class="ss-demo-banner" role="note" style="border:1px solid var(--ss-border-strong,#7E828B);border-left-width:4px;border-radius:6px;padding:.6rem .85rem;margin-bottom:1rem;font-size:.8125rem;line-height:1.45">
        <strong>Dữ liệu minh họa</strong> — chưa kết nối {{ $source }}.
        @if ($real)
            <span>Thật: {{ $real }}.</span>
        @endif
        <span>Thao tác nào ghi rõ "thật" mới tạo/ghi dữ liệu thật; còn lại chỉ chạy trên dữ liệu mẫu và sẽ chuyển sang dữ liệu thật khi có nguồn.</span>
    </div>
@endif
