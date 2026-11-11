@php
    use App\Filament\Support\AiUsage\Vnd;
    $avg = $summary['avg_per_article'] ?? null;
    $gcols = fn (string $first, bool $prov = false) => array_values(array_filter([
        ['key' => 'label', 'label' => $first, 'type' => 'text', 'sortable' => true],
        $prov ? ['key' => 'provider', 'label' => 'Nhà cung cấp', 'type' => 'text', 'sortable' => true, 'filter' => true, 'visible' => false] : null,
        ['key' => 'articles', 'label' => 'Số bài', 'type' => 'number', 'sortable' => true],
        ['key' => 'versions', 'label' => 'Phiên bản', 'type' => 'number', 'sortable' => true],
        ['key' => 'tokens', 'label' => 'Token', 'type' => 'number', 'sortable' => true, 'visible' => false],
        ['key' => 'cost', 'label' => 'Chi phí (₫)', 'type' => 'number', 'sortable' => true],
        ['key' => 'share', 'label' => 'Tỷ trọng', 'type' => 'number', 'decimals' => 1, 'suffix' => '%', 'sortable' => true, 'visible' => false],
        ['key' => 'avg', 'label' => 'TB/bài (₫)', 'type' => 'number', 'sortable' => true],
        ['key' => 'over', 'label' => 'So ngưỡng '.Vnd::format($th['article']), 'type' => 'badge', 'sortable' => true, 'filter' => true],
    ]));
