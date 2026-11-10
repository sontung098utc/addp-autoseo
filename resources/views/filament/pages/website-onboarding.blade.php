<x-filament-panels::page>
    <x-chain-context step="Thiết lập"
        purpose="Chạy một lần cho website mới: đội AI tự đọc website công ty để xây kho và điền hồ sơ doanh nghiệp, gắn Trí tuệ theo lĩnh vực, khảo sát từ khóa và gợi ý bài nên viết trước. Không tạo bài và không giao việc viết."
        :inputs="[['label' => 'Website kết nối', 'note' => 'website đang hoạt động']]"
        :outputs="[['label' => 'Kho kiến thức', 'url' => $links['kb']], ['label' => 'Từ khóa', 'url' => $links['keywords']], ['label' => 'Kế hoạch nội dung', 'url' => $links['plan']]]" />
    <div class="ss-page">
        <div class="ss-card"><div class="ss-card__b">
            <div class="ss-card__h">Khởi tạo website bằng một nút</div>
            <p class="ss-muted ss-text-sm">Cần kết nối AI để đọc website và khảo sát từ khóa; bước nào chưa làm được sẽ được bỏ qua và nói rõ lý do, các bước còn lại vẫn chạy. Chạy ngay trong trang, có thể mất vài chục giây đến vài phút.</p>
            <div style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap">
                <label style="display:flex;flex-direction:column;gap:.2rem;font-size:.85rem">Website
                    <select wire:model="websiteId" style="border:1px solid rgba(128,128,128,.5);border-radius:.4rem;padding:.35rem .55rem;background:transparent" data-testid="ob-site">
                        @foreach ($websites as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach
                    </select>
                </label>
                <x-filament::button wire:click="start" wire:loading.attr="disabled" data-testid="ob-start">Bắt đầu khởi tạo</x-filament::button>
                <span wire:loading wire:target="start" class="ss-muted ss-text-sm">Đang chạy…</span>
            </div>
        </div></div>
        @if ($result)
            <div class="ss-card" data-testid="ob-result"><div class="ss-card__b">
                <div class="ss-card__h">Kết quả</div>
                <ol style="margin:0 0 0 1.1rem">
                    @foreach ($result['steps'] as $s)
                        <li style="margin:.35rem 0"><strong>{{ $s['label'] }}</strong>
                            <x-studio.badge :tone="$s['status'] === 'ok' ? 'ok' : ($s['status'] === 'failed' ? 'danger' : 'warn')">{{ ['ok' => 'Xong', 'skipped' => 'Bỏ qua', 'failed' => 'Lỗi'][$s['status']] }}</x-studio.badge><br>
                            <span class="ss-muted ss-text-sm">{{ $s['message'] }}</span></li>
                    @endforeach
                </ol>
            </div></div>
        @endif
    </div>
</x-filament-panels::page>
