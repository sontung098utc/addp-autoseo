@if ($s)
<div data-testid="step-detail" class="space-y-3 text-sm">
    <div><strong>{{ $s['n'] }} · {{ $s['label'] }}</strong>
        <span class="pv-tag">{{ $s['kind'] === 'llm' ? 'AI'.($s['tier'] ? ' · '.$s['tier'] : '') : 'Luật, không tốn token' }}</span>
        @if ($s['planned'])<span class="pv-tag pv-tag--warn">Chưa chạy</span>@endif
        @if ($s['human'])<span class="pv-tag pv-tag--warn">Người can thiệp</span>@endif
    </div>
    <div><strong>Ai làm:</strong> {{ $s['profile']['name'] }}, {{ $s['profile']['title'] }}</div>
    <div><strong>Khi nào bỏ qua / điều kiện:</strong> {{ $s['when'] ?: 'Luôn chạy.' }}</div>
    <div><strong>Số liệu 30 ngày:</strong>
        @if ($stats)
            {{ $stats['runs'] }} lần chạy · {{ $stats['ok'] }} đạt, {{ $stats['warn'] }} cảnh báo, {{ $stats['failed'] }} lỗi · chi phí trung bình {{ number_format($stats['avg_cost'], 0, ',', '.') }} ₫
        @else
            Chưa có lần chạy nào.
        @endif
    </div>
</div>
@endif