@endphp
<x-filament-panels::page>
    <style>
        .aiu-tabs{display:flex;gap:.25rem;border-bottom:1px solid rgba(128,128,128,.3);margin-bottom:1rem}
        .aiu-tab{padding:.55rem 1rem;border:0;border-bottom:2px solid transparent;background:transparent;color:inherit;cursor:pointer;font-size:.9rem;opacity:.7;margin-bottom:-1px}
        .aiu-tab:hover{opacity:1}
        .aiu-tab[aria-selected=true]{opacity:1;font-weight:600;border-bottom-color:#0f6b5c;color:#0f6b5c}
        :root[data-theme=dark] .aiu-tab[aria-selected=true],.dark .aiu-tab[aria-selected=true]{color:#5eead4;border-bottom-color:#5eead4}
    </style>
    <div class="aiu-tabs" role="tablist" aria-label="Chi phí AI" data-testid="ai-tabs">
        @foreach (\App\Filament\Pages\AiUsagePage::TABS as $k => $label)
            <button type="button" role="tab" class="aiu-tab" aria-selected="{{ $tab === $k ? 'true' : 'false' }}" wire:click="setTab('{{ $k }}')" data-tab="{{ $k }}">{{ $label }}</button>
        @endforeach
    </div>
    @if ($tab === 'cau-hinh')
        @include('filament.pages.ai-config')
    @else
    <div class="ss-page">
        <div class="ss-toolbar" role="group" aria-label="Bộ lọc">
            <label for="ai-period" class="ss-text-sm ss-muted">Kỳ</label>
            <select id="ai-period" wire:model.live="period">
                @foreach (\App\Filament\Pages\AiUsagePage::PERIODS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
            </select>
            @if ($period === 'tuy-chon')
                <label for="ai-from" class="ss-text-sm ss-muted">Từ</label><input id="ai-from" type="date" wire:model.live="customFrom">
                <label for="ai-to" class="ss-text-sm ss-muted">đến</label><input id="ai-to" type="date" wire:model.live="customTo">
            @endif
            <label for="ai-site" class="ss-text-sm ss-muted">Website</label>
            <select id="ai-site" wire:model.live="websiteId">
                <option value="">Tất cả website</option>
                @foreach ($websites as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach
            </select>
            <span class="ss-text-sm ss-muted">{{ $label }}</span>
        </div>

        @if ($rangeError)<div class="ss-alert ss-alert--warn" role="alert">{{ $rangeError }}</div>@endif
        @if ($monthExceeds)
            <div class="ss-alert ss-alert--danger" role="alert" data-ai="month-over"><strong>▲ Chi phí {{ $monthLabel }} đã vượt ngưỡng tháng:</strong> {{ Vnd::format($monthCost) }} / {{ Vnd::format($th['month']) }} (vượt {{ Vnd::format($monthCost - $th['month']) }}).</div>
        @endif
        @if ($avgExceeds || count($overArticles) > 0)
            <div class="ss-alert ss-alert--warn" role="alert" data-ai="over-threshold"><strong>▲ Vượt ngưỡng {{ Vnd::format($th['article']) }}/bài:</strong>
                @if ($avgExceeds)trung bình {{ Vnd::format($avg) }}/bài @endif
                @if (count($overArticles) > 0)<span data-ai="articles-over">{{ $avgExceeds ? 'và ' : '' }}{{ count($overArticles) }} bài vượt{{ count($overArticles) > 1 ? ', cao nhất' : '' }}: {{ $overArticles[0]['title'] }} {{ Vnd::format($overArticles[0]['cost']) }}</span>@endif
            </div>
        @endif

        @if (! $summary)
            <div class="ss-card" data-ai="empty"><div class="ss-card__b" style="display:flex;gap:1rem;align-items:center;justify-content:space-between;flex-wrap:wrap">
                <span>Chưa có dữ liệu chi phí trong kỳ này{{ $websiteId ? ' ở website đã chọn' : '' }}. <x-help title="Chi phí AI hiện khi nào"><p>Chi phí hiện khi có phiên bản bài do AI sinh trong kỳ. Bạn vẫn có thể đặt ngưỡng cảnh báo trước.</p></x-help></span>
                <button type="button" class="ss-dt__btn" wire:click="mountAction('setThreshold')">Đặt ngưỡng cảnh báo</button>
            </div></div>
        @else
            <div class="ss-kpis" data-ai="summary">
                <div class="ss-kpi"><div class="ss-kpi__l">Tổng chi phí</div>
                    <div class="ss-kpi__v">{{ $summary['cost'] === null ? 'Chưa ghi' : Vnd::format($summary['cost']) }}</div>
                    <div class="ss-kpi__d"><x-studio.delta :value="$deltas['cost']" good="down" unit="%" /> <span class="ss-muted">{{ Vnd::int($summary['articles']) }} bài · {{ Vnd::int($summary['versions']) }} phiên bản · {{ Vnd::int($summary['tokens']) }} token</span></div></div>
                <div class="ss-kpi"><div class="ss-kpi__l">Trung bình/bài</div>
                    <div class="ss-kpi__v" style="color:var(--ss-{{ $avg === null ? 'text' : ($avgExceeds ? 'danger-fg' : 'ok-fg') }})">{{ $avg === null ? '—' : Vnd::format($avg) }}</div>
                    <div class="ss-kpi__d">@if ($avg !== null)<x-studio.badge :tone="$avgExceeds ? 'danger' : 'ok'">{{ $avgExceeds ? '▲ vượt' : 'trong' }} ngưỡng {{ Vnd::format($th['article']) }}</x-studio.badge> @endif<x-studio.delta :value="$deltas['avg']" good="down" unit="%" /> <span class="ss-muted">trên {{ Vnd::int($summary['articles_with_cost']) }} bài có ghi chi phí</span></div></div>
                @if ($summary['regen_articles'] > 0)
                <div class="ss-kpi"><div class="ss-kpi__l">Bài sinh lại</div>
                    <div class="ss-kpi__v">{{ $summary['regen_articles'] }}</div>
                    <div class="ss-kpi__d"><x-studio.delta :value="$deltas['regen']" good="down" :decimals="0" /> <span class="ss-muted">{{ $summary['extra_versions'] }} phiên bản sinh lại</span></div></div>
                @endif
                @if ($summary['unrecorded'] > 0)
                <div class="ss-kpi"><div class="ss-kpi__l">Phiên bản chưa ghi chi phí <x-help title="Vì sao tách riêng"><p>Phiên bản chưa ghi chi phí không được tính là 0 ₫.</p></x-help></div>
                    <div class="ss-kpi__v">{{ $summary['unrecorded'] }}</div>
                    <div class="ss-kpi__d"><x-studio.delta :value="$deltas['unrecorded']" good="down" :decimals="0" /></div></div>
                @endif
            </div>
        @endif

        <x-studio.tabs persist="chi-phi-ai" :default="$summary ? 'overview' : 'thresholds'" :tabs="array_values(array_filter([
            $summary ? ['key' => 'overview', 'label' => 'Tổng quan'] : null,
            $summary ? ['key' => 'model', 'label' => 'Theo model', 'count' => count($modelRows)] : null,
            $summary ? ['key' => 'prompt', 'label' => 'Theo prompt', 'count' => count($promptRows)] : null,
            $summary ? ['key' => 'top', 'label' => 'Bài tốn nhất', 'count' => count($topRows), 'tone' => count($overArticles) ? 'danger' : null] : null,
            ['key' => 'thresholds', 'label' => 'Ngưỡng', 'tone' => $monthExceeds ? 'danger' : null],
            ['key' => 'rules', 'label' => 'Quy tắc'],
        ]))">
            <x-studio.panel key="overview">
                @if ($summary)
                    @php
                        $n = count($days);
                        $max = max($peak, $pace ?? 0, 1);
                        $W = 680; $H = 220; $L = 56; $B = 24; $T = 12;
                        $bw = max(2, ($W - $L) / max($n, 1));
                        $y = fn ($v) => $T + ($H - $T - $B) * (1 - $v / $max);
                    @endphp
                    <div class="ss-card"><div class="ss-card__h">Chi phí theo ngày (₫)</div><div class="ss-card__b" style="overflow-x:auto">
                        <svg style="display:block;width:100%;min-width:520px;height:auto" viewBox="0 0 {{ $W }} {{ $H }}" role="img" aria-labelledby="ai-ch-t ai-ch-d">
                            <title id="ai-ch-t">Chi phí AI theo ngày, {{ $label }}</title>
                            <desc id="ai-ch-d">{{ $this->describeChart($days, $peak, $peakDay, $pace) }} Số liệu từng ngày nằm trong bảng bên dưới.</desc>
                            <line x1="{{ $L }}" x2="{{ $W }}" y1="{{ $y(0) }}" y2="{{ $y(0) }}" stroke="var(--ss-border)"/>
                            <line x1="{{ $L }}" x2="{{ $W }}" y1="{{ $y($max) }}" y2="{{ $y($max) }}" stroke="var(--ss-border)"/>
                            <text class="ss-text-xs" fill="var(--ss-text-muted)" x="{{ $L - 6 }}" y="{{ $y(0) + 4 }}" text-anchor="end">0</text>
                            <text class="ss-text-xs" fill="var(--ss-text-muted)" x="{{ $L - 6 }}" y="{{ $y($max) + 4 }}" text-anchor="end">{{ Vnd::int($max) }}</text>
                            @foreach ($days as $i => $d)
                                @if ($d['cost'] > 0)
                                    <rect fill="var(--ss-accent-fg)" x="{{ $L + $i * $bw + 1 }}" y="{{ $y($d['cost']) }}" width="{{ max(1, $bw - 2) }}" height="{{ $y(0) - $y($d['cost']) }}"><title>{{ $d['date']->format('d/m') }}: {{ Vnd::format($d['cost']) }}</title></rect>
                                @endif
                                @if ($i === 0 || $i === $n - 1 || ($n > 8 && $i % intdiv($n, 6) === 0))
                                    <text class="ss-text-xs" fill="var(--ss-text-muted)" x="{{ $L + $i * $bw + $bw / 2 }}" y="{{ $H - 6 }}" text-anchor="middle">{{ $d['date']->format('d/m') }}</text>
                                @endif
                            @endforeach
                            @if ($pace !== null)
                                <line x1="{{ $L }}" x2="{{ $W }}" y1="{{ $y($pace) }}" y2="{{ $y($pace) }}" stroke="var(--ss-warn-fg)" stroke-width="1.5" stroke-dasharray="5 4"/>
                                <text class="ss-text-xs" fill="var(--ss-warn-fg)" x="{{ $W }}" y="{{ $y($pace) - 4 }}" text-anchor="end">Ngưỡng tháng / ngày: {{ Vnd::format($pace) }}</text>
                            @endif
                        </svg>
                    </div></div>
                    <div class="ss-split" style="margin-top:1rem">
                        <x-studio.data-table id="ai-days" :columns="[
                            ['key' => 'date', 'label' => 'Ngày', 'type' => 'text'],
                            ['key' => 'versions', 'label' => 'Phiên bản', 'type' => 'number', 'sortable' => true],
                            ['key' => 'tokens', 'label' => 'Token', 'type' => 'number', 'sortable' => true],
                            ['key' => 'cost', 'label' => 'Chi phí (₫)', 'type' => 'number', 'sortable' => true],
                        ]" :rows="$dayRows" :page-size="10" search="Tìm ngày" />
                        <x-studio.data-table id="ai-sites" :columns="$gcols('Website')" :rows="$siteRows" :page-size="10" search="Tìm website" />
                    </div>
                @endif
            </x-studio.panel>

            <x-studio.panel key="model">
                <div data-ai="byModel"><x-studio.data-table id="ai-model" :columns="$gcols('Model', true)" :rows="$modelRows" :page-size="10" search="Tìm model" empty="Chưa có dữ liệu" /></div>
            </x-studio.panel>
            <x-studio.panel key="prompt">
                <div data-ai="byPrompt"><x-studio.data-table id="ai-prompt" :columns="$gcols('Phiên bản prompt')" :rows="$promptRows" :page-size="10" search="Tìm prompt" empty="Chưa có dữ liệu" /></div>
            </x-studio.panel>
            <x-studio.panel key="top">
                <div data-ai="top"><x-studio.data-table id="ai-top" :columns="[
                    ['key' => 'title', 'label' => 'Bài', 'type' => 'link', 'sortable' => true],
                    ['key' => 'website', 'label' => 'Website', 'type' => 'text', 'sortable' => true, 'filter' => true],
                    ['key' => 'models', 'label' => 'Model', 'type' => 'text', 'visible' => false],
                    ['key' => 'cost', 'label' => 'Chi phí (₫)', 'type' => 'number', 'sortable' => true],
                    ['key' => 'versions', 'label' => 'Phiên bản', 'type' => 'number', 'sortable' => true],
                    ['key' => 'tokens', 'label' => 'Token', 'type' => 'number', 'sortable' => true, 'visible' => false],
                    ['key' => 'over', 'label' => 'So ngưỡng '.Vnd::format($th['article']), 'type' => 'badge', 'sortable' => true, 'filter' => true],
                ]" :rows="$topRows" :page-size="10" search="Tìm bài" empty="Chưa có bài nào ghi chi phí trong kỳ" /></div>
            </x-studio.panel>

            <x-studio.panel key="thresholds">
                <div>
                    <div class="ss-card" data-ai="thresholds"><div class="ss-card__h">Ngưỡng đang áp dụng <x-help title="Về ngưỡng"><p>Khi vượt chỉ cảnh báo, không dừng sinh bài. Ngưỡng đang lưu tạm.</p></x-help></div><div class="ss-card__b ss-text-sm" style="display:grid;gap:.375rem">
                        <div>Theo bài: <strong>{{ Vnd::format($th['article']) }}</strong></div>
                        <div>Theo tháng: <strong>{{ $th['month'] === null ? 'Chưa đặt' : Vnd::format($th['month']) }}</strong>@if ($th['month'] !== null) <span class="ss-muted" data-ai="month-meter">đã dùng {{ Vnd::format($monthCost ?? 0) }} ({{ Vnd::percent(($monthCost ?? 0) / $th['month']) }}) {{ $monthLabel }}</span>@endif</div>
                        <div><button type="button" class="ss-dt__btn" wire:click="mountAction('setThreshold')">Sửa ngưỡng</button></div>
                    </div></div>
                </div>
            </x-studio.panel>

            <x-studio.panel key="rules">
                <div class="ss-grid ss-text-sm">
                    <div class="ss-card"><div class="ss-card__h">Cách tính</div><div class="ss-card__b"><ul style="list-style:disc;padding-left:1.25rem;display:grid;gap:.25rem">
                        <li><strong>Ngưỡng theo bài:</strong> tổng cost_vnd của mọi phiên bản của một bài LỚN HƠN ngưỡng (đúng bằng thì không vượt; so sánh trên số đã làm tròn đồng) thì bài hiện "▲ Vượt ngưỡng". Chỉ cảnh báo, không chặn sinh bài. Mặc định 3.000 ₫ theo báo cáo PoC.</li>
                        <li><strong>Ngưỡng theo tháng:</strong> tổng cost_vnd các phiên bản sinh trong tháng dương lịch (giờ Việt Nam), toàn hệ thống. Để trống = không cảnh báo; nếu đặt phải lớn hơn hoặc bằng ngưỡng theo bài.</li>
                        <li><strong>Trung bình mỗi bài:</strong> tổng cost_vnd của MỌI phiên bản thuộc các bài có phiên bản sinh trong kỳ, chia cho số bài đó (sinh lại tính vào bài). Có thể lệch so với chi phí trong kỳ nếu bài có phiên bản ở kỳ khác.</li>
                        <li><strong>Chi phí tính tại thời điểm sinh:</strong> không tính lại khi đổi bảng giá hoặc tỉ giá. Tiền tệ VND, cộng trên giá trị lưu rồi mới làm tròn đồng nên tổng các dòng có thể lệch vài đồng.</li>
                        <li><strong>So với kỳ trước:</strong> kỳ liền trước cùng độ dài; chi phí và trung bình tính theo %, số bài và phiên bản theo số tuyệt đối. Giảm là tốt (màu xanh).</li>
                    </ul></div></div>
                    <div class="ss-card"><div class="ss-card__h">Quyền và dữ liệu thiếu</div><div class="ss-card__b"><ul style="list-style:disc;padding-left:1.25rem;display:grid;gap:.25rem">
                        <li><strong>Ai được xem và đặt:</strong> Quản trị và Người duyệt. Biên tập viên bị chặn (403).</li>
                        <li><strong>Thiếu dữ liệu:</strong> không có phiên bản trong kỳ thì hiện "Chưa có dữ liệu", không hiện số 0 giả. Phiên bản chưa ghi chi phí (cost_vnd trống, hoặc 0 ₫ với 0 token) được đếm riêng.</li>
                        <li><strong>Model lạ:</strong> khi model chưa có trong bảng giá, bộ tính rơi về giá gemini-1.5-flash nên chi phí có thể bị ghi thấp (cần Dev1 xác nhận).</li>
                        <li><strong>Cần Dev1 thêm:</strong> bảng cấu hình ngưỡng; nhật ký mỗi lượt gọi AI (token vào/ra, cache, ảnh, lỗi/thử lại, chiến dịch); nhật ký cảnh báo đã bắn (task 6.3).</li>
                    </ul></div></div>
                </div>
            </x-studio.panel>
        </x-studio.tabs>
    </div>
    @endif
</x-filament-panels::page>
