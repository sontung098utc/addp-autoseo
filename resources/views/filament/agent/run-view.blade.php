@php
    use App\Filament\Support\Agent\RunFlow;
    use App\Filament\Support\Schedule\VnTime;

    $stepLabel = ['ok' => 'Xong', 'warn' => 'Cần chú ý', 'blocked' => 'Bị chặn', 'failed' => 'Lỗi', 'skipped' => 'Bỏ qua'];
    $stepState = ['ok' => 'done', 'warn' => 'warn', 'blocked' => 'blocked', 'failed' => 'failed', 'skipped' => 'skipped'];
    $roles = ['system' => 'Hệ thống', 'strategist' => 'Chiến lược', 'writer' => 'Người viết', 'editor' => 'Biên tập'];
    $modeLabels = ['cautious' => 'Thận trọng', 'balanced' => 'Cân bằng'];
    [$sLabel, $sColor] = $statusMap[$run->status] ?? [$run->status, 'gray'];
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
        ['key' => 'bao-cao', 'label' => 'Báo cáo'],
        ['key' => 'chi-phi', 'label' => 'Chi phí và model'],
        ['key' => 'du-lieu', 'label' => 'Dữ liệu'],
    ];
    $report = \App\Filament\Support\Agent\RunReport::for($run);
    $jobTitle = \App\Services\Agent\Pipeline\JobTypeRegistry::find((string) ($run->job_type ?: 'article_write'))['title'] ?? $run->job_type;
    $verdictIcon = ['done' => 'heroicon-o-check-circle', 'warn' => 'heroicon-o-exclamation-triangle', 'failed' => 'heroicon-o-x-circle', 'running' => 'heroicon-o-arrow-path'][$verdict['tone']] ?? 'heroicon-o-information-circle';
    $modelList = collect($nodes)->pluck('models')->flatten()->filter()->unique()->values()->all();
    $loopCount = collect($nodes)->firstWhere('code', 'repair')['count'] ?? 0;
