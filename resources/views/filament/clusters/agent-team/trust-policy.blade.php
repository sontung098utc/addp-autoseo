@php
    $pct = fn ($m) => $m['value'] === null ? 'Chưa có dữ liệu' : round($m['value'] * 100).'% ('.$m['n'].' phiên)';
    $num = fn ($m, $unit = '', $dec = 1) => $m['value'] === null ? 'Chưa có dữ liệu' : number_format($m['value'], $dec, ',', '.').$unit;
    $stateLab = ['met' => ['Đạt', 'success'], 'unmet' => ['Chưa đạt', 'danger'], 'nodata' => ['Chưa có dữ liệu', 'gray'], 'locked' => ['Khóa', 'warning']];
    $dur = $metrics['seconds_to_draft']['value'] === null ? 'Chưa có dữ liệu' : ($metrics['seconds_to_draft']['value'] < 90 ? round($metrics['seconds_to_draft']['value']).' giây' : round($metrics['seconds_to_draft']['value'] / 60, 1).' phút');
    $tierLab = ['high' => 'Cao (cần chuyên gia duyệt)', 'medium' => 'Vừa (cần xem kỹ)'];
@endphp
<x-filament-panels::page>
<div data-testid="trust-policy" class="space-y-4">
    <x-filament::section>
        <div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center">
            <x-filament::badge color="success">Đang áp dụng: Mức 1 (T1)</x-filament::badge>
            <x-filament::badge color="warning">Mức 2 (T2) bị khóa</x-filament::badge>
        </div>
        <p class="mt-2 text-sm">AI viết, tự sửa rồi chỉ tạo <strong>bản nháp</strong>. Người duyệt đăng. Hệ thống chỉ báo "đủ điều kiện"; nâng mức là quyết định của bạn và không có nút bật trên màn hình này.</p>
    </x-filament::section>

    <x-filament::section heading="Bậc tin cậy">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(13rem,1fr));gap:.6rem" data-testid="tiers">
            @foreach ($tiers as $t)
                <div style="border:1px solid rgba(128,128,128,.4);border-radius:.6rem;padding:.6rem">
                    <div class="text-xs" style="opacity:.65">MỨC {{ substr($t['t'], 1) }} ({{ $t['t'] }}) · {{ $t['state'] }}</div>
                    <div style="font-weight:600">{{ $t['name'] }}</div>
                    <div class="text-xs mt-1">{{ $t['does'] }}</div>
                    <div class="text-xs mt-1" style="opacity:.7"><em>Lên bậc:</em> {{ $t['up'] }}</div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section heading="Mức tự động hiện tại" description="30 ngày gần nhất, tính từ các phiên chạy thật. Chưa đo được thì ghi 'Chưa có dữ liệu', không điền 0.">
        <table class="text-sm" style="width:100%" data-testid="metrics">
            <tbody>
                <tr><td>Tỉ lệ phiên hoàn tất không cần người</td><td data-m="completion_rate">{{ $pct($metrics['completion_rate']) }}</td></tr>
                <tr><td>Tỉ lệ tự sửa thành công</td><td data-m="repair_success_rate">{{ $pct($metrics['repair_success_rate']) }}</td></tr>
                <tr><td>Số vòng sửa trung bình</td><td data-m="avg_repair_rounds">{{ $num($metrics['avg_repair_rounds'], ' vòng') }}</td></tr>
                <tr><td>Tỉ lệ giữ nháp (cần người xem)</td><td data-m="hold_rate">{{ $pct($metrics['hold_rate']) }}</td></tr>
                <tr><td>Tỉ lệ phiên lỗi</td><td data-m="fail_rate">{{ $pct($metrics['fail_rate']) }}</td></tr>
                <tr><td>Chi phí mỗi bản nháp</td><td data-m="cost_per_draft">{{ $num($metrics['cost_per_draft'], ' đ', 0) }}</td></tr>
                <tr><td>Thời gian từ giao việc tới bản nháp</td><td data-m="seconds_to_draft">{{ $dur }}</td></tr>
                @foreach ($unmeasured as $label => $why)
                    <tr><td>{{ $label }}</td><td>Chưa có dữ liệu <span class="text-xs" style="opacity:.6">({{ $why }})</span></td></tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section heading="Điều kiện để lên Mức 2" :collapsible="true" :collapsed="true">
        <table class="text-sm" style="width:100%" data-testid="gate">
            <thead><tr style="text-align:left"><th>Điều kiện</th><th>Hiện tại</th><th>Ngưỡng</th><th></th></tr></thead>
            <tbody>
                @foreach ($gate['conditions'] as $c)
                    <tr>
                        <td>{{ $c['label'] }}</td><td>{{ $c['current'] }}</td><td>{{ $c['threshold'] }}</td>
                        <td><x-filament::badge :color="$stateLab[$c['state']][1]">{{ $stateLab[$c['state']][0] }}</x-filament::badge></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="mt-2 text-xs" style="opacity:.75">Chỉ kết luận khi có ít nhất {{ $minSample }} phiên. Ngưỡng là đề xuất ban đầu, chờ bạn chốt.</p>
        <div class="mt-2 text-sm" data-testid="gate-locked" style="color:#b45309">
            <strong>Cần văn bản chính sách của bạn + connector hỗ trợ gỡ bài.</strong>
            Hai điều kiện này khóa cứng: dù số liệu đạt, Mức 2 vẫn chưa thể bật. Còn {{ $gate['unmet'] }} điều kiện chưa đạt.
        </div>
    </x-filament::section>

    <x-filament::section heading="Nhóm chủ đề" :collapsible="true" :collapsed="true">
        <table class="text-sm" style="width:100%" data-testid="topics">
            <thead><tr style="text-align:left"><th>Hạng</th><th>Nhóm</th><th>Ví dụ cụm từ</th><th>Ai duyệt</th></tr></thead>
            <tbody>
                @foreach ($topics as $tier => $groups)
                    @foreach ($groups as $group => $phrases)
                        <tr>
                            <td>{{ $tierLab[$tier] ?? $tier }}</td><td>{{ str_replace('_', ' ', $group) }}</td>
                            <td>{{ implode(', ', array_slice($phrases, 0, 4)) }}</td>
                            <td>{{ $tier === 'high' ? 'Giữ nháp, người có chuyên môn duyệt' : 'Người duyệt xem kỹ' }}</td>
                        </tr>
                    @endforeach
                @endforeach
                <tr><td>Thấp</td><td colspan="2">Không trùng nhóm nào ở trên</td><td>Người duyệt thông thường (Mức 1)</td></tr>
            </tbody>
        </table>
        <p class="mt-2 text-xs" style="opacity:.7">Luật phân hạng lấy từ resources/playbooks/risk/topics.php{{ $topicsReviewed ? ' (rà soát '.$topicsReviewed.')' : '' }}.</p>
    </x-filament::section>

    <x-filament::section heading="Ai được làm gì" :collapsible="true" :collapsed="true">
        <table class="text-sm" style="width:100%" data-testid="roles">
            <thead><tr style="text-align:left"><th>Việc</th>@foreach ($roles as $label)<th>{{ $label }}</th>@endforeach</tr></thead>
            <tbody>
                @foreach ($matrix as $row)
                    <tr data-row="{{ $row['source'] }}">
                        <td>{{ $row['label'] }} <span class="text-xs" style="opacity:.55">({{ $row['source'] }})</span></td>
                        @foreach ($roles as $role => $label)<td data-role="{{ $role }}">{{ $row['cells'][$role] ? '✓' : '—' }}</td>@endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="mt-2 text-xs" style="opacity:.7">Đọc trực tiếp từ Policies đang chạy, không phải bản đề xuất.</p>
    </x-filament::section>
</div>
</x-filament-panels::page>
