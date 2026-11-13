{{-- Chi tiết MỘT phiên chạy (Diễn biến, Báo cáo, Chi phí và model, Dữ liệu). Dùng chung cho trang phiên không gắn bài và tab Phiên chạy của trang bài. Dữ liệu: App\Filament\Support\Agent\RunViewData::for(). --}}
@php
    use App\Filament\Support\Agent\RunFlow;
    use App\Filament\Support\Schedule\VnTime;

    $stepLabel = ['ok' => 'Xong', 'warn' => 'Cần chú ý', 'blocked' => 'Bị chặn', 'failed' => 'Lỗi', 'skipped' => 'Bỏ qua'];
    $stepState = ['ok' => 'done', 'warn' => 'warn', 'blocked' => 'blocked', 'failed' => 'failed', 'skipped' => 'skipped'];
    $roles = ['system' => 'Hệ thống', 'strategist' => 'Chiến lược', 'writer' => 'Người viết', 'editor' => 'Biên tập'];
    $modeLabels = ['cautious' => 'Thận trọng', 'balanced' => 'Cân bằng'];
    $badgeShort = ['running' => 'Đang chạy', 'ready_for_review' => 'Xong', 'held' => 'Giữ', 'blocked' => 'Bị chặn', 'failed' => 'Lỗi'];
    [, $sColor] = $statusMap[$run->status] ?? [$run->status, 'gray'];
    $sLabel = $badgeShort[$run->status] ?? $run->status;
    if (collect($flow['nodes'])->contains('state', 'waiting')) { [$sLabel, $sColor] = ['Chờ bạn', 'warning']; }
    if ($stale) { [$sLabel, $sColor] = ['Có thể bị treo', 'warning']; }
    $tone = ['success' => 'done', 'warning' => 'warn', 'danger' => 'failed', 'info' => 'running', 'gray' => 'idle'][$sColor] ?? 'idle';
    $nodes = $flow['nodes'];
    $rounds = $flow['rounds'];
    $threshold = $flow['threshold'];
    $fs = $flow['finalScore'];
    $scoreTone = $fs === null ? 'idle' : ($fs >= $threshold ? 'done' : ($fs >= 50 ? 'warn' : 'failed'));
    $fmtSec = fn (?int $s) => $s === null ? null : ($s >= 60 ? intdiv($s, 60).' phút '.($s % 60).' giây' : $s.' giây');
    $startedAt = VnTime::fromRaw($run, 'started_at') ?? VnTime::fromRaw($run, 'created_at');
    $tail = fn (string $t, int $n = 150) => mb_strlen($t) > $n ? [rtrim(mb_substr($t, 0, $n)).'…', true] : [$t, false];
    $retries = $run->steps->where('stage', 'retry');
    $aiNote = ($run->ai_mode === 'real' ? 'AI thật' : 'AI giả lập: nội dung chỉ để kiểm luồng, không phản ánh chất lượng AI thật.').' Bậc tin cậy '.$run->trust_tier.($run->trust_tier === 'T1' ? ': chỉ tạo bản nháp chờ người duyệt, không bao giờ tự xuất bản.' : '.');
    $isAdmin = (bool) auth()->user()?->isAdmin();
    $problem = in_array($run->status, ['failed', 'blocked', 'held'], true) || $stale || collect($nodes)->contains('state', 'waiting');
    $tabs = [
        ['key' => 'dien-bien', 'label' => 'Diễn biến', 'tone' => $live ? 'info' : ($problem ? ($run->status === 'failed' ? 'danger' : 'warn') : null)],
        ['key' => 'bao-cao', 'label' => 'Báo cáo và dữ liệu'],
        ['key' => 'chi-phi', 'label' => 'Chi phí và model'],
    ];
    $report = \App\Filament\Support\Agent\RunReport::for($run);
    $jobTitle = \App\Services\Agent\Pipeline\JobTypeRegistry::find((string) ($run->job_type ?: 'article_write'))['title'] ?? $run->job_type;
    $verdictIcon = ($verdict['tone'] ?? '') === 'failed' ? 'heroicon-o-x-circle' : 'heroicon-o-exclamation-triangle';
    $modelList = collect($nodes)->pluck('models')->flatten()->filter()->unique()->values()->all();
    $loopCount = collect($nodes)->firstWhere('code', 'repair')['count'] ?? 0;
