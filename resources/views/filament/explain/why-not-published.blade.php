@php
    $colors = ['ok' => 'success', 'blocking' => 'danger', 'waiting' => 'warning', 'info' => 'gray'];
    $labels = ['ok' => 'Không chặn', 'blocking' => 'Đang chặn', 'waiting' => 'Đang chờ', 'info' => 'Thông tin'];
    $verdictColor = ['done' => 'success', 'waiting' => 'warning', 'blocked' => 'danger', 'failed' => 'danger'][$result['verdict']] ?? 'gray';
@endphp
<div class="space-y-4" data-testid="why-not-published">
    <div>
        <x-filament::badge :color="$verdictColor">{{ $result['headline'] }}</x-filament::badge>
        <div class="mt-2 text-sm" style="opacity:.75">{{ $result['subject'] }}</div>
    </div>

    <div style="border:1px solid rgba(128,128,128,.25);border-radius:.5rem;overflow:hidden">
        @foreach ($result['checks'] as $i => $c)
            <div style="display:grid;grid-template-columns:1.2fr 1.4fr auto;gap:.75rem;align-items:start;padding:.6rem .8rem;border-bottom:1px solid rgba(128,128,128,.18);{{ $result['decisive'] === $i ? 'background:rgba(245,158,11,.10);' : '' }}">
                <div style="font-weight:600;font-size:.85rem">{{ $result['decisive'] === $i ? '● ' : '' }}{{ $c['label'] }}</div>
                <div style="font-size:.85rem">
                    {{ $c['value'] }}
                    <div style="font-size:.78rem;opacity:.7;margin-top:.15rem">{{ $c['detail'] }}</div>
                </div>
                <x-filament::badge :color="$colors[$c['state']] ?? 'gray'">{{ $labels[$c['state']] ?? $c['state'] }}</x-filament::badge>
            </div>
        @endforeach
    </div>

    <div class="text-sm"><strong>Bước tiếp theo:</strong> {{ $result['next'] }}</div>

    <div class="text-xs" style="opacity:.65">
        Giải thích dựa trên các luật đang chạy trong hệ thống (dừng xuất bản, trạng thái, website, giờ đăng, duyệt, kiểm chất lượng).
        Dòng có ● là điều đang quyết định. Các luật chưa có trong hệ thống (ví dụ nhóm chủ đề nhạy cảm) chưa được hiển thị ở đây.
    </div>
</div>
