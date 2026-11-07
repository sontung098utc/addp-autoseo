<x-filament-panels::page>
    <div data-testid="ai-connection" class="space-y-4">
        <x-filament::section>
            <div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center">
                <x-filament::badge :color="$ai['connected'] ? 'success' : 'warning'">{{ $ai['connected'] ? 'Đã có khóa AI' : 'Chưa có khóa AI' }}</x-filament::badge>
                @if ($ai['connected'])
                    <x-filament::badge color="gray">Model {{ $ai['model'] }}</x-filament::badge>
                    <x-filament::badge color="gray">{{ $ai['source'] === 'db' ? 'Khóa lưu trong hệ thống' : 'Khóa từ biến môi trường' }} {{ $ai['key_hint'] }}</x-filament::badge>
                    @if ($ai['tested'] === true)<x-filament::badge color="success">Đã kiểm tra: dùng được</x-filament::badge>
                    @elseif ($ai['tested'] === false)<x-filament::badge color="danger">Kiểm tra lỗi: {{ $ai['test_message'] }}</x-filament::badge>
                    @else<x-filament::badge color="gray">Chưa kiểm tra kết nối</x-filament::badge>@endif
                @endif
            </div>
            <p class="mt-2 text-xs" style="opacity:.75">
                Chưa có khóa thì lượt tự động <strong>không chạy</strong> (hệ thống không dùng AI giả lập một cách im lặng). Mọi bài do AI viết đều dừng ở <strong>bản nháp chờ duyệt</strong>.
                @unless ($isAdmin) Chỉ quản trị viên được nhập hoặc đổi khóa. @endunless
            </p>
        </x-filament::section>
        <form wire:submit="save">{{ $this->form }}</form>
    </div>
</x-filament-panels::page>