@endphp
    <x-flow.style />
    <style>
        .rvx{display:flex;flex-direction:column;gap:.9rem}
        .rvx-head{display:flex;flex-wrap:wrap;gap:.5rem 1.2rem;align-items:center;justify-content:space-between}
        .rvx-id,.rvx-actions{display:flex;flex-wrap:wrap;gap:.3rem .6rem;align-items:center;font-size:.8rem}
        .rv-incident{border:1px solid var(--rv-warn);border-radius:.5rem}
        .rvx-alert{display:flex;flex-wrap:wrap;gap:.5rem .8rem;align-items:center;padding:.55rem .9rem;border-radius:.7rem;border:1px solid var(--fl-warn);background:rgba(217,119,6,.09);font-size:.88rem;font-weight:600}
        .rvx-alert--failed{border-color:var(--fl-fail);background:rgba(220,38,38,.08)}
        .rvx-alert span{flex:1 1 14rem}
        .rvx-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:.75rem}
        @media (max-width:1100px){.rvx-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}}
        @media (max-width:640px){.rvx-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
        .rvx-kpi{position:relative;display:flex;flex-direction:column;gap:.1rem;padding:.8rem .95rem;border-radius:.9rem;border:1px solid var(--fl-line);background:var(--fl-card);box-shadow:var(--fl-shadow);min-width:0}
        .rvx-kpi b{font-size:1.45rem;font-weight:800;line-height:1.15;letter-spacing:-.01em;overflow-wrap:anywhere}
        .rvx-kpi small{font-size:.7rem;opacity:.7;font-weight:600;line-height:1.3}
        .rvx-kpi__ic{width:2.2rem;height:2.2rem;border-radius:.65rem;display:inline-flex;align-items:center;justify-content:center;background:var(--fl-soft);margin-bottom:.35rem}
        .rvx-kpi--done{border-color:rgba(5,150,105,.45)}.rvx-kpi--done .rvx-kpi__ic{background:rgba(5,150,105,.16);color:var(--fl-done)}.rvx-kpi--done b{color:var(--fl-done)}
        .rvx-kpi--warn{border-color:rgba(217,119,6,.5)}.rvx-kpi--warn .rvx-kpi__ic{background:rgba(217,119,6,.16);color:var(--fl-warn)}.rvx-kpi--warn b{color:var(--fl-warn)}
        .rvx-kpi--failed{border-color:rgba(220,38,38,.5)}.rvx-kpi--failed .rvx-kpi__ic{background:rgba(220,38,38,.14);color:var(--fl-fail)}.rvx-kpi--failed b{color:var(--fl-fail)}
        .rvx-kpi--running{border-color:rgba(2,132,199,.5)}.rvx-kpi--running .rvx-kpi__ic{background:rgba(2,132,199,.14);color:var(--fl-run)}.rvx-kpi--running b{color:var(--fl-run)}
        .rvx-flowpane{padding:1rem 1.1rem 1.2rem;background:var(--fl-card);box-shadow:var(--fl-shadow)}
        .rvx-pane{border:1px solid var(--fl-line);border-radius:.75rem;padding:.9rem 1rem}
        /* Chi tiết bước: một trang dính sát mép phải, cao hết màn hình (ngăn trượt); không đẩy lưu đồ; đóng bằng X, Esc hoặc bấm nền mờ. */
        .rvx-drawer{position:fixed;top:0;right:0;bottom:0;z-index:60;width:min(30rem,100vw);height:100vh;max-height:none;overflow:auto;border-radius:0;border-width:0 0 0 1px;background:var(--fl-card,#fff);box-shadow:-12px 0 40px rgba(15,23,42,.22);padding:3.2rem 1.25rem 1.5rem}
        .rvx-drawer__x{position:absolute;top:.9rem;right:.9rem;display:inline-flex;align-items:center;justify-content:center;width:2rem;height:2rem;border:0;border-radius:999px;background:transparent;color:inherit;cursor:pointer;opacity:.7}
        .rvx-drawer__x:hover{opacity:1;background:rgba(128,128,128,.18)}
        .rvx-drawer h3{padding-right:2.5rem;font-size:1rem}
        .rvx-io{margin:.5rem 0 .25rem;font-size:.8rem}
        .rvx-io__h{margin-top:.75rem;font-size:.7rem;font-weight:600;letter-spacing:.04em;text-transform:uppercase;opacity:.65}
        .rvx-io__b{margin-top:.4rem}
        .rvx-io__k{font-size:.72rem;opacity:.65}
        .rvx-io__t{white-space:pre-line;overflow-wrap:anywhere}
        .rvx-io__ul,.rvx-io__ol{margin:.15rem 0 0 1.1rem;padding:0}
        .rvx-io__ul{list-style:disc}.rvx-io__ol{list-style:decimal}
        .rvx-io__ul li,.rvx-io__ol li{margin:.15rem 0;overflow-wrap:anywhere}
        .rvx-io__d summary{cursor:pointer;opacity:.75;font-size:.75rem}
        .rvx-io__pre{margin:.3rem 0 0;max-height:16rem;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere;font-size:.72rem;line-height:1.45;padding:.5rem;border-radius:.4rem;background:rgba(128,128,128,.12)}
        .rvx-io__slot{margin:.3rem 0;padding:.4rem .5rem;border-radius:.4rem;background:rgba(128,128,128,.08)}
        .rvx-io__imgs{display:grid;grid-template-columns:repeat(auto-fill,minmax(8.5rem,1fr));gap:.5rem}
        .rvx-io__imgs img{width:100%;height:auto;border-radius:.4rem;display:block}
        .rvx-io__imgs figcaption{font-size:.72rem;margin-top:.2rem}
        .rvx-scrim{position:fixed;inset:0;z-index:59;background:rgba(15,23,42,.25)}

        .rvx-pane h3{margin:0 0 .5rem;font-size:.95rem;font-weight:700;display:flex;gap:.5rem;align-items:center;flex-wrap:wrap}
        .rvx-step{border-top:1px solid var(--fl-line);padding:.55rem 0;font-size:.8rem}.rvx-step:first-of-type{border-top:0}
        .rvx-step .t{display:flex;flex-wrap:wrap;gap:.4rem;align-items:center}
        .rvx-note{font-size:.75rem;opacity:.75}
        .rvx-table{width:100%;font-size:.8rem;border-collapse:collapse}.rvx-table th,.rvx-table td{padding:.35rem .5rem;border-bottom:1px solid var(--fl-line);text-align:left}.rvx-table td.n,.rvx-table th.n{text-align:right;white-space:nowrap}
        .rvx-pre{font-size:.72rem;white-space:pre-wrap;overflow-wrap:anywhere;background:rgba(128,128,128,.08);border-radius:.5rem;padding:.6rem;max-height:22rem;overflow:auto}
        .rvx details>summary{cursor:pointer;font-weight:600;font-size:.85rem}
    </style>
    @if (! empty($flowOnly))
        {{-- Chỉ phần Diễn biến (không đầu trang, không tab): dùng ở Tổng quan của trang bài cho phiên mới nhất; bấm từng bước vẫn mở ngăn chi tiết như trong tab Phiên chạy. --}}
        <div class="rvx fl" data-testid="agent-run-flow">
            @include('filament.agent.partials.run-flow')
        </div>
    @else
    <div class="rvx fl" data-testid="agent-run" @if ($live) wire:poll.{{ $pollSeconds }}s @endif>
        @if ($live)
            <div class="ss-live-pill rv-live" data-testid="run-live">
                <x-studio.running label="Đang chạy" />
                <span>· Đang ở: <strong>{{ collect($nodes)->firstWhere('code', $currentStage)['title'] ?? $blueprint[$currentStage]['title'] ?? 'hoàn tất, đang đóng phiên' }}</strong> · đã chạy {{ $elapsed }}</span>
            </div>
        @elseif ($stale)
            <div data-testid="run-stale" class="rv-stale">
                <x-filament::icon icon="heroicon-m-clock" style="width:1.1rem;height:1.1rem" />
                <span>Có thể bị treo <x-studio.hint tone="warning" :text="'Phiên vẫn ghi là đang chạy nhưng quá '.\App\Filament\Support\Agent\RunActivity::STALE_MINUTES.' phút không có bước mới. Đã ngừng làm mới tự động; tải lại trang để kiểm tra.'" /></span>
            </div>
        @endif

        {{-- Đầu trang: huy hiệu trạng thái ngắn, #id · giờ, nút hành động. Không câu kết luận nào. --}}
        <div class="rvx-head" data-testid="run-status-bar">
            <div class="rvx-id" data-testid="run-meta">
                <span class="rv-outcome" data-testid="run-badge"><x-filament::badge :color="$sColor">{{ $sLabel }}</x-filament::badge></span>
                <span class="rv-sub">#{{ $run->id }} · {{ $startedAt ? VnTime::format($startedAt, 'd/m/Y H:i') : '—' }}</span>
                @if ($campaign)<a class="rv-chip rv-chip--link" href="{{ \App\Filament\Resources\CampaignResource::getUrl('view', ['record' => $campaign]) }}">{{ $campaign->name }}</a>@endif
                <x-help title="Về phiên này">
                    <p>{{ $aiNote }}</p>
                    @if ($run->website)<p>Website: {{ $run->website->name }}.</p>@endif
                    @if ($run->risk_level)<p>Hạng rủi ro: {{ ['low'=>'thấp','medium'=>'vừa','high'=>'cao'][$run->risk_level] ?? $run->risk_level }}.</p>@endif
                    @if ($campaign)<p>Chế độ chiến dịch: {{ $modeLabels[$campaign->mode] ?? $campaign->mode }}.</p>@endif
                    @if ($run->article?->primary_keyword)<p>Từ khóa: {{ $run->article->primary_keyword }}.</p>@endif
                </x-help>
            </div>
        </div>

        @if ($verdict)
            <div class="rvx-alert rvx-alert--{{ $verdict['tone'] }}" data-testid="run-alert" role="alert">
                <x-filament::icon :icon="$verdictIcon" style="width:1.2rem;height:1.2rem;flex:none" />
                <span>{{ $verdict['text'] }}</span>
                @if ($verdict['action'])<x-filament::button tag="a" size="sm" :href="$verdict['action']['url']" data-testid="run-alert-action">{{ $verdict['action']['label'] }}</x-filament::button>@endif
            </div>
        @endif

        <x-studio.tabs :default="$tab" :tabs="$tabs" label="Các phần của phiên chạy">
            {{-- ===== Diễn biến ===== --}}
            <x-studio.panel key="dien-bien">
                @include('filament.agent.partials.run-flow')
            </x-studio.panel>

            {{-- ===== Báo cáo ===== --}}
            <x-studio.panel key="bao-cao">
                <div class="rvx">
                    <section class="ss-review-card" data-testid="run-report">
                        <h2 class="ss-review-card__title">{{ $report['title'] }}</h2>
                        <ul style="margin:0 0 .5rem 1rem;list-style:disc">@foreach ($report['lines'] as $l)<li>{{ $l }}</li>@endforeach</ul>
                    </section>
                    <section class="rvx-pane" data-testid="run-result">
                        <h3>Kết quả</h3>
                        <div class="rv-result">
                            @if ($run->outcome_reason)<div class="rv-result__reason"><span class="rv-k">Lý do kết thúc</span> {{ $run->outcome_reason }}</div>@endif
                            @if ($run->finalVersion)
                                <div>Bản nháp v{{ $run->finalVersion->version }} đã tạo (xem ở tab Nội dung).</div>
                            @endif
                            @include('filament.agent.cms-draft-line', ['version' => $run->finalVersion])
                            @if ($run->ai_mode !== 'real')
                                <div class="rv-warn"><x-filament::icon icon="heroicon-m-exclamation-triangle" style="width:1rem;height:1rem" /> AI giả lập <x-studio.hint tone="warning" text="Phiên này dùng AI giả lập nên nội dung chỉ để kiểm luồng, không phản ánh chất lượng AI thật." /></div>
                            @endif
                        </div>
                    </section>
                </div>
                {{-- Dữ liệu thô và nhật ký từng bước (trước đây là tab riêng) nằm cùng báo cáo. --}}
                <div class="rvx" data-testid="run-data">
                    <div class="rvx-pane">
                        <h3>Đầu vào và đầu ra <x-help text="Dữ liệu thô của phiên: thứ phiên đã nhận vào và thứ nó ghi ra. Chỉ quản trị xem được." /></h3>
                        @if ($isAdmin)
                            <div class="rv-k">Đầu vào</div><pre class="rvx-pre" data-testid="run-input-ref">{{ json_encode($run->input_refs ?: new stdClass, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre>
                            <div class="rv-k" style="margin-top:.5rem">Đầu ra</div><pre class="rvx-pre" data-testid="run-output-ref">{{ json_encode($run->output_ref ?: new stdClass, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre>
                        @else
                            <p class="rvx-note">Dữ liệu kỹ thuật chỉ hiện cho quản trị.</p>
                        @endif
                    </div>
                    <details class="rvx-pane">
                        <summary>Nhật ký từng bước ({{ $run->steps->count() }}) <x-help text="Mỗi bước ghi rõ giờ, người làm và lý do hệ thống làm như vậy." /></summary>
                        <ol class="rv-tl" style="margin-top:.6rem">
                            @foreach ($run->steps as $s)
                                @php
                                    $st = $stepState[$s->status] ?? 'idle';
                                    $at = $flow['clock'][$s->id] ?? null;
                                    [$short, $isLong] = $tail((string) $s->detail);
                                @endphp
                                <li class="rv-tl__item rv-tl__item--{{ $st }}" data-log-step="{{ $s->stage }}">
                                    <div class="rv-tl__time">{{ $at ? VnTime::format($at, 'H:i:s') : '—' }}<small>#{{ $s->seq }}@if (($flow['durations'][$s->id] ?? null) !== null) · +{{ $flow['durations'][$s->id] }}s @endif</small></div>
                                    <div class="rv-tl__dot" aria-hidden="true"></div>
                                    <div class="rv-tl__main">
                                        <div class="rv-tl__head"><strong>{{ $s->title }}</strong>
                                            <x-filament::badge size="sm" :color="['ok' => 'success', 'warn' => 'warning', 'blocked' => 'gray', 'failed' => 'danger', 'skipped' => 'gray'][$s->status] ?? 'gray'">{{ $stepLabel[$s->status] ?? $s->status }}</x-filament::badge>
                                        </div>
                                        <div class="rv-tl__who" data-testid="step-role-skill">Vai trò: {{ $s->data['role_name'] ?? ($roles[$s->role] ?? $s->role) }} · Kỹ năng: {{ $s->data['skill_label'] ?? 'Chỉ dẫn mặc định' }}@if (! empty($s->data['skill'])) · {{ $s->data['skill']['status'] }} · {{ $s->data['skill']['checksum'] }}@endif @if (! empty($s->data['skill_note'])) · {{ $s->data['skill_note'] }}@endif @if ($s->model)· Model: {{ $s->model }}@endif</div>
                                        <div class="rv-tl__detail">{{ $short }} @if ($isLong)<x-studio.hint :text="$s->detail" />@endif</div>
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    </details>
                    <details class="rvx-pane">
                        <summary>Chặng nào đã thật, chặng nào cần Dev1 <x-help text="Bản thiết kế sống: cùng một nguồn với trang Cách hoạt động." /></summary>
                        @foreach ($blueprint as $b)
                            <div class="rv-bp">
                                <x-studio.chip domain="readiness" :value="$b['readiness']" /> <strong class="text-sm">{{ $b['title'] }}</strong>
                                <div class="text-xs" style="opacity:.8">{{ $b['what'] }}</div>
                                <div class="text-xs" style="opacity:.8"><strong>Đã có:</strong> {{ $b['built'] }}</div>
                                <div class="text-xs" style="color:#b45309"><strong>Dev1 cần ({{ $b['task'] }}):</strong> {{ $b['dev1'] }}</div>
                            </div>
                        @endforeach
                    </details>
                </div>
            </x-studio.panel>

            {{-- ===== Chi phí và model ===== --}}
            <x-studio.panel key="chi-phi">
                <div class="rvx" data-testid="run-cost">
                    <div class="rv-status">
                        <div class="rv-tile" data-testid="run-budget">
                            <div class="rv-k">Chi phí / ngân sách phiên <x-studio.hint text="Ngân sách phiên hiện là giá trị mặc định trong RunPolicy; chưa nối cài đặt ngân sách thật theo ngày/tháng (AG-B09)." tone="warning" /></div>
                            <div class="rv-mid">{{ number_format($flow['cost'], 0, ',', '.') }} ₫ <span class="rv-small">/ {{ number_format($flow['budget'], 0, ',', '.') }} ₫</span></div>
                            <div class="rv-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $flow['budgetPct'] }}"><i style="width:{{ $flow['budgetPct'] }}%" class="{{ $flow['budgetPct'] >= 90 ? 'is-hot' : '' }}"></i></div>
                        </div>
                        <div class="rv-tile"><div class="rv-k">Token</div><div class="rv-mid">{{ number_format($flow['tokens'], 0, ',', '.') }}</div></div>
                        <div class="rv-tile"><div class="rv-k">Tổng thời gian</div><div class="rv-mid">{{ \App\Filament\Support\Agent\RunActivity::formatElapsed($flow['seconds']) }}</div>@if ($live)<div class="rv-small">đang chạy</div>@endif</div>
                    </div>
                    <div class="rvx-pane" style="overflow-x:auto">
                        <h3>Chi phí từng chặng</h3>
                        <table class="rvx-table" data-testid="cost-table">
                            <thead><tr><th>Chặng</th><th>Người phụ trách</th><th>Model</th><th class="n">Token</th><th class="n">Chi phí</th><th class="n">Thời lượng</th></tr></thead>
                            <tbody>
                            @foreach ($nodes as $n)
                                @continue($n['count'] === 0)
                                <tr data-cost-stage="{{ $n['code'] }}"><td>{{ $n['title'] }}@if ($n['count'] > 1) ×{{ $n['count'] }}@endif</td><td>{{ $n['who']['title'] }}</td><td>{{ implode(', ', $n['models']) ?: '—' }}</td><td class="n">{{ number_format($n['tokens'], 0, ',', '.') }}</td><td class="n">{{ number_format($n['cost'], 0, ',', '.') }} ₫</td><td class="n">{{ $n['seconds'] !== null ? $fmtSec($n['seconds']) : '—' }}</td></tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </x-studio.panel>

        </x-studio.tabs>
    </div>
    @endif
