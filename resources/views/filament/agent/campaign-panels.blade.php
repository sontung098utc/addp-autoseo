<div class="grid gap-4 mt-6 md:grid-cols-2">
    <div>
        <h2 class="text-base font-semibold mb-2">Đang chờ bạn bổ sung (không chặn việc khác)</h2>
        <x-filament::section>
            @forelse ($waits as $w)
                <div class="flex items-start justify-between gap-3 py-2">
                    <div>
                        <div class="font-medium">{{ $w->campaign?->name }}: {{ $w->title }}</div>
                        <div class="text-xs text-gray-500">{{ $w->detail }} Hết hạn lúc {{ \App\Filament\Support\Schedule\VnTime::format($w->expires_at) }} (giờ VN).</div>
                    </div>
                    <x-filament::button size="xs" color="gray" wire:click="dismissWait({{ $w->id }})">Bỏ qua</x-filament::button>
                </div>
            @empty
                <div class="text-sm text-gray-500">Không có gì đang chờ bạn.</div>
            @endforelse
            @foreach ($expired as $w)
                <div class="text-xs text-gray-500 py-1">Đã tự bỏ qua: {{ $w->limit_note }}</div>
            @endforeach
        </x-filament::section>
    </div>
    <div>
        <h2 class="text-base font-semibold mb-2">Hạn mức hôm nay</h2>
        <x-filament::section>
            @php($b = $budget)
            <div class="flex justify-between text-sm"><span>Chi phí AI</span><b>{{ number_format($b['cost'], 0, ',', '.') }} đ / {{ number_format($b['cost_cap'], 0, ',', '.') }} đ</b></div>
            <div class="h-1.5 rounded bg-gray-200 my-2"><div class="h-1.5 rounded bg-primary-600" style="width: {{ $b['cost_pct'] }}%"></div></div>
            <div class="flex justify-between text-sm"><span>Bài đẩy nháp</span><b>{{ $b['drafts'] }} / {{ $b['draft_cap'] }}</b></div>
            <div class="h-1.5 rounded bg-gray-200 my-2"><div class="h-1.5 rounded bg-primary-600" style="width: {{ $b['draft_pct'] }}%"></div></div>
            <div class="text-xs text-gray-500 mt-2">Cộng các chiến dịch đang chạy. Giới hạn bài/ngày giúp tránh đăng hàng loạt. "Bài đẩy nháp" đang đếm bài đạt luật và vào hàng chờ duyệt; việc đẩy lên CMS chưa nối (AG-S04).</div>
        </x-filament::section>
    </div>
</div>
