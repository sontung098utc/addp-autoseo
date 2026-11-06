@php
    use App\Filament\Support\Gsc\OpportunityFinder as OF;
    use App\Filament\Support\Gsc\PageArticleLinker;
    $n = fn ($v, $d = 0) => $v === null ? 'Chưa có dữ liệu' : number_format($v, $d, ',', '.');
    $pct = fn ($v) => $v === null ? 'Chưa có dữ liệu' : number_format($v * 100, 2, ',', '.').'%';
    // Mũi tên + chữ + số, không chỉ màu. Vị trí: số nhỏ hơn là tốt hơn.
    $delta = function (array $d, string $kind) {
        if ($d['state'] === 'nodata') { return 'Chưa có dữ liệu'; }
        if ($d['state'] === 'new') { return 'Mới'; }
        $a = $d['abs'];
        if (abs($a) < 1e-9) { return '— không đổi'; }
        if ($kind === 'position') {
            return ($a < 0 ? '▲ Tốt hơn ' : '▼ Xấu đi ').number_format(abs($a), 1, ',', '.').' bậc';
        }
        if ($kind === 'ctr') {
            return ($a > 0 ? '▲ +' : '▼ −').number_format(abs($a) * 100, 2, ',', '.').' điểm %';
        }
        return ($a > 0 ? '▲ +' : '▼ −').($d['pct'] === null ? number_format(abs($a), 0, ',', '.') : number_format(abs($d['pct']), 1, ',', '.').'%');
    };
