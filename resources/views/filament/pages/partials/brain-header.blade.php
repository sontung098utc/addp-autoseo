{{-- AG-B1: đầu trang một Trí tuệ. Mọi chuỗi qua {{ }} (escape). --}}
<div style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;margin-bottom:.8rem">
    <a href="{{ \App\Filament\Pages\BrainLibraryPage::getUrl() }}" class="ss-muted ss-text-xs" style="text-decoration:underline">← Kho trí tuệ</a>
    <strong style="font-size:1.1rem">{{ $brain->name }}</strong>
    @if (! $brain->isActive())<x-studio.badge tone="muted">Đã lưu trữ</x-studio.badge>@endif
    <x-studio.badge tone="info">{{ $reliances[$brain->reliance()] ?? $brain->reliance() }}</x-studio.badge>
    <x-studio.hint text="Mức dựa vào AI: Tự do = AI dùng kiến thức riêng, kho chỉ định hướng giọng và cấu trúc. Kiểm số liệu = số liệu, tên, mốc thời gian phải khớp kho. Bắt buộc nguồn = lĩnh vực nhạy cảm, cần người có chuyên môn duyệt. Bản này mới lưu, truyền cho AI và ghi nhật ký; việc tự kiểm từng khẳng định làm ở bước sau." />
    @unless ($canEdit)
        <span class="ss-muted ss-text-xs">Chỉ xem <x-studio.hint text="Hiện admin và người duyệt được sửa. Quyền duyệt kiến thức sẽ tính sau theo yêu cầu." /></span>
    @endunless
</div>
