@php
    $pb = $s['playbook'];
    $ver = ['verified' => 'Đã xác minh', 'partial' => 'Xác minh một phần', 'unverified' => 'Chưa xác minh', 'internal' => 'Quy tắc nội bộ'];
    $sec = fn (string $t) => '<div class="text-sm" style="font-weight:600;margin:.9rem 0 .3rem">'.e($t).'</div>';
@endphp
<div data-testid="skill-detail" class="space-y-2 text-sm">
    <div class="text-xs"><code>{{ $s['ref'] }}</code></div>
    @if (! $pb)
        <div class="text-xs" style="color:#b91c1c">Playbook lỗi định dạng, không đọc được nội dung: {{ $s['error'] }}</div>
    @else
        <div class="text-xs">
            @if ($pb->status === 'draft')<x-studio.badge tone="warn">Nháp, chưa qua bộ mẫu</x-studio.badge>
            @else<x-studio.badge :tone="$pb->status === 'active' ? 'ok' : 'muted'">{{ $pb->status === 'active' ? 'Đang dùng' : 'Đã nghỉ' }}</x-studio.badge>@endif
            Kỹ năng mặc định không sửa trực tiếp ở đây; muốn đổi, hãy tạo kỹ năng riêng.
        </div>

        <div class="text-xs" data-testid="skill-usage" style="margin-top:.6rem">
            {{ $usage ? 'Đã dùng trong '.$usage['count'].' bước; lần gần nhất '.($usage['last']?->format('d/m/Y H:i') ?? 'không rõ').'.' : 'Chưa được phiên nào dùng.' }}
            @if ($s['roles'])Vai trò: {{ implode(', ', array_column($s['roles'], 'name')) }}.@endif
        </div>

        {!! $sec('Xuất xứ') !!}
        <table class="text-xs" style="width:100%"><tbody data-testid="skill-lineage">
            <tr><td style="opacity:.7;width:25%">Nguồn</td><td>{{ $pb->lineage['source'] }}</td></tr>
            <tr><td style="opacity:.7">Phiên bản nguồn</td><td>{{ $pb->lineage['source_version'] }}</td></tr>
            <tr><td style="opacity:.7">Giấy phép</td><td>{{ $pb->lineage['license'] }}</td></tr>
            <tr><td style="opacity:.7">Đã chỉnh</td><td>{{ $pb->lineage['adaptations'] }}</td></tr>
            <tr><td style="opacity:.7">Rà soát lần cuối</td><td>{{ $pb->reviewedAt }}</td></tr>
            <tr><td style="opacity:.7">Checksum</td><td><code>{{ substr($pb->checksum, 0, 12) }}</code></td></tr>
        </tbody></table>

        {!! $sec('Luật chất lượng liên quan (rubric_refs)') !!}
        @if ($rubric === [])
            <div class="text-xs" style="opacity:.7">Không gắn luật nào.</div>
        @else
            <table class="text-xs" style="width:100%;border-collapse:collapse" data-testid="skill-rubric"><tbody>
            @foreach ($rubric as $r)
                <tr style="border-top:1px solid rgba(128,128,128,.25);vertical-align:top">
                    <td style="white-space:nowrap"><code>{{ $r['code'] }}</code></td>
                    @if ($r['entry'])
                        <td>{{ $r['entry']['name'] }}</td>
                        <td>@forelse ($r['entry']['thresholds'] as $k => $v){{ $k }}: {{ is_scalar($v) ? $v : json_encode($v) }}<br>@empty — @endforelse</td>
                        <td>{{ $ver[$r['entry']['verification']] ?? $r['entry']['verification'] }}</td>
                    @else
                        <td colspan="3" style="color:#b91c1c">Mã luật không có trong bộ kiểm tra.</td>
                    @endif
                </tr>
            @endforeach
            </tbody></table>
        @endif

        {!! $sec('Nội dung playbook (chỉ đọc)') !!}
        <div class="skill-md text-xs" data-testid="skill-body" style="max-height:24rem;overflow:auto;border:1px solid rgba(128,128,128,.25);border-radius:.5rem;padding:.6rem">{!! $html !!}</div>
    @endif
</div>