@endphp
<x-filament-panels::page>
    <style>
        .gs-bar{display:flex;gap:.75rem;flex-wrap:wrap;align-items:center}
        .gs-box{border:1px solid rgba(128,128,128,.3);border-radius:.75rem;padding:1rem;margin-bottom:1rem}
        .gs-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(10rem,1fr));gap:.75rem}
        .gs-kpi b{display:block;font-size:1.25rem}
        .gs-muted{opacity:.75;font-size:.8rem}
        .gs-tabs{display:flex;gap:.25rem;flex-wrap:wrap;margin-bottom:.5rem}
        .gs-tab{padding:.35rem .75rem;border:1px solid rgba(128,128,128,.4);border-radius:.5rem;background:none;cursor:pointer}
        .gs-tab[aria-selected=true]{font-weight:700;border-color:rgb(var(--primary-600));border-width:2px}
        .gs-table{width:100%;border-collapse:collapse;font-size:.85rem}
        .gs-table th,.gs-table td{padding:.35rem .5rem;border-bottom:1px solid rgba(128,128,128,.2);text-align:right}
        .gs-table th:first-child,.gs-table td:first-child{text-align:left}
        .gs-link{background:none;border:0;padding:0;text-decoration:underline;cursor:pointer;color:inherit;text-align:left}
        .gs-opp{margin:.4rem 0;padding:.4rem .6rem;border-left:4px solid rgb(var(--warning-600));background:rgba(128,128,128,.08);border-radius:.35rem}
        .gs-chart{width:100%;height:auto}
        .gs-sel{border-color:rgb(var(--primary-600))}
        .gs-badge{display:inline-block;border:1px solid currentColor;border-radius:.4rem;padding:0 .4rem;font-size:.75rem}
    </style>

    <x-demo-banner source="Google Search Console API" real="động cơ so sánh hai kỳ, luật cơ hội, liên kết trang với bài, đề xuất cập nhật" />

    <div class="gs-bar gs-box">
        <label>Property (website):
            <select wire:model.live="websiteId">
                @foreach ($websites as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach
            </select>
        </label>
        <span role="group" aria-label="Kỳ so sánh">
            @foreach ([7, 14, 28] as $d)
                <button type="button" class="gs-tab" aria-pressed="{{ $days === $d ? 'true' : 'false' }}" wire:click="setDays({{ $d }})">{{ $d }} ngày</button>
            @endforeach
        </span>
        <span class="gs-muted">
            @if ($state === 'ket-noi' || $state === 'het-han')
                Đồng bộ gần nhất: dữ liệu đến {{ $report['periods']['current']['to']->format('d/m/Y') }} (minh họa)
            @endif
        </span>
    </div>

    @if ($state === 'chua-ket-noi')
        <div class="gs-box">
            <h2>Website chưa kết nối Google Search Console</h2>
            <p>Sau khi kết nối bạn sẽ có: so sánh clicks/impressions/CTR/vị trí hai kỳ, cơ hội lên trang 1, bài tụt hạng và đề xuất cập nhật.</p>
            @if (\App\Filament\Support\Demo\DemoMode::enabled())
                <x-filament::button wire:click="connect">Kết nối Google Search Console</x-filament::button>
                <p class="gs-muted">Minh họa: nút chỉ lật cờ. Thật sẽ là OAuth chỉ-đọc (webmasters.readonly), token làm mới lưu mã hóa, thu hồi được.</p>
            @else
                <p class="gs-muted">Việc kết nối do quản trị thực hiện khi hệ thống có kết nối Google thật.</p>
            @endif
        </div>
    @elseif ($state === 'dang-dong-bo')
        <div class="gs-box"><h2>Đang lấy dữ liệu lần đầu <span class="gs-badge">Đang đồng bộ</span></h2>
            <p>Việc này có thể mất vài phút. Chưa có báo cáo so sánh, không hiển thị số 0 giả. Quay lại sau.</p></div>
    @elseif ($state === 'khong-quyen')
        <div class="gs-box"><h2>Tài khoản Google đã kết nối không có quyền với property này</h2>
            <p>Cần cấp quyền xem property trong Search Console hoặc chọn property khác trong danh sách website đang hoạt động:</p>
            <ul>@foreach ($websites as $w)<li>{{ $w->name }} — {{ $w->base_url }}</li>@endforeach</ul></div>
    @elseif ($state === 'chua-co-du-lieu')
        <div class="gs-box"><h2>Chưa có dữ liệu</h2><p>Đã kết nối nhưng Google chưa trả số liệu cho property này (thường cần vài ngày sau khi xác minh). Không hiển thị số 0 giả.</p></div>
    @else
        @if ($state === 'het-han')
            <div class="gs-box" role="alert"><strong>Kết nối hết hạn <span class="gs-badge">Số liệu có thể đã cũ</span></strong>
                <p>Số liệu đã lưu bên dưới có thể cũ. Kết nối lại để tiếp tục đồng bộ.</p>
                <x-filament::button wire:click="connect">Kết nối lại</x-filament::button></div>
        @else
            <div class="gs-bar gs-muted"><x-filament::button size="xs" color="gray" wire:click="disconnect">Thu hồi kết nối (minh họa)</x-filament::button></div>
        @endif

        @if ($report['empty'])
            <div class="gs-box"><h2>Chưa có dữ liệu</h2></div>
        @else
            @php
                $cp = $report['periods']['current']; $pp = $report['periods']['previous']; $t = $report['totals'];
            @endphp
            <div class="gs-box">
                <h2>Kỳ {{ $days }} ngày: {{ $cp['from']->format('d/m/Y') }} – {{ $cp['to']->format('d/m/Y') }}</h2>
                <p class="gs-muted">So với {{ $pp['from']->format('d/m/Y') }} – {{ $pp['to']->format('d/m/Y') }}. Kỳ kết thúc ở hôm nay − 3 ngày vì Search Console trễ 2–3 ngày; những ngày gần nhất chưa chốt.</p>
                <div class="gs-kpis">
                    @foreach (['clicks' => 'Clicks', 'impressions' => 'Impressions', 'ctr' => 'CTR', 'position' => 'Vị trí TB'] as $k => $label)
                        <div class="gs-kpi"><span class="gs-muted">{{ $label }}</span>
                            <b>{{ $k === 'ctr' ? $pct($t['cur']['ctr']) : ($k === 'position' ? $n($t['cur']['position'], 1) : $n($t['cur'][$k])) }}</b>
                            <span>{{ $delta($t['d'][$k], $k) }}</span></div>
                    @endforeach
                </div>
            </div>

            @php
                $all = array_merge(array_column($report['daily_cur'], 'clicks'), array_column($report['daily_prev'], 'clicks'));
                $max = max(1, max(array_filter($all, fn ($v) => $v !== null) ?: [1]));
                $pts = function ($series) use ($max) {
                    $c = max(1, count($series) - 1); $o = [];
                    foreach ($series as $i => $s) { if ($s['clicks'] !== null) { $o[] = round($i / $c * 600, 1).','.round(150 - $s['clicks'] / $max * 140, 1); } }
                    return implode(' ', $o);
                };
                $sumC = array_sum(array_filter(array_column($report['daily_cur'], 'clicks'), fn ($v) => $v !== null));
            @endphp
            <div class="gs-box">
                <h2>Clicks theo ngày</h2>
                <svg class="gs-chart" viewBox="0 0 600 160" role="img" aria-labelledby="gs-ch-t">
                    <title id="gs-ch-t">Clicks mỗi ngày: kỳ này {{ $n($sumC) }} clicks trong {{ $days }} ngày, cao nhất {{ $n($max) }} clicks/ngày; đường nét đứt là kỳ trước.</title>
                    <polyline points="{{ $pts($report['daily_prev']) }}" fill="none" stroke="currentColor" stroke-width="1.5" stroke-dasharray="5 4" opacity=".6"/>
                    <polyline points="{{ $pts($report['daily_cur']) }}" fill="none" stroke="rgb(var(--primary-600))" stroke-width="2.5"/>
                </svg>
                <p class="gs-muted">Nét liền: kỳ này. Nét đứt: kỳ trước (xếp chồng theo thứ tự ngày). Ngày tính theo giờ Pacific của Google, không quy đổi sang giờ Việt Nam.</p>
            </div>

            <div class="gs-box">
                <h2>Cơ hội và bài tụt hạng: {{ count($report['opportunities']) }} mục</h2>
                @forelse ($report['opportunities'] as $o)
                    <div class="gs-opp">
                        <span class="gs-badge">{{ $o['label'] }}</span>
                        <button type="button" class="gs-link" wire:click="selectItem('{{ in_array($o['key'], array_column($report['items']['page'], 'key'), true) ? 'page' : 'query' }}', @js($o['key']))">{{ $o['key'] }}</button>
                        <div class="gs-muted">{{ $o['reason'] }}</div>
                    </div>
                @empty
                    <p>Không có mục nào thỏa các luật trong kỳ này.</p>
                @endforelse
            </div>

            <div class="gs-box">
                <h2>Chi tiết theo chiều dữ liệu</h2>
                <div class="gs-tabs" role="tablist">
                    @foreach (\App\Filament\Support\Gsc\GscService::DIMENSIONS as $k => $label)
                        <button type="button" role="tab" class="gs-tab" aria-selected="{{ $tab === $k ? 'true' : 'false' }}" wire:click="setTab('{{ $k }}')">{{ $label }}</button>
                    @endforeach
                </div>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Tìm trong bảng" aria-label="Tìm trong bảng">
                <table class="gs-table">
                    <thead><tr>
                        @foreach (['key' => 'Mục', 'clicks' => 'Clicks', 'impressions' => 'Impressions', 'ctr' => 'CTR', 'position' => 'Vị trí'] as $c => $l)
                            <th scope="col"><button type="button" class="gs-link" wire:click="sortBy('{{ $c }}')">{{ $l }}@if ($sort === $c) {{ $dir === 'asc' ? '▲' : '▼' }}@endif</button></th>
                        @endforeach
                        <th scope="col">Thay đổi clicks</th><th scope="col">Thay đổi vị trí</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($rows as $r)
                        <tr>
                            <td><button type="button" class="gs-link" wire:click="selectItem('{{ $tab }}', @js($r['key']))">{{ $r['key'] }}</button></td>
                            <td>{{ $n($r['cur']['clicks']) }}</td><td>{{ $n($r['cur']['impressions']) }}</td>
                            <td>{{ $pct($r['cur']['ctr']) }}</td><td>{{ $n($r['cur']['position'], 1) }}</td>
                            <td>{{ $delta($r['d']['clicks'], 'clicks') }}</td><td>{{ $delta($r['d']['position'], 'position') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7">Chưa có dữ liệu</td></tr>
                    @endforelse
                    </tbody>
                </table>
                <p class="gs-muted">Google lược bớt truy vấn ẩn danh nên tổng theo truy vấn có thể nhỏ hơn tổng theo trang. Hiển thị tối đa 50 dòng.</p>
            </div>

            @if ($selected)
                @php $link = $selDim === 'page' ? PageArticleLinker::find($selected['key'], $links) : PageArticleLinker::find($selected['page'], $links); @endphp
                <div class="gs-box gs-sel" aria-live="polite">
                    <h2>Mục đang chọn: {{ $selected['key'] }}</h2>
                    <p>Clicks {{ $n($selected['cur']['clicks']) }} ({{ $delta($selected['d']['clicks'], 'clicks') }}); impressions {{ $n($selected['cur']['impressions']) }}; CTR {{ $pct($selected['cur']['ctr']) }}; vị trí {{ $n($selected['cur']['position'], 1) }}.</p>
                    @if ($selDim === 'page' || $selDim === 'query')
                        <p>Bài liên kết: @if ($link)<a href="{{ $link['url'] }}">{{ $link['title'] }}</a>@else Chưa liên kết bài @endif</p>
                    @endif
                    @foreach ($selectedOpps as $o)<div class="gs-opp"><span class="gs-badge">{{ $o['label'] }}</span> {{ $o['reason'] }}</div>@endforeach
                    <h3>AI đề xuất (minh họa)</h3>
                    <p class="gs-muted">Nguồn: số liệu Search Console của mục này; sinh từ mẫu văn bản theo loại cơ hội.</p>
                    <ul>@foreach (\App\Filament\Support\Gsc\AiSuggestion::for($selected['key'], array_column($selectedOpps, 'rule')) as $s)<li>{{ $s }}</li>@endforeach</ul>
                    <x-filament::button wire:click="createProposal">Tạo đề xuất cập nhật</x-filament::button>
                    <p class="gs-muted">Chỉ tạo một đề xuất chờ duyệt; không tự sửa bài, không tự đăng.</p>
                </div>
            @endif
        @endif
    @endif

    <div class="gs-box">
        <h2>Đề xuất cập nhật ({{ count($proposals) }})</h2>
        @forelse ($proposals as $p)
            <div class="gs-opp">
                <span class="gs-badge">{{ $p['status'] }}</span> <strong>{{ $p['key'] }}</strong>
                <div class="gs-muted">{{ $p['page'] ?? 'Không gắn trang' }} · {{ $p['created_by_name'] }} · {{ \Illuminate\Support\Carbon::parse($p['created_at'])->setTimezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') }} · kỳ {{ $p['metrics']['period'] }}</div>
                <ul>@foreach ($p['reasons'] as $r)<li>{{ $r }}</li>@endforeach</ul>
                <div>Hướng cập nhật gợi ý: {{ implode(' ', $p['suggestions']) }}</div>
            </div>
        @empty
            <p class="gs-muted">Chưa có đề xuất nào.</p>
        @endforelse
    </div>

    <details class="gs-box">
        <summary>Luật nghiệp vụ của màn này</summary>
        <ul>
            <li>Nguồn: Google Search Console API, OAuth chỉ đọc; hệ thống không ghi ngược vào Search Console.</li>
            <li>Xem: trưởng nhóm duyệt bài trở lên (người viết không vào được). Tạo đề xuất cập nhật: cũng từ vai trò này, kiểm ở server.</li>
            <li>Kỳ gần nhất kết thúc ở hôm nay − 3 ngày vì GSC trễ 2–3 ngày; dữ liệu những ngày gần nhất chưa chốt.</li>
            <li>Ngày theo giờ Pacific của Google, không quy đổi sang giờ Việt Nam.</li>
            <li>CTR = tổng clicks / tổng impressions (không trung bình cộng CTR); vị trí trung bình có trọng số theo impressions.</li>
            <li>Google lược truy vấn ẩn danh: tổng truy vấn ≤ tổng trang. Mỗi lần gọi API tối đa rowLimit 25.000 dòng, phân trang bằng startRow.</li>
            <li>Không có dữ liệu thì ghi "Chưa có dữ liệu" hoặc "Mới", không hiển thị số 0 giả.</li>
            <li>Cơ hội lên trang 1: vị trí TB từ {{ OF::PAGE1_MIN_POS }} đến {{ OF::PAGE1_MAX_POS }} và từ {{ OF::MIN_IMPRESSIONS }} impressions.</li>
            <li>CTR thấp: CTR dưới {{ OF::LOW_CTR_RATIO * 100 }}% CTR kỳ vọng theo vị trí (bảng ước lượng cố định) và từ {{ OF::MIN_IMPRESSIONS }} impressions.</li>
            <li>Tụt hạng: vị trí xấu đi từ {{ OF::DROP_POSITION }} bậc hoặc clicks giảm từ {{ OF::DROP_CLICKS_PCT }}% so với kỳ trước, và kỳ trước có từ {{ OF::DROP_MIN_PREV_CLICKS }} clicks.</li>
            <li>Dữ liệu thật cần: bảng gsc_connections, gsc_metrics (upsert theo website_id, date, page, query, device, country), bảng đề xuất cập nhật.</li>
        </ul>
    </details>
</x-filament-panels::page>
