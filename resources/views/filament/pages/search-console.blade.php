@php
    use App\Filament\Support\Gsc\OpportunityFinder as OF;
    $n = fn ($v, $d = 0) => $v === null ? '—' : number_format($v, $d, ',', '.');
    $pct = fn ($v) => $v === null ? '—' : number_format($v * 100, 2, ',', '.').'%';
    // Số liệu delta cho KPI: % với clicks/impressions, điểm % với CTR, bậc với vị trí.
    $dk = function (array $d, string $kind) {
        if ($d['state'] !== 'ok') { return null; }
        if ($kind === 'ctr') { return round($d['abs'] * 100, 2); }
        if ($kind === 'position') { return round($d['abs'], 1); }
        return $d['pct'] === null ? null : round($d['pct'], 1);
    };
    $hasData = in_array($state, ['ket-noi', 'het-han'], true) && $report && ! $report['empty'];
    $tabs = [];
    if ($hasData) {
        $tabs[] = ['key' => 'overview', 'label' => 'Tổng quan'];
        foreach (\App\Filament\Support\Gsc\GscService::DIMENSIONS as $k => $label) {
            $tabs[] = ['key' => $k, 'label' => $label];
        }
        $tabs[] = ['key' => 'opps', 'label' => 'Cơ hội', 'count' => count($oppRows), 'tone' => $oppRows ? 'warn' : null];
    }
    $tabs[] = ['key' => 'proposals', 'label' => 'Đề xuất', 'count' => count($proposalRows)];
    $tabs[] = ['key' => 'rules', 'label' => 'Quy tắc'];
    $dimCols = fn () => [
        ['key' => 'key', 'label' => 'Mục', 'type' => 'text', 'sortable' => true],
        ['key' => 'clicks', 'label' => 'Clicks', 'type' => 'number', 'sortable' => true],
        ['key' => 'd_clicks', 'label' => 'Δ Clicks', 'type' => 'delta', 'good' => 'up', 'unit' => '%', 'sortable' => true],
        ['key' => 'impressions', 'label' => 'Impressions', 'type' => 'number', 'sortable' => true],
        ['key' => 'd_impressions', 'label' => 'Δ Impressions', 'type' => 'delta', 'good' => 'up', 'unit' => '%', 'sortable' => true, 'visible' => false],
        ['key' => 'ctr', 'label' => 'CTR', 'type' => 'number', 'decimals' => 2, 'suffix' => '%', 'sortable' => true],
        ['key' => 'd_ctr', 'label' => 'Δ CTR (điểm %)', 'type' => 'delta', 'good' => 'up', 'decimals' => 2, 'sortable' => true, 'visible' => false],
        ['key' => 'position', 'label' => 'Vị trí', 'type' => 'number', 'decimals' => 1, 'sortable' => true],
        ['key' => 'd_position', 'label' => 'Δ Vị trí', 'type' => 'delta', 'good' => 'down', 'decimals' => 1, 'sortable' => true],
    ];
