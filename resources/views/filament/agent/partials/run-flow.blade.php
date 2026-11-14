{{-- Phần DIỄN BIẾN của một phiên: chỉ số, lưu đồ các bước (bấm một bước để mở ngăn chi tiết đầu vào/đầu ra), vòng chấm-sửa, quyết định tự động. Dùng trong tab Diễn biến của phiên và trong thẻ Luồng chạy ở Tổng quan của trang bài. Cần các biến chuẩn bị ở đầu partials/run-detail (chạy bằng @include từ đó). --}}
@php
    use App\Filament\Support\Agent\RunFlow;
    use App\Filament\Support\Schedule\VnTime;
@endphp
                <div class="rvx" x-data="{ sel: @js((empty($flowOnly) && $verdict) ? $focus : null) }" x-on:keydown.escape.window="sel = null">
                    <div class="rvx-kpis" data-testid="run-stats">
                        <div class="rvx-kpi rvx-kpi--{{ $tone }}" data-kpi="duration"><span class="rvx-kpi__ic"><x-filament::icon icon="heroicon-o-clock" style="width:1.3rem;height:1.3rem" /></span><b>{{ \App\Filament\Support\Agent\RunActivity::formatElapsed($flow['seconds']) }}</b><small>Thời lượng</small></div>
                        <div class="rvx-kpi rvx-kpi--{{ $flow['budgetPct'] >= 90 ? 'warn' : 'idle' }}" data-kpi="cost"><span class="rvx-kpi__ic"><x-filament::icon icon="heroicon-o-banknotes" style="width:1.3rem;height:1.3rem" /></span><b>{{ number_format($flow['cost'], 0, ',', '.') }} ₫</b><small>Chi phí · ngân sách {{ number_format($flow['budget'], 0, ',', '.') }} ₫</small></div>
                        <div class="rvx-kpi rvx-kpi--{{ $scoreTone }}" data-kpi="score" data-testid="run-score"><span class="rvx-kpi__ic"><x-filament::icon icon="heroicon-o-chart-bar" style="width:1.3rem;height:1.3rem" /></span><b>@if ($fs !== null){{ $fs }}/100 @else — @endif</b><small>Điểm cuối <x-studio.hint text="Điểm 0–100 suy ra từ kết quả chấm của luật (100 trừ 20 mỗi lỗi P0, 5 mỗi P1, 2 mỗi P2). Chỉ để theo dõi cải thiện: đạt hay không do luật P0 quyết định, không do điểm." /></small></div>
                        <div class="rvx-kpi rvx-kpi--{{ $run->repair_rounds >= 3 ? 'warn' : ($run->repair_rounds > 0 ? 'running' : 'idle') }}" data-kpi="repairs"><span class="rvx-kpi__ic"><x-filament::icon icon="heroicon-o-arrow-path" style="width:1.3rem;height:1.3rem" /></span><b>{{ $run->repair_rounds }}</b><small>Vòng sửa</small></div>
                        <div class="rvx-kpi rvx-kpi--idle" data-kpi="tokens"><span class="rvx-kpi__ic"><x-filament::icon icon="heroicon-o-cpu-chip" style="width:1.3rem;height:1.3rem" /></span><b>{{ number_format($flow['tokens'], 0, ',', '.') }}</b><small>Token @if ($modelList !== []) · {{ implode(', ', array_slice($modelList, 0, 2)) }}@endif</small></div>
                    </div>

                    <div class="rvx-pane rvx-flowpane" data-testid="stage-rail">
                        @php $graph = \App\Filament\Support\Agent\FlowGraph::trace($flow, $run->steps, (bool) ($live ?? false), $currentStage ?? null); @endphp
                        @if ($graph)
                            <x-flow.trace :t="$graph" />
                        @else
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
                                        :state-label="$stLabel" :state-icon="$stIcon" :parallel="$n['parallel'] ?? null" :last="$loop->last" :link="$link" :loop="$isLoop ? $next['count'].' vòng' : null"
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
                        @endif
                    </div>

                    {{-- MỘT khung chi tiết cho chặng đang chọn --}}
                    <div class="rvx-scrim" x-show="sel !== null" x-cloak x-transition.opacity.duration.150ms x-on:click="sel = null" aria-hidden="true" data-testid="stage-detail-scrim"></div>
                    @foreach ($nodes as $n)
                        @php [$stLabel, $stIcon] = RunFlow::STATE[$n['state']]; @endphp
                        <div class="rvx-pane rvx-drawer" role="dialog" aria-label="Chi tiết bước: {{ $n['title'] }}" data-testid="stage-detail" data-detail="{{ $n['code'] }}" x-show="sel === '{{ $n['code'] }}'" x-cloak x-transition.opacity.duration.120ms>
                            <button type="button" class="rvx-drawer__x" x-on:click="sel = null" aria-label="Đóng chi tiết bước" data-testid="stage-detail-close"><x-filament::icon icon="heroicon-m-x-mark" style="width:1.1rem;height:1.1rem" /></button>
                            <h3>
                                <x-filament::icon :icon="$stIcon" style="width:1.1rem;height:1.1rem" /> {{ $n['title'] }}
                                <span class="rvx-note">{{ $stLabel }}@if ($n['count'] > 1) ×{{ $n['count'] }}@endif · {{ $n['who']['title'] }}@if ($n['skill']) · {{ $n['skill'] }}@endif</span>
                                @if (isset($blueprint[$n['code']]))<x-help :title="$n['title']"><p>{{ $blueprint[$n['code']]['what'] }}</p></x-help>@endif
                            </h3>
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
                                    @if (is_array($s->data['failed'] ?? null) && $s->data['failed'] !== []) {{-- 'failed' của bước chấm là DANH SÁCH luật lỗi; bước khác có thể dùng khóa này cho một con số --}}
                                        <ul class="rv-fails">
                                            @foreach ($s->data['failed'] as $f)<li><code>{{ $f['code'] ?? '' }}</code> <span class="rv-sev rv-sev--{{ $f['severity'] ?? '' }}">{{ $f['severity'] ?? '' }}</span> {{ $f['message'] ?? '' }}</li>@endforeach
                                        </ul>
                                    @endif
                                    @include('filament.agent.step-io', ['s' => $s, 'run' => $run])
                                    @if (isset($s->data['images']) || isset($s->data['internal_links']))@include('filament.agent.context-assets', ['data' => $s->data])@endif
                                    @if ($s->tokens_used || $s->cost_vnd > 0)<div class="rvx-note">{{ number_format($s->tokens_used) }} token{{ $s->prompt_tokens !== null && $s->completion_tokens !== null ? ' (vào '.number_format($s->prompt_tokens).' · ra '.number_format($s->completion_tokens).')' : '' }} · {{ number_format($s->cost_vnd, 0, ',', '.') }} ₫</div>@endif
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