@endphp
<x-filament-panels::page>
    <x-flow.style />
    <style>
        .rvx{display:flex;flex-direction:column;gap:.9rem}
        .rvx-head{display:flex;flex-wrap:wrap;gap:.5rem 1.2rem;align-items:center;justify-content:space-between}
        .rvx-id,.rvx-actions{display:flex;flex-wrap:wrap;gap:.3rem .6rem;align-items:center;font-size:.8rem}
        .rvx-verdict{display:flex;flex-wrap:wrap;gap:.7rem 1rem;align-items:center;padding:1rem 1.2rem;border-radius:.95rem;border:1px solid var(--fl-line);border-left:6px solid var(--fl-mute);background:var(--fl-card);box-shadow:var(--fl-shadow)}
        .rvx-verdict__ic{flex:none;width:2.8rem;height:2.8rem;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;background:var(--fl-soft)}
        .rvx-verdict__main{flex:1 1 16rem;min-width:0}
        .rvx-verdict__main small{font-size:.65rem;text-transform:uppercase;letter-spacing:.08em;font-weight:700;opacity:.6}
        .rvx-verdict p{margin:.1rem 0 0;font-size:1.05rem;font-weight:700;line-height:1.4}
        .rvx-verdict--done{border-left-color:var(--fl-done);background:linear-gradient(90deg,rgba(5,150,105,.09),transparent 45%),var(--fl-card)}.rvx-verdict--done .rvx-verdict__ic{background:rgba(5,150,105,.16);color:var(--fl-done)}
        .rvx-verdict--warn{border-left-color:var(--fl-warn);background:linear-gradient(90deg,rgba(217,119,6,.11),transparent 45%),var(--fl-card)}.rvx-verdict--warn .rvx-verdict__ic{background:rgba(217,119,6,.18);color:var(--fl-warn)}
        .rvx-verdict--failed{border-left-color:var(--fl-fail);background:linear-gradient(90deg,rgba(220,38,38,.10),transparent 45%),var(--fl-card)}.rvx-verdict--failed .rvx-verdict__ic{background:rgba(220,38,38,.16);color:var(--fl-fail)}
        .rvx-verdict--running{border-left-color:var(--fl-run);background:linear-gradient(90deg,rgba(2,132,199,.10),transparent 45%),var(--fl-card)}.rvx-verdict--running .rvx-verdict__ic{background:rgba(2,132,199,.16);color:var(--fl-run)}
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
        .rvx-pane h3{margin:0 0 .5rem;font-size:.95rem;font-weight:700;display:flex;gap:.5rem;align-items:center;flex-wrap:wrap}
        .rvx-step{border-top:1px solid var(--fl-line);padding:.55rem 0;font-size:.8rem}.rvx-step:first-of-type{border-top:0}
        .rvx-step .t{display:flex;flex-wrap:wrap;gap:.4rem;align-items:center}
        .rvx-note{font-size:.75rem;opacity:.75}
        .rvx-wait{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;margin:.4rem 0;padding:.5rem .7rem;border-radius:.6rem;background:rgba(124,58,237,.1);border:1px solid var(--fl-wait)}
        .rvx-table{width:100%;font-size:.8rem;border-collapse:collapse}.rvx-table th,.rvx-table td{padding:.35rem .5rem;border-bottom:1px solid var(--fl-line);text-align:left}.rvx-table td.n,.rvx-table th.n{text-align:right;white-space:nowrap}
        .rvx-pre{font-size:.72rem;white-space:pre-wrap;overflow-wrap:anywhere;background:rgba(128,128,128,.08);border-radius:.5rem;padding:.6rem;max-height:22rem;overflow:auto}
        .rvx details>summary{cursor:pointer;font-weight:600;font-size:.85rem}
    </style>
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

        {{-- Đầu trang gọn: chip trạng thái, định danh, nút chính. Kết luận và chỉ số nằm ở tab Diễn biến, phía trên lưu đồ. --}}
        <div class="rvx-head" data-testid="run-status-bar">
            <div class="rvx-id" data-testid="run-meta">
                <span class="rv-outcome"><x-filament::badge :color="$sColor" size="lg">{{ $sLabel }}</x-filament::badge></span>
                <span class="rv-sub">#{{ $run->id }} · {{ $jobTitle }} · {{ $startedAt ? VnTime::format($startedAt, 'd/m/Y H:i') : '—' }}</span>
                @if ($run->website)<span class="rv-chip">{{ $run->website->name }}</span>@endif
                @if ($campaign)<a class="rv-chip rv-chip--link" href="{{ \App\Filament\Resources\CampaignResource::getUrl('edit', ['record' => $campaign]) }}">Chiến dịch: {{ $campaign->name }}</a>@endif
                <x-help title="Về phiên này">
                    <p>{{ $aiNote }}</p>
                    @if ($run->risk_level)<p>Hạng rủi ro: {{ ['low'=>'thấp','medium'=>'vừa','high'=>'cao'][$run->risk_level] ?? $run->risk_level }}.</p>@endif
                    @if ($campaign)<p>Chế độ chiến dịch: {{ $modeLabels[$campaign->mode] ?? $campaign->mode }}.</p>@endif
                    @if ($run->article?->primary_keyword)<p>Từ khóa: {{ $run->article->primary_keyword }}.</p>@endif
                </x-help>
            </div>
            <div class="rvx-actions">
                @if ($verdict['action'])<x-filament::button tag="a" size="sm" color="gray" :href="$verdict['action']['url']" data-testid="run-head-action">{{ $verdict['action']['label'] }}</x-filament::button>@endif
                <span class="rv-disabled" data-testid="rerun-unwired" role="note">Chạy lại <x-studio.hint tone="warning" text="Chưa nối: hiện chưa có luồng chạy lại một phiên cũ an toàn. Muốn viết lại, giao lại bài cho đội AI từ trang bài viết." /> <em>Chưa nối</em></span>
            </div>
        </div>

        <x-studio.tabs :default="$tab" :tabs="$tabs" label="Các phần của phiên chạy">
            {{-- ===== Diễn biến ===== --}}
            <x-studio.panel key="dien-bien">
                <div class="rvx" x-data="{ sel: @js($focus) }">
                    <div class="rvx-verdict rvx-verdict--{{ $verdict['tone'] }}" data-testid="run-verdict">
                        <span class="rvx-verdict__ic"><x-filament::icon :icon="$verdictIcon" style="width:1.6rem;height:1.6rem" /></span>
                        <div class="rvx-verdict__main"><small>Kết luận</small><p>{{ $verdict['text'] }}</p></div>
                        @if ($verdict['action'])<x-filament::button tag="a" :href="$verdict['action']['url']" data-testid="run-verdict-action">{{ $verdict['action']['label'] }}</x-filament::button>@endif
                    </div>

                    <div class="rvx-kpis" data-testid="run-stats">
                        <div class="rvx-kpi rvx-kpi--{{ $tone }}" data-kpi="duration"><span class="rvx-kpi__ic"><x-filament::icon icon="heroicon-o-clock" style="width:1.3rem;height:1.3rem" /></span><b>{{ \App\Filament\Support\Agent\RunActivity::formatElapsed($flow['seconds']) }}</b><small>Thời lượng</small></div>
                        <div class="rvx-kpi rvx-kpi--{{ $flow['budgetPct'] >= 90 ? 'warn' : 'idle' }}" data-kpi="cost"><span class="rvx-kpi__ic"><x-filament::icon icon="heroicon-o-banknotes" style="width:1.3rem;height:1.3rem" /></span><b>{{ number_format($flow['cost'], 0, ',', '.') }} ₫</b><small>Chi phí · ngân sách {{ number_format($flow['budget'], 0, ',', '.') }} ₫</small></div>
                        <div class="rvx-kpi rvx-kpi--{{ $scoreTone }}" data-kpi="score" data-testid="run-score"><span class="rvx-kpi__ic"><x-filament::icon icon="heroicon-o-chart-bar" style="width:1.3rem;height:1.3rem" /></span><b>@if ($fs !== null){{ $fs }}/100 @else — @endif</b><small>Điểm cuối <x-studio.hint text="Điểm 0–100 suy ra từ kết quả chấm của luật (100 trừ 20 mỗi lỗi P0, 5 mỗi P1, 2 mỗi P2). Chỉ để theo dõi cải thiện: đạt hay không do luật P0 quyết định, không do điểm." /></small></div>
                        <div class="rvx-kpi rvx-kpi--{{ $run->repair_rounds >= 3 ? 'warn' : ($run->repair_rounds > 0 ? 'running' : 'idle') }}" data-kpi="repairs"><span class="rvx-kpi__ic"><x-filament::icon icon="heroicon-o-arrow-path" style="width:1.3rem;height:1.3rem" /></span><b>{{ $run->repair_rounds }}</b><small>Vòng sửa</small></div>
                        <div class="rvx-kpi rvx-kpi--idle" data-kpi="tokens"><span class="rvx-kpi__ic"><x-filament::icon icon="heroicon-o-cpu-chip" style="width:1.3rem;height:1.3rem" /></span><b>{{ number_format($flow['tokens'], 0, ',', '.') }}</b><small>Token @if ($modelList !== []) · {{ implode(', ', array_slice($modelList, 0, 2)) }}@endif</small></div>
                    </div>

                    <div class="rvx-pane rvx-flowpane" data-testid="stage-rail">
                        <div class="fl-wrap">
                            <div class="fl-grid">
                                @foreach ($nodes as $n)
                                    @php
                                        [$stLabel, $stIcon] = RunFlow::STATE[$n['state']];
                                        $next = $nodes[$loop->index + 1] ?? null;
                                        $isLoop = $n['code'] === 'grade' && $next && $next['code'] === 'repair' && $next['count'] > 0;
                                        $link = $n['state'] === 'running' ? 'live' : ($n['state'] === 'done' ? 'done' : (($next && in_array($next['state'], ['idle', 'pending', 'skipped'], true)) ? 'dashed' : null));
                                    @endphp
                                    <x-flow.node :n="$loop->iteration" :label="$n['title']" :desc="$blueprint[$n['code']]['what'] ?? null" :state="$n['state']" :kind="$n['kind']" :human="$n['code'] === 'checkpoint'" :avatar="$n['who']['avatar']" :who="$n['who']['title']"
                                        :state-label="$stLabel" :state-icon="$stIcon" :last="$loop->last" :link="$link" :loop="$isLoop ? $next['count'].' vòng' : null"
                                        data-stage="{{ $n['code'] }}" data-state="{{ $n['state'] }}" :data-live-stage="$n['state'] === 'running' ? $n['code'] : null"
                                        x-on:click="sel = '{{ $n['code'] }}'" x-bind:class="{ 'is-sel': sel === '{{ $n['code'] }}' }">
                                        <span class="fl-state">
                                            @if ($n['state'] === 'running')<x-studio.running size="sm" label="Đang chạy" />
                                            @elseif ($n['state'] === 'waiting')<span class="fl-wait-pill" data-testid="flow-waiting"><x-filament::icon icon="heroicon-m-user" style="width:.9rem;height:.9rem" /> Chờ bạn</span>
                                            @else<span>{{ $stLabel }}@if ($n['count'] > 1) ×{{ $n['count'] }}@endif</span>@endif
                                        </span>
                                        @if ($n['seconds'] !== null || $n['cost'] > 0)<span class="fl-meta">@if ($n['seconds'] !== null)<span>{{ $n['seconds'] }}s</span>@endif @if ($n['cost'] > 0)<span>{{ number_format($n['cost'], 0, ',', '.') }} ₫</span>@endif</span>@endif
                                    </x-flow.node>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    {{-- MỘT khung chi tiết cho chặng đang chọn --}}
                    @foreach ($nodes as $n)
                        @php [$stLabel, $stIcon] = RunFlow::STATE[$n['state']]; @endphp
                        <div class="rvx-pane" data-testid="stage-detail" data-detail="{{ $n['code'] }}" x-show="sel === '{{ $n['code'] }}'" x-cloak>
                            <h3>
                                <x-filament::icon :icon="$stIcon" style="width:1.1rem;height:1.1rem" /> {{ $n['title'] }}
                                <span class="rvx-note">{{ $stLabel }}@if ($n['count'] > 1) ×{{ $n['count'] }}@endif · {{ $n['who']['title'] }}@if ($n['skill']) · {{ $n['skill'] }}@endif</span>
                                @if (isset($blueprint[$n['code']]))<x-help :title="$n['title']"><p>{{ $blueprint[$n['code']]['what'] }}</p></x-help>@endif
                            </h3>
                            @if ($n['waiting'])
                                <div class="rvx-wait"><x-filament::icon icon="heroicon-m-user" style="width:1.1rem;height:1.1rem" /> <strong>Chờ bạn.</strong> Phiên dừng ở chặng này cho đến khi có người xác nhận.@if ($verdict['action'])<x-filament::button tag="a" size="xs" :href="$verdict['action']['url']">{{ $verdict['action']['label'] }}</x-filament::button>@endif</div>
                            @endif
                            @foreach ($n['incidents'] as $i)
                                <div class="rv-incident" data-testid="flow-incident">
                                    <x-filament::icon :icon="$i['kind'] === 'failover' ? 'heroicon-m-arrows-right-left' : 'heroicon-m-arrow-path'" style="width:.9rem;height:.9rem" />
                                    @if ($i['kind'] === 'failover')
                                        <span>Chuyển model dự phòng @if ($i['from'] && $i['to'])({{ $i['from'] }} → {{ $i['to'] }})@endif · đã tự xử lý</span>
                                    @else
                                        <span>Thử lại @if ($i['attempt'])lần {{ $i['attempt'] }}@if ($i['max'])/{{ $i['max'] }}@endif @endif @if ($i['wait'])· chờ {{ $i['wait'] }}s @endif· đã tự xử lý</span>
                                    @endif
                                    @if ($i['error'])<x-studio.hint :text="'Lỗi: '.$i['error']" tone="warning" />@endif
                                </div>
                            @endforeach
                            @forelse ($n['steps'] as $s)
                                @php
                                    $at = $flow['clock'][$s->id] ?? null;
                                    [$short, $isLong] = $tail((string) $s->detail, 260);
                                @endphp
                                <div class="rvx-step" data-step="{{ $s->stage }}">
                                    <div class="t">
                                        <strong>{{ $s->title }}</strong>
                                        <x-filament::badge size="sm" :color="['ok' => 'success', 'warn' => 'warning', 'blocked' => 'gray', 'failed' => 'danger', 'skipped' => 'gray'][$s->status] ?? 'gray'">{{ $stepLabel[$s->status] ?? $s->status }}</x-filament::badge>
                                        <span class="rvx-note">{{ $at ? VnTime::format($at, 'H:i:s') : '—' }}@if (($flow['durations'][$s->id] ?? null) !== null) · +{{ $flow['durations'][$s->id] }}s @endif @if ($s->model)· {{ $s->model }}@endif</span>
                                    </div>
                                    <div>{{ $short }} @if ($isLong)<x-studio.hint :text="$s->detail" />@endif</div>
                                    @if (isset($s->data['score']))<div class="rvx-note">Điểm {{ $s->data['score'] }}/100 @if (isset($s->data['threshold'])) · ngưỡng {{ $s->data['threshold'] }}@endif</div>@endif
                                    @if (! empty($s->data['failed']))
                                        <ul class="rv-fails">
                                            @foreach ($s->data['failed'] as $f)<li><code>{{ $f['code'] ?? '' }}</code> <span class="rv-sev rv-sev--{{ $f['severity'] ?? '' }}">{{ $f['severity'] ?? '' }}</span> {{ $f['message'] ?? '' }}</li>@endforeach
                                        </ul>
                                    @endif
                                    @if (isset($s->data['images']) || isset($s->data['internal_links']))@include('filament.agent.context-assets', ['data' => $s->data])@endif
                                    @if ($s->tokens_used || $s->cost_vnd > 0)<div class="rvx-note">{{ number_format($s->tokens_used) }} token · {{ number_format($s->cost_vnd, 0, ',', '.') }} ₫</div>@endif
                                </div>
                            @empty
                                <p class="rvx-note">{{ $n['state'] === 'pending' ? 'Chưa tới chặng này.' : 'Chặng này không chạy trong phiên.' }}</p>
                            @endforelse
                        </div>
                    @endforeach

                    @if ($rounds !== [])
                        <div class="rv-loop" data-testid="score-timeline">
                            <div class="rv-loop__head">Vòng Chấm ↔ Sửa · {{ $loopCount }} vòng <x-studio.hint text="Mỗi lần chấm là một điểm. Điểm chỉ để theo dõi cải thiện; đạt hay không do luật P0 quyết định. Nếu điểm không tăng, hệ thống dừng sửa và ghi quyết định tự động." /></div>
                            <div class="rv-loop__chain">
                                @foreach ($rounds as $r)
                                    @if (! $loop->first)
                                        <span class="rv-arrow rv-arrow--{{ ($r['delta'] ?? 0) > 0 ? 'up' : (($r['delta'] ?? 0) < 0 ? 'down' : 'flat') }}" aria-label="{{ ($r['delta'] ?? 0) > 0 ? 'tăng' : (($r['delta'] ?? 0) < 0 ? 'giảm' : 'không đổi') }}">
                                            {{ ($r['delta'] ?? 0) > 0 ? '▲ +' : (($r['delta'] ?? 0) < 0 ? '▼ ' : '■ ') }}{{ $r['delta'] }}
                                        </span>
                                    @endif
                                    <span class="rv-round rv-round--{{ $r['score'] >= $r['threshold'] ? 'ok' : 'low' }}" data-round-score="{{ $r['score'] }}">
                                        <small>{{ $r['label'] }}</small><b>{{ $r['score'] }}</b>
                                    </span>
                                @endforeach
                            </div>
                            <div class="rv-small">{{ \App\Services\Agent\Run\RunScorer::format(\App\Services\Agent\Run\RunScorer::timeline($run->steps)) }} · ngưỡng tham khảo {{ $threshold }}/100 @if ($retries->isNotEmpty()) · đã tự thử lại {{ $retries->count() }} lần khi nhà cung cấp AI lỗi tạm thời @endif</div>
                        </div>
                    @endif

                    <details class="rvx-pane" data-testid="auto-decisions" @if ($flow['decisions'] !== []) open @endif>
                        <summary>Quyết định tự động ({{ count($flow['decisions']) }}) <x-help text="Những lần hệ thống tự quyết định thay vì chờ người, kèm dữ kiện trước/sau." /></summary>
                        @forelse ($flow['decisions'] as $d)
                            <div class="rv-decision rv-decision--{{ $d['status'] }}" data-testid="decision">
                                <div class="rv-decision__title"><x-filament::icon icon="heroicon-m-bolt" style="width:1.1rem;height:1.1rem" /> {{ $d['title'] }}</div>
                                <div class="rv-decision__body">{{ $d['detail'] }}</div>
                                @foreach ($d['pairs'] as $p)
                                    <div class="rv-ba"><span>{{ $p['label'] }}</span> <b class="rv-ba__before">{{ $p['before'] }}</b> <i aria-hidden="true">→</i> <b class="rv-ba__after">{{ $p['after'] }}</b> <small>(trước → sau)</small></div>
                                @endforeach
                                @if ($d['facts'])
                                    <div class="rv-facts">@foreach ($d['facts'] as $f)<span><em>{{ $f['label'] }}</em> {{ $f['value'] }}</span>@endforeach</div>
                                @endif
                            </div>
                        @empty
                            <p class="rv-small" style="margin-top:.5rem">Phiên này hệ thống không phải tự quyết định điều gì đặc biệt.</p>
                        @endforelse
                    </details>
                </div>
            </x-studio.panel>

            {{-- ===== Báo cáo ===== --}}
            <x-studio.panel key="bao-cao">
                <div class="rvx">
                    <section class="ss-review-card" data-testid="run-report">
                        <h2 class="ss-review-card__title">{{ $report['title'] }}</h2>
                        <ul style="margin:0 0 .5rem 1rem;list-style:disc">@foreach ($report['lines'] as $l)<li>{{ $l }}</li>@endforeach</ul>
                        @foreach ($report['links'] as $lk)<a class="rv-chip rv-chip--link" href="{{ $lk['url'] }}">{{ $lk['label'] }}</a> @endforeach
                    </section>
                    <section class="rvx-pane" data-testid="run-result">
                        <h3>Kết quả</h3>
                        <div class="rv-result">
                            @if ($run->outcome_reason)<div class="rv-result__reason"><span class="rv-k">Lý do kết thúc</span> {{ $run->outcome_reason }}</div>@endif
                            @if ($run->finalVersion)
                                <div>Bản nháp v{{ $run->finalVersion->version }}: <a class="underline" href="{{ \App\Filament\Resources\ArticleVersionResource::getUrl('view', ['record' => $run->finalVersion]) }}">xem bài</a></div>
                            @elseif ($run->job_type !== 'article_write')
                                <div class="rv-small">Loại việc này không tạo bài.</div>
                            @else
                                <div class="rv-small">Phiên này không tạo bản nháp.</div>
                            @endif
                            @include('filament.agent.cms-draft-line', ['version' => $run->finalVersion])
                            @if ($run->ai_mode !== 'real')
                                <div class="rv-warn"><x-filament::icon icon="heroicon-m-exclamation-triangle" style="width:1rem;height:1rem" /> AI giả lập <x-studio.hint tone="warning" text="Phiên này dùng AI giả lập nên nội dung chỉ để kiểm luồng, không phản ánh chất lượng AI thật." /></div>
                            @endif
                        </div>
                    </section>
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

            {{-- ===== Dữ liệu ===== --}}
            <x-studio.panel key="du-lieu">
                <div class="rvx" data-testid="run-data">
                    <div class="rvx-pane">
                        <h3>Đầu vào và đầu ra <x-help text="Dữ liệu thô của phiên (input_refs, output_ref). Chỉ quản trị xem được." /></h3>
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
        </x-studio.tabs>
    </div>
</x-filament-panels::page>