@endphp
<x-filament-panels::page>
    <style>
        .gs-chart{width:100%;height:auto}
        .gs-list{margin:0;padding:0;list-style:none;display:grid;gap:.5rem}
    </style>

    <div class="ss-page">
        <div class="ss-toolbar">
            <label class="ss-text-sm ss-muted" for="gs-site">Property</label>
            <select id="gs-site" wire:model.live="websiteId">
                @foreach ($websites as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach
            </select>
            <span role="group" aria-label="Kỳ so sánh" class="ss-toolbar">
                @foreach ([7, 14, 28] as $d)
                    <x-filament::button size="sm" :color="$days === $d ? 'info' : 'gray'" :outlined="$days !== $d" wire:click="setDays({{ $d }})" aria-pressed="{{ $days === $d ? 'true' : 'false' }}">{{ $d }} ngày</x-filament::button>
                @endforeach
            </span>
            @if (in_array($state, ['ket-noi', 'het-han'], true))
                <x-studio.badge :tone="$state === 'het-han' ? 'warn' : 'ok'">{{ $state === 'het-han' ? 'Hết hạn' : 'Đã kết nối' }}</x-studio.badge>
                @if ($report && ! $report['empty'])<span class="ss-text-sm ss-muted">Dữ liệu đến {{ $report['periods']['current']['to']->format('d/m/Y') }}</span>@endif
            @endif
            <span style="flex:1"></span>
            @if ($state === 'ket-noi')
                <x-filament::button size="sm" color="gray" wire:click="disconnect">Thu hồi kết nối</x-filament::button>
            @endif
            @if ($hasData)
                <x-filament::button wire:click="createProposal" :disabled="! $selected" title="{{ $selected ? 'Tạo đề xuất cho: '.$selected['key'] : 'Chọn một dòng trong bảng trước' }}">Tạo đề xuất cập nhật</x-filament::button>
            @endif
        </div>

        @if ($state === 'chua-ket-noi')
            <div class="ss-alert ss-alert--warn" role="status" style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
                <span>Website chưa kết nối Google Search Console.</span>
                @if (\App\Filament\Support\Demo\DemoMode::enabled())
                    <x-filament::button size="sm" wire:click="connect">Kết nối Google Search Console</x-filament::button>
                @else
                    <span class="ss-muted">Quản trị sẽ kết nối khi hệ thống có kết nối Google.</span>
                @endif
            </div>
        @elseif ($state === 'dang-dong-bo')
            <div class="ss-alert ss-alert--info" role="status"><x-studio.badge tone="info">Đang đồng bộ</x-studio.badge> Đang lấy dữ liệu lần đầu, quay lại sau vài phút.</div>
        @elseif ($state === 'khong-quyen')
            <div class="ss-alert ss-alert--danger" role="alert">Tài khoản Google đã kết nối không có quyền với property này. Cấp quyền xem hoặc chọn property khác: {{ $websites->pluck('base_url')->implode(', ') }}</div>
        @elseif ($state === 'chua-co-du-lieu' || (in_array($state, ['ket-noi', 'het-han'], true) && $report && $report['empty']))
            <div class="ss-alert ss-alert--info" role="status">Chưa có dữ liệu cho property này.</div>
        @endif
        @if ($state === 'het-han')
            <div class="ss-alert ss-alert--warn" role="alert" style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
                <span><x-studio.badge tone="warn">Số liệu có thể đã cũ</x-studio.badge> Kết nối hết hạn.</span>
                <x-filament::button size="sm" color="gray" wire:click="connect">Kết nối lại</x-filament::button>
            </div>
        @endif

        @if ($hasData)
            @php $t = $report['totals']; $cp = $report['periods']['current']; $pp = $report['periods']['previous']; @endphp
            <div class="ss-kpis">
                <div class="ss-kpi"><div class="ss-kpi__l">Clicks</div><div class="ss-kpi__v">{{ $n($t['cur']['clicks']) }}</div><div class="ss-kpi__d"><x-studio.delta :value="$dk($t['d']['clicks'], 'clicks')" good="up" unit="%" /></div></div>
                <div class="ss-kpi"><div class="ss-kpi__l">Impressions</div><div class="ss-kpi__v">{{ $n($t['cur']['impressions']) }}</div><div class="ss-kpi__d"><x-studio.delta :value="$dk($t['d']['impressions'], 'impressions')" good="up" unit="%" /></div></div>
                <div class="ss-kpi"><div class="ss-kpi__l">CTR</div><div class="ss-kpi__v">{{ $pct($t['cur']['ctr']) }}</div><div class="ss-kpi__d"><x-studio.delta :value="$dk($t['d']['ctr'], 'ctr')" good="up" unit=" điểm %" :decimals="2" /></div></div>
                <div class="ss-kpi"><div class="ss-kpi__l">Vị trí TB</div><div class="ss-kpi__v">{{ $n($t['cur']['position'], 1) }}</div><div class="ss-kpi__d"><x-studio.delta :value="$dk($t['d']['position'], 'position')" good="down" unit=" bậc" /></div></div>
            </div>
        @endif

        <x-studio.tabs persist="search-console" :tabs="$tabs">
            @if ($hasData)
                @php
                    $all = array_merge(array_column($report['daily_cur'], 'clicks'), array_column($report['daily_prev'], 'clicks'));
                    $max = max(1, max(array_filter($all, fn ($v) => $v !== null) ?: [1]));
                    $pts = function ($series) use ($max) {
                        $c = max(1, count($series) - 1); $o = [];
                        foreach ($series as $i => $s) { if ($s['clicks'] !== null) { $o[] = round($i / $c * 600, 1).','.round(150 - $s['clicks'] / $max * 140, 1); } }
                        return implode(' ', $o);
                    };
                    $sumC = array_sum(array_filter(array_column($report['daily_cur'], 'clicks'), fn ($v) => $v !== null));
                    $sumP = array_sum(array_filter(array_column($report['daily_prev'], 'clicks'), fn ($v) => $v !== null));
                @endphp
                <x-studio.panel key="overview">
                    <div class="ss-split">
                        <div class="ss-card">
                            <div class="ss-card__h">Clicks theo ngày <span class="ss-text-sm ss-muted">{{ $cp['from']->format('d/m') }} – {{ $cp['to']->format('d/m/Y') }} so với {{ $pp['from']->format('d/m') }} – {{ $pp['to']->format('d/m') }}</span></div>
                            <div class="ss-card__b">
                                <svg class="gs-chart" viewBox="0 0 600 160" role="img" aria-labelledby="gs-ch-t">
                                    <title id="gs-ch-t">Clicks mỗi ngày: kỳ này {{ $n($sumC) }} clicks trong {{ $days }} ngày, kỳ trước {{ $n($sumP) }}; cao nhất {{ $n($max) }} clicks/ngày.</title>
                                    <polyline points="{{ $pts($report['daily_prev']) }}" fill="none" stroke="var(--ss-muted-fg)" stroke-width="1.5" stroke-dasharray="5 4"/>
                                    <polyline points="{{ $pts($report['daily_cur']) }}" fill="none" stroke="var(--ss-accent-fg)" stroke-width="2.5"/>
                                </svg>
                                <div class="ss-text-sm" style="display:flex;gap:1rem;flex-wrap:wrap">
                                    <span><strong style="color:var(--ss-accent-fg)">━</strong> Kỳ này: {{ $n($sumC) }} clicks</span>
                                    <span><strong style="color:var(--ss-muted-fg)">╌</strong> Kỳ trước: {{ $n($sumP) }} clicks</span>
                                </div>
                            </div>
                        </div>
                        <div class="ss-card">
                            <div class="ss-card__h">Top cơ hội</div>
                            <div class="ss-card__b">
                                <ul class="gs-list">
                                    @forelse (array_slice($oppRows, 0, 5) as $o)
                                        <li class="ss-text-sm"><x-studio.badge :tone="$o['type']['tone']">{{ $o['type']['text'] }}</x-studio.badge> <strong>{{ $o['key'] }}</strong><div class="ss-text-xs ss-muted">{{ $o['reason'] }}</div></li>
                                    @empty
                                        <li class="ss-text-sm ss-muted">Không có cơ hội nào trong kỳ này.</li>
                                    @endforelse
                                </ul>
                            </div>
                        </div>
                    </div>
                </x-studio.panel>

                @foreach (\App\Filament\Support\Gsc\GscService::DIMENSIONS as $k => $label)
                    <x-studio.panel :key="$k">
                        <div class="ss-split">
                            <x-studio.data-table :id="'gs-'.$k" :page-size="25" :search="'Tìm '.mb_strtolower($label)" select-method="selectRowId" :selected-id="$selectedId" :rows="$dimRows[$k]" :columns="$dimCols()" />
                            @include('filament.pages.search-console-detail')
                        </div>
                    </x-studio.panel>
                @endforeach

                <x-studio.panel key="opps">
                    <div class="ss-split">
                        <x-studio.data-table id="gs-opps" :page-size="25" search="Tìm cơ hội" select-method="selectRowId" :selected-id="$selectedId" empty="Không có mục nào thỏa các luật trong kỳ này" :rows="$oppRows" :columns="[
                            ['key' => 'type', 'label' => 'Loại cơ hội', 'type' => 'badge', 'filter' => true, 'sortable' => true, 'tones' => [OF::LABELS[OF::RULE_DROP] => 'danger', OF::LABELS[OF::RULE_LOW_CTR] => 'warn', OF::LABELS[OF::RULE_PAGE1] => 'info']],
                            ['key' => 'key', 'label' => 'Mục', 'type' => 'text', 'sortable' => true],
                            ['key' => 'reason', 'label' => 'Lý do', 'type' => 'text'],
                        ]" />
                        @include('filament.pages.search-console-detail')
                    </div>
                </x-studio.panel>
            @endif

            <x-studio.panel key="proposals">
                <x-studio.data-table id="gs-proposals" :page-size="10" search="Tìm đề xuất" empty="Chưa có đề xuất nào" :rows="$proposalRows" :columns="[
                    ['key' => 'status', 'label' => 'Trạng thái', 'type' => 'badge', 'filter' => true, 'sortable' => true],
                    ['key' => 'key', 'label' => 'Mục', 'type' => 'text', 'sortable' => true],
                    ['key' => 'page', 'label' => 'Trang', 'type' => 'mono'],
                    ['key' => 'reasons', 'label' => 'Lý do', 'type' => 'text'],
                    ['key' => 'by', 'label' => 'Người tạo', 'type' => 'text', 'filter' => true, 'visible' => false],
                    ['key' => 'at', 'label' => 'Thời điểm (giờ VN)', 'type' => 'text', 'sortable' => true],
                    ['key' => 'period', 'label' => 'Kỳ', 'type' => 'text', 'visible' => false],
                ]" />
            </x-studio.panel>

            <x-studio.panel key="rules">
                <div class="ss-card"><div class="ss-card__b">
                    <ul class="ss-text-sm" style="margin:0;padding-left:1.2rem;display:grid;gap:.35rem">
                        <li>Nguồn: Google Search Console API, OAuth chỉ đọc; hệ thống không ghi ngược vào Search Console.</li>
                        <li>Xem và tạo đề xuất cập nhật: trưởng nhóm duyệt bài trở lên (kiểm ở server).</li>
                        <li>Kỳ gần nhất kết thúc ở hôm nay − 3 ngày vì GSC trễ 2–3 ngày. Ngày theo giờ Pacific của Google.</li>
                        <li>CTR = tổng clicks / tổng impressions; vị trí trung bình có trọng số theo impressions. Vị trí tăng số là xấu đi.</li>
                        <li>Google lược truy vấn ẩn danh nên tổng truy vấn ≤ tổng trang.</li>
                        <li>Không có dữ liệu thì ghi "—" hoặc "Mới", không hiển thị số 0 giả.</li>
                        <li>Cơ hội trang 1: vị trí TB từ {{ OF::PAGE1_MIN_POS }} đến {{ OF::PAGE1_MAX_POS }} và từ {{ OF::MIN_IMPRESSIONS }} impressions.</li>
                        <li>CTR thấp: dưới {{ OF::LOW_CTR_RATIO * 100 }}% CTR kỳ vọng theo vị trí và từ {{ OF::MIN_IMPRESSIONS }} impressions.</li>
                        <li>Tụt hạng: vị trí xấu đi từ {{ OF::DROP_POSITION }} bậc hoặc clicks giảm từ {{ OF::DROP_CLICKS_PCT }}% so với kỳ trước, kỳ trước có từ {{ OF::DROP_MIN_PREV_CLICKS }} clicks.</li>
                        <li>Đề xuất chỉ chờ duyệt; không tự sửa bài, không tự đăng.</li>
                    </ul>
                </div></div>
            </x-studio.panel>
        </x-studio.tabs>
    </div>
</x-filament-panels::page>
