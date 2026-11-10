@if ($j)
<div data-testid="job-detail" class="space-y-3 text-sm">
    <div>Mắt xích: {{ $chain[$j['link']] ?? $j['link'] }}@foreach ((array) $j['also'] as $l), {{ $chain[$l] ?? $l }}@endforeach</div>
    @if ($url)<div><x-filament::button size="sm" tag="a" :href="$url">Mở trang làm việc này</x-filament::button></div>@endif
    @if ($j['human'])<div><strong>Việc bạn cần làm:</strong>@foreach ($j['human'] as $h)<div style="margin-left:.5rem">• {{ $h }}</div>@endforeach</div>@endif
    <div><strong>Kết quả hiện ở:</strong> {{ collect($j['shown_at'])->map(fn ($x) => trim(preg_replace('/\s*\(.*?\)/u', '', $x)))->implode('; ') ?: 'chưa rõ' }}</div>
    <div><strong>Ai dùng kết quả tiếp:</strong> {{ collect($j['used_by'])->map(fn ($u) => $u === 'human' ? 'Bạn xem và duyệt' : ($names[$u] ?? $u))->implode(', ') ?: 'chưa rõ' }}</div>
    <div><strong>Các bước:</strong>
        @forelse ($j['steps'] as $s)
            <div style="margin-left:.5rem">• {{ $s['label'] }} · {{ $s['kind'] === 'llm' ? 'llm (tốn token)' : 'rule' }}@if (! empty($s['planned'])) · Chưa chạy @endif @if (! empty($s['when'])) · {{ $s['when'] }} @endif</div>
        @empty chưa khai @endforelse
    </div>
    @if ($j['budget'])<div><strong>Ngân sách:</strong> {{ $j['budget'] }}</div>@endif
    <details data-testid="job-tech"><summary style="cursor:pointer;font-weight:600">Chi tiết kỹ thuật (dành cho quản trị)</summary>
    <div class="space-y-2 text-xs" style="margin-top:.4rem">
        <div><strong>Trạng thái mã:</strong> {{ $j['status'] }}</div>
        <div>{{ $j['summary'] }}</div>
    <div><strong>Đọc:</strong> {{ implode(', ', $j['reads']) ?: 'chưa khai' }}</div>
    <div><strong>Ghi:</strong> {{ implode(', ', $j['writes']) ?: 'chưa khai' }}</div>
    <div><strong>Hiện (đầy đủ):</strong> {{ implode('; ', $j['shown_at']) ?: 'chưa khai' }}</div>
    <div><strong>Dùng tiếp (mã):</strong> {{ collect($j['used_by'])->map(fn ($u) => $names[$u] ?? $u)->implode(', ') ?: 'chưa khai' }}</div>
    <div><strong>Điểm người can thiệp:</strong>
        @forelse ($j['human'] as $h)<div style="margin-left:.5rem">• {{ $h }}</div>@empty không có @endforelse
    </div>
    <div><strong>Tính năng liên quan:</strong> {{ implode(', ', $j['features']) ?: 'chưa có' }}</div>
    </div></details>
    <div class="text-xs" style="opacity:.7">Chạy loại việc: chưa nối ở tab này.</div>
</div>
@endif
