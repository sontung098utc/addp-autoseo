@php
    use App\Filament\Support\Article\VersionDiff as D;
    $labels = ['same' => 'Giữ nguyên', 'changed' => 'Đã sửa', 'added' => 'Mới', 'removed' => 'Đã bỏ'];
@endphp
<div data-testid="aw-compare" style="display:grid;gap:1rem;font-size:.9rem">
    @if (! $diff)
        <p>Chưa có bản trước để so sánh.</p>
    @else
        @php $s = $diff['summary']; $qo = $s['quality_old']; $qn = $s['quality_new']; @endphp
        <p><strong>v{{ $old->version }} → v{{ $new->version }}</strong></p>
        <ul style="list-style:disc;padding-left:1.1rem">
            <li>Mục: {{ $s['sections_changed'] }} đã sửa · {{ $s['sections_added'] }} mới · {{ $s['sections_removed'] }} đã bỏ · thẻ meta đổi {{ $s['meta_changed'] }}</li>
            <li>Số từ: {{ $s['words_old'] }} → {{ $s['words_new'] }}</li>
            <li>Chưa đạt: P0 {{ $qo['P0'] ?? 0 }} → {{ $qn['P0'] ?? 0 }} · P1 {{ $qo['P1'] ?? 0 }} → {{ $qn['P1'] ?? 0 }} · P2 {{ $qo['P2'] ?? 0 }} → {{ $qn['P2'] ?? 0 }}</li>
        </ul>
        @if (($qn['P0'] ?? 0) > ($qo['P0'] ?? 0))
            <p data-testid="aw-compare-warn" style="border-left:3px solid #dc2626;padding:.4rem .7rem">Bản mới có nhiều lỗi chặn hơn bản trước.</p>
        @endif
        <section>
            <h3 style="font-weight:600">Thẻ meta đổi</h3>
            @if ($diff['meta'] === [])
                <p style="opacity:.75">Không đổi.</p>
            @else
                <table style="width:100%;border-collapse:collapse">
                    <thead><tr><th align="left">Trường</th><th align="left">Cũ</th><th align="left">Mới</th></tr></thead>
                    <tbody>
                        @foreach ($diff['meta'] as $m)
                            <tr><td style="vertical-align:top">{{ $m['label'] }}</td><td style="vertical-align:top">{{ $m['old'] ?? '—' }}</td><td style="vertical-align:top">{{ $m['new'] ?? '—' }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
        <section>
            <h3 style="font-weight:600">Các mục</h3>
            @foreach ($diff['sections'] as $sec)
                <div data-status="{{ $sec['status'] }}" style="border-top:1px solid rgba(128,128,128,.3);padding:.5rem 0">
                    <div><strong>{{ $sec['heading'] }}</strong> · {{ $labels[$sec['status']] }} · {{ $sec['old_words'] }} → {{ $sec['new_words'] }} từ</div>
                    @if ($sec['status'] === 'changed')
                        <div style="display:grid;gap:.75rem;grid-template-columns:1fr 1fr">
                            <div><div style="opacity:.75">Cũ</div>{{ D::excerpt($sec['old_html']) }}</div>
                            <div><div style="opacity:.75">Mới</div>{{ D::excerpt($sec['new_html']) }}</div>
                        </div>
                    @endif
                </div>
            @endforeach
        </section>
        <p style="opacity:.75">So sánh theo tiêu đề H2; chưa so từng câu.</p>
    @endif
</div>
