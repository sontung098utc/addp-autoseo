@php
    $ver = ['verified' => ['Đã xác minh', 'ok'], 'partial' => ['Xác minh một phần', 'warn'], 'unverified' => ['Chưa xác minh', 'muted'], 'internal' => ['Quy tắc nội bộ', 'info']];
    $sec = fn (string $t) => '<div class="text-sm" style="font-weight:600;margin:.9rem 0 .3rem">'.e($t).'</div>';
@endphp
<div data-testid="role-detail" class="space-y-2 text-sm">
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
        <x-studio.chip domain="readiness" :value="$role['readiness']" />
        <span class="text-xs" style="opacity:.75">{{ $role['readiness_note'] }}</span>
    </div>

    {!! $sec('Mục tiêu') !!}
    <div>{{ $role['goal'] }}</div>

    {!! $sec('Công cụ') !!}
    <div class="text-xs"><strong>Được phép:</strong> {{ implode(' · ', $role['tools_allowed']) }}</div>
    <div class="text-xs"><strong>Không được:</strong> {{ implode(' · ', $role['tools_denied']) }}</div>

    {!! $sec('Bậc model & cổng người') !!}
    <div class="text-xs">Bậc model mục tiêu: <strong>{{ $role['tier_label'] }}</strong> (khai báo thiết kế; model thật chọn ở mục Mô hình, tài khoản ở Cấu hình AI → Tích hợp AI) · Cổng người: {{ $role['human_gate'] }}</div>

    {!! $sec('Kỹ năng (playbook)') !!}
    <div data-testid="role-skills" class="space-y-1">
        @foreach ($skills as $s)
            <div class="text-xs" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
                <code>{{ $s['ref'] }}</code>
                @if ($s['exists'])
                    <x-studio.badge :tone="$s['status'] === 'active' ? 'ok' : 'muted'">v{{ $s['version'] }} · {{ $s['status'] === 'active' ? 'đang dùng' : ($s['status'] === 'draft' ? 'bản nháp' : $s['status']) }}</x-studio.badge>
                    <span style="opacity:.7">{{ $s['title'] }}</span>
                @elseif ($s['error'])
                    <x-studio.badge tone="danger">Lỗi</x-studio.badge> <span>{{ $s['error'] }}</span>
                @else
                    <x-studio.badge tone="muted">Chưa có</x-studio.badge>
                @endif
            </div>
        @endforeach
        <div class="text-xs" style="opacity:.7">
            @if ($skillsUrl)<a href="{{ $skillsUrl }}" style="text-decoration:underline">Mở tab Kỹ năng</a>@else Tab Kỹ năng chưa có (đang phát triển). @endif
        </div>
    </div>

    {!! $sec('Rubric: các luật và ngưỡng') !!}
    @if ($rubric === [])
        <div class="text-xs" style="opacity:.7">Vai trò này chưa gắn luật chất lượng nào.</div>
    @else
        <div style="overflow-x:auto"><table class="text-xs" style="width:100%;border-collapse:collapse" data-testid="role-rubric">
            <thead><tr style="text-align:left;opacity:.7"><th>Luật</th><th>Mức</th><th>Ngưỡng</th><th>Nguồn / lý do</th><th>Xác minh</th></tr></thead>
            <tbody>
            @foreach ($rubric as $r)
                <tr style="border-top:1px solid rgba(128,128,128,.25);vertical-align:top">
                    <td><code>{{ $r['code'] }}</code> {{ $r['name'] }}</td>
                    <td>{{ $r['severities'] ? implode('/', $r['severities']) : '—' }}</td>
                    <td>@forelse ($r['thresholds'] as $k => $v){{ $k }}: {{ is_scalar($v) ? $v : json_encode($v) }}<br>@empty — @endforelse</td>
                    <td>{{ $r['rationale'] }}</td>
                    <td><x-studio.badge :tone="$ver[$r['verification']][1] ?? 'muted'" :title="$r['caveat']">{{ $ver[$r['verification']][0] ?? $r['verification'] }}</x-studio.badge></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        <div class="text-xs" style="opacity:.65">Rà soát lần cuối {{ $rubric[0]['reviewed_at'] }}. Ngưỡng đọc trực tiếp từ mã của bộ kiểm tra; "Mức" là các mức lỗi mà luật có thể trả.</div>
    @endif

    {!! $sec('Rào chắn (không bao giờ làm)') !!}
    <ul class="text-xs" style="margin:0;padding-left:1.1rem;list-style:disc">@foreach ($role['guardrails'] as $g)<li>{{ $g }}</li>@endforeach</ul>

    {!! $sec('Tự động hóa (khai báo thiết kế)') !!}
    <table class="text-xs" style="width:100%" data-testid="role-automation"><tbody>
        <tr><td style="opacity:.7;width:35%">Điều kiện đạt (kiểm tự động)</td><td>{{ $role['pass_condition'] }}</td></tr>
        <tr><td style="opacity:.7">Nếu không đạt, tự làm</td><td>{{ $role['on_fail'] }}</td></tr>
        <tr><td style="opacity:.7">Nhóm chủ đề</td><td>{{ $role['topic_class'] }}</td></tr>
        <tr><td style="opacity:.7">Hết hạn chờ</td><td>{{ $role['on_timeout'] }}</td></tr>
    </tbody></table>

    {!! $sec('Xuất xứ') !!}
    <div class="text-xs">Học từ <strong>{{ implode(', ', $role['origin']) }}</strong> (giấy phép MIT).
        @foreach ($skills as $s)@if ($s['lineage'])<div style="margin-top:.25rem"><code>{{ $s['ref'] }}</code>: {{ $s['lineage']['source'] }} v{{ $s['lineage']['source_version'] }} · {{ $s['lineage']['license'] }}. Đã chỉnh: {{ $s['lineage']['adaptations'] }}</div>@endif @endforeach
    </div>
</div>
