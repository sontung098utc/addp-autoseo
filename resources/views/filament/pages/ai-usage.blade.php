@php
    use App\Filament\Support\AiUsage\Vnd;
    $warn = '▲ Vượt ngưỡng';
    $avg = $summary['avg_per_article'] ?? null;
@endphp
<x-filament-panels::page>
    <style>
        .ai-box{border:1px solid rgba(128,128,128,.3);border-radius:.75rem;padding:1rem;margin-bottom:1rem}
        .ai-h{font-weight:600;margin-bottom:.5rem}
        .ai-muted{opacity:.75;font-size:.8rem;line-height:1.5}
        .ai-bar{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin:.75rem 0}
        .ai-inp{border:1px solid rgba(128,128,128,.4);border-radius:.4rem;padding:.35rem .5rem;background:transparent;color:inherit;font-size:.85rem}
        .ai-lede{font-size:1.05rem;line-height:1.6;max-width:75ch}
        .ai-num{font-family:ui-monospace,monospace;font-weight:600}
        .ai-table{width:100%;border-collapse:collapse;font-size:.8rem}
        .ai-table th,.ai-table td{text-align:left;padding:.4rem .5rem;border-bottom:1px solid rgba(128,128,128,.2);vertical-align:top}
        .ai-table .r{text-align:right;font-variant-numeric:tabular-nums}
        .ai-table-wrap{overflow-x:auto}
        .ai-warn{display:block;border:1px solid rgb(var(--warning-600));border-left-width:4px;border-radius:.4rem;padding:.6rem .85rem;margin-bottom:.75rem;font-size:.85rem;line-height:1.5}
        .ai-danger{border-color:rgb(var(--danger-600))}
        .ai-flag{font-weight:600;white-space:nowrap}
        .ai-svg{display:block;width:100%;min-width:520px;height:auto}
        .ai-scroll{overflow-x:auto}
        .ai-bar-rect{fill:rgb(var(--primary-600))}
        .ai-grid{stroke:rgba(128,128,128,.3);stroke-width:1}
        .ai-tick{fill:currentColor;font:11px ui-monospace,monospace;opacity:.75}
        .ai-pace{stroke:currentColor;stroke-width:1.5;stroke-dasharray:5 4}
        .ai-todo li{margin-bottom:.4rem;font-size:.8rem;line-height:1.5}
        .ai-law h3{font-weight:600;font-size:.85rem;margin:.75rem 0 .25rem}
        .ai-law ul{list-style:disc;padding-left:1.25rem;font-size:.8rem;line-height:1.6}
        .ai-split{display:grid;grid-template-columns:minmax(0,1fr) 20rem;gap:1rem}
        @media (max-width:1000px){.ai-split{grid-template-columns:minmax(0,1fr)}}
    </style>

    @if ($hasDemo)
        <x-demo-banner source="dữ liệu thật" real="mọi con số ở màn này tính từ bảng article_versions; các bản ghi bắt đầu bằng “[Mẫu AI] ” chỉ là dữ liệu xem thử"/>
    @endif

    <p class="ai-muted">AI đang tốn bao nhiêu, ở model nào, ở bài nào và đã chạm ngưỡng cảnh báo chưa. Số liệu lấy THẬT từ các phiên bản bài do AI sinh. Chỉ Quản trị và Người duyệt xem và đặt được ngưỡng.</p>

    <div class="ai-bar" role="group" aria-label="Bộ lọc">
        <label for="ai-period"><strong>Kỳ</strong></label>
        <select id="ai-period" class="ai-inp" wire:model.live="period">
            @foreach (\App\Filament\Pages\AiUsagePage::PERIODS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
        </select>
        @if ($period === 'tuy-chon')
            <label for="ai-from">Từ</label><input id="ai-from" type="date" class="ai-inp" wire:model.live="customFrom">
            <label for="ai-to">đến</label><input id="ai-to" type="date" class="ai-inp" wire:model.live="customTo">
        @endif
        <label for="ai-site"><strong>Website</strong></label>
        <select id="ai-site" class="ai-inp" wire:model.live="websiteId">
            <option value="">Tất cả website</option>
            @foreach ($websites as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach
        </select>
        <span class="ai-muted">Kỳ đang xem: {{ $label }} (giờ Việt Nam)</span>
    </div>
    @if ($rangeError)<div class="ai-warn" role="alert">{{ $rangeError }}</div>@endif

    {{-- cảnh báo: luật hiện ngay trên giao diện --}}
    @if ($monthExceeds)
        <div class="ai-warn ai-danger" role="alert" data-ai="month-over">
            <strong>▲ Chi phí {{ $monthLabel }} đã vượt ngưỡng tháng.</strong>
            {{ Vnd::format($monthCost) }} trên ngưỡng {{ Vnd::format($th['month']) }} (vượt {{ Vnd::format($monthCost - $th['month']) }}). Hệ thống vẫn tiếp tục sinh bài; ngưỡng chỉ cảnh báo, không tự dừng.
            Thông báo qua email hoặc chat sẽ có khi kênh thông báo (task 6.3) được kết nối; hiện chỉ hiện banner này.
            <button type="button" class="fi-link" wire:click="mountAction('setThreshold')" style="text-decoration:underline">Sửa ngưỡng</button>
        </div>
    @endif
    @if ($avgExceeds)
        <div class="ai-warn" role="alert" data-ai="avg-over">
            <strong>▲ Trung bình {{ Vnd::format($avg) }}/bài, vượt ngưỡng {{ Vnd::format($th['article']) }}/bài.</strong>
            Tính trên {{ $summary['articles_with_cost'] }} bài có phiên bản sinh trong kỳ, sinh lại tính vào bài.
        </div>
    @endif
    @if (count($overArticles) > 0)
        <div class="ai-warn" data-ai="articles-over">
            <strong>▲ {{ count($overArticles) }} bài vượt ngưỡng theo bài ({{ Vnd::format($th['article']) }}).</strong>
            Bài tốn nhất: “{{ $overArticles[0]['title'] }}” đã tốn {{ Vnd::format($overArticles[0]['cost']) }} sau {{ $overArticles[0]['versions'] }} phiên bản.
        </div>
    @endif

    @if (! $summary)
        <div class="ai-box" data-ai="empty">
            <div class="ai-h">Chưa có dữ liệu</div>
            <p class="ai-muted">Không có phiên bản bài nào do AI sinh trong kỳ {{ $label }}{{ $websiteId ? ' ở website đã chọn' : '' }}. Chi phí được ghi mỗi khi AI sinh một phiên bản bài (cột cost_vnd của article_versions). Bạn vẫn có thể đặt ngưỡng cảnh báo trước.</p>
        </div>
    @endif

    <div class="ai-split">
        <div>
            @if ($summary)
                <section class="ai-box" aria-labelledby="ai-sum-h" data-ai="summary">
                    <div class="ai-h" id="ai-sum-h">Tóm tắt {{ $label }}</div>
                    <p class="ai-lede">
                        @if ($summary['cost'] === null)
                            Có <span class="ai-num">{{ Vnd::int($summary['versions']) }}</span> phiên bản (<span class="ai-num">{{ Vnd::int($summary['articles']) }}</span> bài) nhưng chưa phiên bản nào ghi chi phí.
                        @else
                            AI tốn <span class="ai-num">{{ Vnd::format($summary['cost']) }}</span> cho <span class="ai-num">{{ Vnd::int($summary['articles']) }}</span> bài (<span class="ai-num">{{ Vnd::int($summary['versions']) }}</span> phiên bản, <span class="ai-num">{{ Vnd::int($summary['tokens']) }}</span> token)
                            @if ($avg !== null) · trung bình <span class="ai-num">{{ Vnd::format($avg) }}/bài</span>, {{ $avgExceeds ? 'vượt' : 'trong' }} ngưỡng {{ Vnd::format($th['article']) }}/bài.@else.@endif
                        @endif
                        @if ($summary['regen_articles'] > 0) {{ $summary['regen_articles'] }} bài phải sinh lại (tổng {{ $summary['extra_versions'] }} phiên bản sinh lại).@endif
                    </p>
                    @if ($summary['unrecorded'] > 0)
                        <p class="ai-muted">{{ $summary['unrecorded'] }} phiên bản chưa ghi chi phí (cost_vnd trống, hoặc 0 ₫ với 0 token). Các phiên bản này không được tính là 0 ₫ vào trung bình.</p>
                    @endif
                    <p class="ai-muted"><strong>Cách tính trung bình/bài:</strong> tổng cost_vnd của MỌI phiên bản thuộc các bài có phiên bản sinh trong kỳ, chia cho số bài đó (sinh lại tính vào bài). Vì vậy có thể lệch so với “chi phí trong kỳ” nếu bài có phiên bản ở kỳ khác. Chi phí được tính tại thời điểm sinh, đổi bảng giá hoặc tỉ giá sau đó không tính lại số cũ. Tiền tệ VND, làm tròn đồng khi hiển thị.</p>
                    @if ($th['month'] !== null)
                        <p class="ai-muted" data-ai="month-meter">Ngưỡng {{ $monthLabel }}: đã dùng {{ Vnd::format($monthCost ?? 0) }} / {{ Vnd::format($th['month']) }} ({{ $th['month'] > 0 ? Vnd::percent(($monthCost ?? 0) / $th['month']) : '' }}) — toàn hệ thống.</p>
                    @endif
                </section>

                <section class="ai-box" aria-labelledby="ai-day-h">
                    <div class="ai-h" id="ai-day-h">Chi phí theo ngày</div>
                    @php
                        $n = count($days);
                        $max = max($peak, $pace ?? 0, 1);
                        $W = 680; $H = 220; $L = 56; $B = 24; $T = 12;
                        $bw = max(2, ($W - $L) / max($n, 1));
                        $y = fn ($v) => $T + ($H - $T - $B) * (1 - $v / $max);
                    @endphp
                    <div class="ai-scroll">
                        <svg class="ai-svg" viewBox="0 0 {{ $W }} {{ $H }}" role="img" aria-labelledby="ai-ch-t ai-ch-d">
                            <title id="ai-ch-t">Chi phí AI theo ngày, {{ $label }}</title>
                            <desc id="ai-ch-d">{{ $this->describeChart($days, $peak, $peakDay, $pace) }} Bảng số liệu từng ngày nằm ngay bên dưới.</desc>
                            <line class="ai-grid" x1="{{ $L }}" x2="{{ $W }}" y1="{{ $y(0) }}" y2="{{ $y(0) }}"/>
                            <line class="ai-grid" x1="{{ $L }}" x2="{{ $W }}" y1="{{ $y($max) }}" y2="{{ $y($max) }}"/>
                            <text class="ai-tick" x="{{ $L - 6 }}" y="{{ $y(0) + 4 }}" text-anchor="end">0</text>
                            <text class="ai-tick" x="{{ $L - 6 }}" y="{{ $y($max) + 4 }}" text-anchor="end">{{ Vnd::int($max) }}</text>
                            @foreach ($days as $i => $d)
                                @if ($d['cost'] > 0)
                                    <rect class="ai-bar-rect" x="{{ $L + $i * $bw + 1 }}" y="{{ $y($d['cost']) }}" width="{{ max(1, $bw - 2) }}" height="{{ $y(0) - $y($d['cost']) }}"><title>{{ $d['date']->format('d/m') }}: {{ Vnd::format($d['cost']) }}</title></rect>
                                @endif
                                @if ($i === 0 || $i === $n - 1 || ($n > 8 && $i % intdiv($n, 6) === 0))
                                    <text class="ai-tick" x="{{ $L + $i * $bw + $bw / 2 }}" y="{{ $H - 6 }}" text-anchor="middle">{{ $d['date']->format('d/m') }}</text>
                                @endif
                            @endforeach
                            @if ($pace !== null)
                                <line class="ai-pace" x1="{{ $L }}" x2="{{ $W }}" y1="{{ $y($pace) }}" y2="{{ $y($pace) }}"/>
                                <text class="ai-tick" x="{{ $W }}" y="{{ $y($pace) - 4 }}" text-anchor="end">Nhịp đều ngưỡng tháng {{ Vnd::format($pace) }}/ngày</text>
                            @endif
                        </svg>
                    </div>
                    <p class="ai-muted">Đơn vị: đồng (₫), chi phí tại thời điểm sinh.@if ($pace !== null) Đường nét đứt = ngưỡng tháng {{ Vnd::format($th['month']) }} chia số ngày trong tháng, chỉ để tham chiếu; ngưỡng thật chỉ tính theo tháng và theo bài.@else Chưa đặt ngưỡng tháng nên không vẽ đường nhịp đều.@endif</p>
                    <details>
                        <summary class="ai-muted" style="cursor:pointer">Xem bảng số liệu theo ngày ({{ $n }} dòng)</summary>
                        <div class="ai-table-wrap" style="max-height:20rem;overflow:auto">
                            <table class="ai-table"><caption class="sr-only">Phiên bản, token và chi phí AI theo từng ngày</caption>
                                <thead><tr><th scope="col">Ngày</th><th scope="col" class="r">Phiên bản</th><th scope="col" class="r">Token</th><th scope="col" class="r">Chi phí</th></tr></thead>
                                <tbody>@foreach ($days as $d)<tr><td>{{ $d['date']->format('d/m/Y') }}</td><td class="r">{{ $d['versions'] }}</td><td class="r">{{ Vnd::int($d['tokens']) }}</td><td class="r">{{ $d['versions'] > 0 ? Vnd::format($d['cost']) : '—' }}</td></tr>@endforeach</tbody>
                            </table>
                        </div>
                    </details>
                </section>

                @foreach ([['byModel', 'Theo model', 'Model', 'ai_provider · ai_model'], ['byPrompt', 'Theo phiên bản prompt', 'Phiên bản prompt', 'prompt_version'], ['bySite', 'Theo website', 'Website', 'articles.website_id']] as [$var, $title, $col, $meta])
                    <section class="ai-box" data-ai="{{ $var }}">
                        <div class="ai-h">{{ $title }} <span class="ai-muted">{{ $meta }}</span></div>
                        <div class="ai-table-wrap">
                            <table class="ai-table"><caption class="sr-only">{{ $title }}</caption>
                                <thead><tr><th scope="col">{{ $col }}</th><th scope="col" class="r">Số bài</th><th scope="col" class="r">Phiên bản</th><th scope="col" class="r">Token</th><th scope="col" class="r">Chi phí</th><th scope="col" class="r">TB/bài (trong nhóm)</th><th scope="col">So ngưỡng {{ Vnd::format($th['article']) }}</th></tr></thead>
                                <tbody>
                                @foreach ($$var as $g)
                                    @php $over = \App\Filament\Support\AiUsage\UsageThresholds::exceeds($g['avg_per_article'], $th['article']); @endphp
                                    <tr>
                                        <td><strong>{{ $g['label'] }}</strong>@if ($var === 'byModel' && $g['provider'])<br><span class="ai-muted">{{ $g['provider'] }}</span>@endif</td>
                                        <td class="r">{{ $g['articles'] }}</td><td class="r">{{ $g['versions'] }}</td><td class="r">{{ Vnd::int($g['tokens']) }}</td>
                                        <td class="r">{{ $g['cost'] === null ? 'Chưa ghi chi phí' : Vnd::format($g['cost']) }}@if ($g['share'] !== null) <span class="ai-muted">({{ Vnd::percent($g['share']) }})</span>@endif</td>
                                        <td class="r">{{ Vnd::format($g['avg_per_article']) }}</td>
                                        <td>@if ($g['avg_per_article'] === null)—@elseif ($over)<span class="ai-flag">{{ $warn }}</span>@else Trong ngưỡng @endif</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </section>
                @endforeach

                <section class="ai-box" data-ai="top">
                    <div class="ai-h">Bài tốn nhất <span class="ai-muted">tổng cost_vnd mọi phiên bản của bài · so với ngưỡng {{ Vnd::format($th['article']) }}/bài</span></div>
                    <div class="ai-table-wrap">
                        <table class="ai-table"><caption class="sr-only">Các bài có chi phí AI cao nhất</caption>
                            <thead><tr><th scope="col">Bài</th><th scope="col" class="r">Chi phí</th><th scope="col" class="r">Phiên bản</th><th scope="col" class="r">Token</th><th scope="col">So với ngưỡng</th></tr></thead>
                            <tbody>
                            @forelse ($topArticles as $a)
                                <tr>
                                    <td><a href="{{ \App\Filament\Resources\ArticleVersionResource::getUrl('view', ['record' => $a['version_id']]) }}" class="fi-link"><strong>{{ $a['title'] }}</strong></a><br><span class="ai-muted">{{ $a['website'] }} · {{ implode(', ', $a['models']) ?: 'không rõ model' }}</span></td>
                                    <td class="r">{{ Vnd::format($a['cost']) }}</td><td class="r">{{ $a['versions'] }}</td><td class="r">{{ Vnd::int($a['tokens']) }}</td>
                                    <td>@if (\App\Filament\Support\AiUsage\UsageThresholds::exceeds($a['cost'], $th['article']))<span class="ai-flag">{{ $warn }}</span>@else Trong ngưỡng @endif</td>
                                </tr>
                            @empty
                                <tr><td colspan="5">Chưa có bài nào ghi chi phí trong kỳ.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    <p class="ai-muted">Bài nhiều phiên bản là bài bị sinh lại nhiều. Chỉ cảnh báo, không chặn sinh bài.</p>
                </section>
            @endif
        </div>

        <aside>
            <section class="ai-box" data-ai="thresholds">
                <div class="ai-h">Ngưỡng đang áp dụng</div>
                <dl class="ai-muted" style="line-height:1.8">
                    <div>Theo bài: <span class="ai-num">{{ Vnd::format($th['article']) }}</span></div>
                    <div>Theo tháng: <span class="ai-num">{{ $th['month'] === null ? 'Chưa đặt (không cảnh báo)' : Vnd::format($th['month']) }}</span></div>
                    <div>Phạm vi: toàn hệ thống</div>
                    <div>Kênh nhận cảnh báo: Chưa kết nối (task 6.3)</div>
                    <div>Khi vượt: hiện banner, không tự dừng sinh bài.</div>
                </dl>
                <p class="ai-muted">Ngưỡng đang LƯU TẠM, sẽ chuyển sang bảng cấu hình khi Dev1 thêm.</p>
                <button type="button" class="fi-link" wire:click="mountAction('setThreshold')" style="text-decoration:underline;font-size:.8rem">Sửa ngưỡng</button>
            </section>
            <section class="ai-box" data-ai="na">
                <div class="ai-h">Chưa có số liệu</div>
                <ul class="ai-todo">
                    <li><strong>Token vào / ra tách riêng</strong><br><span class="ai-muted">Chưa có dữ liệu — hệ thống chỉ lưu tổng tokens_used. Sẽ có khi ghi usage từng lượt gọi AI.</span></li>
                    <li><strong>Cache hit</strong><br><span class="ai-muted">Chưa có dữ liệu — chưa lưu số token được cache.</span></li>
                    <li><strong>Chi phí ảnh AI và alt ảnh</strong><br><span class="ai-muted">Chưa có dữ liệu — lượt sinh alt ảnh chưa tạo dòng chi phí.</span></li>
                    <li><strong>Lượt sinh lỗi hoặc thử lại</strong><br><span class="ai-muted">Chưa có dữ liệu — chỉ lượt thành công tạo phiên bản và có chi phí.</span></li>
                    <li><strong>Theo chiến dịch</strong><br><span class="ai-muted">Chưa có dữ liệu — sẽ có khi có bảng chiến dịch.</span></li>
                </ul>
            </section>
        </aside>
    </div>

    <details class="ai-box ai-law" id="ai-rules">
        <summary style="cursor:pointer;font-weight:600">Luật nghiệp vụ của màn này</summary>
        <h3>Luật</h3>
        <ul>
            <li><strong>Ngưỡng theo bài:</strong> tổng cost_vnd của mọi phiên bản của một bài LỚN HƠN ngưỡng (đúng bằng ngưỡng thì không vượt; so sánh trên số đã làm tròn đồng) thì bài hiện “▲ Vượt ngưỡng” và banner. Chỉ cảnh báo, không chặn sinh bài. Mặc định 3.000 ₫ theo báo cáo PoC.</li>
            <li><strong>Ngưỡng theo tháng:</strong> tổng cost_vnd các phiên bản sinh trong tháng dương lịch (giờ Việt Nam), toàn hệ thống. Để trống = không cảnh báo; nếu đặt phải lớn hơn hoặc bằng ngưỡng theo bài.</li>
            <li><strong>Ai được xem và đặt:</strong> Quản trị và Người duyệt. Biên tập viên bị chặn (403), người dùng ngừng hoạt động hoặc chưa đăng nhập không vào được.</li>
            <li><strong>Chi phí tính tại thời điểm sinh:</strong> không tính lại khi đổi bảng giá hoặc tỉ giá.</li>
            <li><strong>Trung bình mỗi bài:</strong> tổng chi phí mọi phiên bản của các bài có phiên bản sinh trong kỳ ÷ số bài đó; sinh lại tính vào bài.</li>
            <li><strong>Thiếu dữ liệu:</strong> không có phiên bản trong kỳ thì hiện “Chưa có dữ liệu”, không hiện số 0 giả. Phiên bản chưa ghi chi phí (cost_vnd trống, hoặc 0 ₫ với 0 token) được đếm riêng, không tính là 0 ₫.</li>
            <li><strong>Làm tròn:</strong> cộng trên giá trị lưu rồi mới làm tròn đồng, nên tổng các dòng đã làm tròn có thể lệch vài đồng.</li>
            <li><strong>Model lạ:</strong> bộ tính chi phí rơi về giá gemini-1.5-flash khi model chưa có trong bảng giá; chi phí của model đó có thể bị ghi thấp (cần Dev1 xác nhận).</li>
        </ul>
        <h3>Dữ liệu hệ thống cần thêm (Dev1)</h3>
        <ul>
            <li>Bảng cấu hình ngưỡng (theo bài, theo tháng, phạm vi, người sửa, thời điểm sửa) thay cho bộ nhớ tạm hiện tại.</li>
            <li>Nhật ký mỗi lượt gọi AI: token vào/ra, token cache, chi phí ảnh, trạng thái lỗi/thử lại, khóa bài, khóa chiến dịch.</li>
            <li>Nhật ký cảnh báo đã bắn để không báo trùng khi có kênh thông báo (task 6.3).</li>
        </ul>
    </details>
</x-filament-panels::page>
