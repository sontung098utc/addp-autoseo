<x-filament-panels::page>
    <style>
        .bb-in{border:1px solid rgba(128,128,128,.5);border-radius:.4rem;padding:.35rem .55rem;background:transparent}
        .bb-row{display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:.75rem}
        .bb-row label{display:flex;flex-direction:column;gap:.2rem;font-size:.85rem}
        .bb-t{width:100%;border-collapse:collapse;font-size:.9rem}
        .bb-t th,.bb-t td{text-align:left;padding:.35rem .5rem;border-bottom:1px solid rgba(128,128,128,.25);vertical-align:top}
    </style>
    <div class="ss-page">
        <div class="ss-alert ss-alert--info" role="note" data-testid="bb-note">{{ $this->note() }}</div>
        <div class="ss-alert ss-alert--info" role="note" style="margin-top:.5rem">Chạy nền và chạy theo lịch chưa có: lượt xây chạy ngay trong trang và dừng nếu bạn đóng trang giữa chừng.</div>

        <div class="ss-card" style="margin-top:1rem"><div class="ss-card__b">
            <div class="ss-card__h">Xây kho</div>
            <div class="bb-row">
                <label>Website
                    <select class="bb-in" wire:model.live="websiteId">
                        @foreach ($sites as $s)<option value="{{ $s->id }}">{{ $s->name }} ({{ $s->base_url }})</option>@endforeach
                    </select>
                </label>
                <label>Số trang tối đa (tối đa 50)
                    <input class="bb-in" type="number" min="1" max="50" wire:model.live="maxPages" style="width:8rem">
                </label>
            </div>
            <label style="display:flex;flex-direction:column;gap:.2rem;font-size:.85rem">Thêm URL (mỗi dòng một URL, chỉ tên miền của website)
                <textarea class="bb-in" rows="3" wire:model="urlsText" placeholder="https://..."></textarea>
            </label>
            <div class="bb-row" style="margin-top:.75rem">
                <x-filament::button wire:click="confirmBuild" wire:loading.attr="disabled" :disabled="$off !== null">Bắt đầu xây kho</x-filament::button>
                <span wire:loading wire:target="build" class="ss-muted ss-text-sm">Đang đọc trang, vui lòng chờ…</span>
                @if ($off)<span class="ss-muted ss-text-sm" data-testid="bb-off">{{ $off }}</span>@elseif ($avDemo)<x-studio.badge tone="warn">AI giả lập (local)</x-studio.badge>@elseif ($avLabel)<x-studio.badge tone="ok">{{ $avLabel }}</x-studio.badge>@endif
            </div>
        </div></div>

        @if ($result)
            <div class="ss-card" style="margin-top:1rem" data-testid="bb-result"><div class="ss-card__b">
                <div class="ss-card__h">Kết quả lượt vừa chạy @if ($result['demo'])<x-studio.badge tone="warn">AI giả lập</x-studio.badge>@endif</div>
                <p>Đọc {{ $result['pages_read'] }} trang · bỏ qua {{ $result['pages_skipped'] }} trang (không đổi hoặc quá ít chữ) · lỗi {{ $result['pages_failed'] }} trang · kế hoạch {{ $result['pages_planned'] }} trang.</p>
                <p>{{ $result['items_auto'] }} mục tự nhận · <a href="{{ $reviewUrl }}" wire:navigate>{{ $result['items_proposed'] }} mục chờ duyệt (mở Kho trí tuệ để duyệt)</a>.</p>
                <p class="ss-muted ss-text-sm">Chi phí ước lượng {{ number_format($result['cost_vnd'], 0, ',', '.') }} ₫. Con số là ước lượng theo giá cấu hình, không phải hóa đơn.</p>
                @if ($result['message'])<div class="ss-alert ss-alert--warn" role="status">{{ $result['message'] }}</div>@else<p class="ss-muted ss-text-sm">Dừng vì: hết danh sách trang.</p>@endif
                @foreach ($result['rejected_urls'] as $u)<p class="ss-muted ss-text-xs">Bỏ URL không hợp lệ: {{ $u }}</p>@endforeach
            </div></div>
        @endif

        <div class="ss-card" style="margin-top:1rem"><div class="ss-card__b">
            <div class="ss-card__h">Mục đã tự nhận ({{ $autoItems->count() }})</div>
            @if ($autoItems->isEmpty())
                <p class="ss-muted ss-text-sm">Chưa có mục nào tự nhận.</p>
            @else
                <table class="bb-t"><thead><tr><th>Mục</th><th>Loại</th><th>Căn cứ</th><th></th></tr></thead><tbody>
                @foreach ($autoItems as $it)
                    <tr>
                        <td><strong>{{ $it->title }}</strong> <x-studio.badge tone="info">tự nhận</x-studio.badge><br><span class="ss-muted ss-text-xs">{{ \Illuminate\Support\Str::limit($it->body, 160) }}</span></td>
                        <td>{{ \App\Models\KnowledgeItem::TYPES[$it->type] ?? $it->type }}</td>
                        <td class="ss-muted ss-text-xs">{{ \Illuminate\Support\Str::limit((string) $it->source_span, 120) }}</td>
                        <td><x-filament::button size="xs" color="gray" wire:click="undo({{ $it->id }})">Hoàn tác</x-filament::button></td>
                    </tr>
                @endforeach
                </tbody></table>
            @endif
        </div></div>

        <div class="ss-card" style="margin-top:1rem"><div class="ss-card__b">
            <div class="ss-card__h">Các lượt xây gần đây</div>
            @if ($runs === [])
                <p class="ss-muted ss-text-sm">Chưa có lượt xây nào.</p>
            @else
                <table class="bb-t"><thead><tr><th>Lúc</th><th>Trang</th><th>Mục</th><th>Chi phí</th><th>Trạng thái</th></tr></thead><tbody>
                @foreach ($runs as $r)
                    <tr><td>{{ $r['at'] }}</td><td>{{ $r['pages'] }}</td><td>{{ $r['items'] }}</td><td>{{ number_format($r['cost'], 0, ',', '.') }} ₫</td><td>{{ $statusLabels[$r['status']] ?? $r['status'] }}@if ($r['reason'])<br><span class="ss-muted ss-text-xs">{{ $r['reason'] }}</span>@endif</td></tr>
                @endforeach
                </tbody></table>
            @endif
        </div></div>
    </div>
</x-filament-panels::page>
