@php
    $fmt = fn ($b) => $b >= 1048576 ? round($b / 1048576, 1).' MB' : round($b / 1024).' KB';
    $row = 'display:flex;justify-content:space-between;gap:12px;padding:6px 0;border-bottom:1px solid rgba(127,127,127,.18);font-size:13px';
@endphp
<div style="display:flex;flex-direction:column;gap:16px">
    <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px">
        @foreach ([['Thiếu file', count($r['missing'])], ['File mồ côi', count($r['orphan_files'])], ['Trùng file', count($r['duplicates'])], ['Chưa dùng > 90 ngày', count($r['unused'])]] as [$label, $n])
            <div style="padding:10px 12px;border-radius:10px;border:1px solid rgba(127,127,127,.25)">
                <div style="font-size:22px;font-weight:700;color:{{ $n > 0 ? '#d97706' : 'inherit' }}">{{ $n }}</div>
                <div style="font-size:12px;opacity:.7">{{ $label }}</div>
            </div>
        @endforeach
    </div>
    @if ($r['missing'])
        <div><div style="font-weight:600;font-size:13px;margin-bottom:4px">Bản ghi mất file trên ổ lưu trữ (cần kiểm tra, không tự xóa)</div>
            @foreach (array_slice($r['missing'], 0, 8) as $m)<div style="{{ $row }}"><span>#{{ $m['id'] }} {{ $m['title'] }}</span><span style="opacity:.6">{{ $m['file_path'] }}</span></div>@endforeach</div>
    @endif
    @if ($r['orphan_files'])
        <div><div style="font-weight:600;font-size:13px;margin-bottom:4px">File không bản ghi nào dùng (chỉ báo cáo, không tự xóa)</div>
            @foreach (array_slice($r['orphan_files'], 0, 8) as $f)<div style="{{ $row }}"><span style="opacity:.8">{{ $f }}</span></div>@endforeach</div>
    @endif
    @if ($r['unused'])
        <div><div style="font-weight:600;font-size:13px;margin-bottom:4px">Ảnh chưa gắn vào bài nào quá 90 ngày (nút bên dưới chỉ chuyển sang "Đã lưu trữ")</div>
            @foreach (array_slice($r['unused'], 0, 8) as $u)<div style="{{ $row }}"><span>#{{ $u['id'] }} {{ $u['title'] ?: '(không tiêu đề)' }}</span><span style="opacity:.6">{{ $fmt($u['file_size']) }}</span></div>@endforeach
            @if (count($r['unused']) > 8)<div style="font-size:12px;opacity:.6;padding-top:4px">… và {{ count($r['unused']) - 8 }} ảnh nữa</div>@endif</div>
    @endif
    @if (! $r['missing'] && ! $r['orphan_files'] && ! $r['unused'] && ! $r['duplicates'])
        <div style="padding:12px;border-radius:10px;background:rgba(5,150,105,.1);font-size:13px">Thư viện gọn gàng, không có gì cần dọn.</div>
    @endif
</div>
