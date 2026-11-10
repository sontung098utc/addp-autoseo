@if ($j)
<div data-testid="job-detail" class="space-y-3 text-sm">
    <div>{{ $j['status'] === 'active' ? 'Đã có mã chạy' : 'Chưa có mã' }} · mắt xích: {{ $chain[$j['link']] ?? $j['link'] }}@foreach ((array) $j['also'] as $l), {{ $chain[$l] ?? $l }}@endforeach</div>
    <div>{{ $j['summary'] }}</div>
    <div><strong>Các bước:</strong>
        @forelse ($j['steps'] as $s)
            <div style="margin-left:.5rem">• {{ $s['label'] }} · {{ $s['kind'] === 'llm' ? 'llm (tốn token)' : 'rule' }}@if (! empty($s['planned'])) · Chưa chạy @endif @if (! empty($s['when'])) · {{ $s['when'] }} @endif</div>
        @empty chưa khai @endforelse
    </div>
    <div><strong>Đọc:</strong> {{ implode(', ', $j['reads']) ?: 'chưa khai' }}</div>
    <div><strong>Ghi:</strong> {{ implode(', ', $j['writes']) ?: 'chưa khai' }}</div>
    <div><strong>Hiện:</strong> {{ implode('; ', $j['shown_at']) ?: 'chưa khai' }}</div>
    <div><strong>Ai dùng tiếp:</strong> {{ collect($j['used_by'])->map(fn ($u) => $names[$u] ?? $u)->implode(', ') ?: 'chưa khai' }}</div>
    <div><strong>Điểm người can thiệp:</strong>
        @forelse ($j['human'] as $h)<div style="margin-left:.5rem">• {{ $h }}</div>@empty không có @endforelse
    </div>
    <div><strong>Tính năng liên quan:</strong> {{ implode(', ', $j['features']) ?: 'chưa có' }}</div>
    @if ($j['budget'])<div><strong>Ngân sách:</strong> {{ $j['budget'] }}</div>@endif
    <div class="text-xs" style="opacity:.7">Chạy loại việc: chưa nối ở tab này.</div>
</div>
@endif
