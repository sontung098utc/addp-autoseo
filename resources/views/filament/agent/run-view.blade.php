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
@endphp
<x-filament-panels::page>
    <div class="rv" data-testid="agent-run" @if ($live) wire:poll.{{ $pollSeconds }}s @endif>
        @if ($live)
            <div class="ss-live-pill rv-live" data-testid="run-live">
                <x-studio.running label="Đang chạy" />
                <span>· Đang ở: <strong>{{ $blueprint[$currentStage]['title'] ?? 'hoàn tất, đang đóng phiên' }}</strong> · đã chạy {{ $elapsed }}</span>
            </div>
        @elseif ($stale)
            <div data-testid="run-stale" class="rv-stale">
                <x-filament::icon icon="heroicon-m-clock" style="width:1.1rem;height:1.1rem" />
                <span>Có thể bị treo <x-studio.hint tone="warning" :text="'Phiên vẫn ghi là đang chạy nhưng quá '.\App\Filament\Support\Agent\RunActivity::STALE_MINUTES.' phút không có bước mới. Đã ngừng làm mới tự động; tải lại trang để kiểm tra.'" /></span>
            </div>
        @endif

        {{-- Dòng phụ: số phiên, giờ bắt đầu và các chip ngữ cảnh. Tên bài là tiêu đề trang phía trên. --}}
        <div class="rv-meta" data-testid="run-meta">
            <span class="rv-sub">#{{ $run->id }} · bắt đầu lúc {{ $startedAt ? VnTime::format($startedAt, 'd/m/Y H:i') : '—' }} (giờ VN)</span>
            @if ($run->website)<span class="rv-chip">{{ $run->website->name }}</span>@endif
            @if ($campaign)
                <a class="rv-chip rv-chip--link" href="{{ \App\Filament\Resources\CampaignResource::getUrl('edit', ['record' => $campaign]) }}">Chiến dịch: {{ $campaign->name }}</a>
                <span class="rv-chip">Chế độ {{ $modeLabels[$campaign->mode] ?? $campaign->mode }}</span>
            @endif
            @if ($run->article?->primary_keyword)<span class="rv-chip">Từ khóa: {{ $run->article->primary_keyword }}</span>@endif
        </div>

        {{-- Thanh trạng thái --}}
        <div class="rv-status" data-testid="run-status-bar">
            <div class="rv-tile rv-tile--lead rv-tone-{{ $tone }}">
                <div class="rv-k">Kết quả</div>
                <div class="rv-outcome"><x-filament::badge :color="$sColor" size="lg">{{ $sLabel }}</x-filament::badge></div>
                <div class="rv-chips">
                    <x-filament::badge :color="$run->ai_mode === 'real' ? 'success' : 'gray'">{{ $run->ai_mode === 'real' ? 'AI thật' : 'AI giả lập' }}</x-filament::badge>
                    <x-filament::badge color="gray">Bậc tin cậy {{ $run->trust_tier }}</x-filament::badge>
                    @if ($run->risk_level)<x-filament::badge :color="['low'=>'success','medium'=>'warning','high'=>'danger'][$run->risk_level] ?? 'gray'">Rủi ro {{ ['low'=>'thấp','medium'=>'vừa','high'=>'cao'][$run->risk_level] ?? $run->risk_level }}</x-filament::badge>@endif
                    <x-studio.hint text="Bậc T1: chỉ tạo bản nháp chờ người duyệt, không bao giờ tự xuất bản." />
                </div>
            </div>
            <div class="rv-tile rv-tone-{{ $scoreTone }}" data-testid="run-score">
                <div class="rv-k">Điểm cuối <x-studio.hint text="Điểm 0–100 suy ra từ kết quả chấm của luật (100 trừ 20 mỗi lỗi P0, 5 mỗi P1, 2 mỗi P2). Chỉ để theo dõi cải thiện: đạt hay không do luật P0 quyết định, không do điểm." /></div>
                @if ($fs !== null)
                    <div class="rv-big">{{ $fs }}<span>/100</span></div>
                    <div class="rv-small">Ngưỡng tham khảo {{ $threshold }}</div>
                @else
                    <div class="rv-big rv-big--na">—</div><div class="rv-small">Chưa có lần chấm</div>
                @endif
            </div>
            <div class="rv-tile"><div class="rv-k">Vòng sửa</div><div class="rv-big">{{ $run->repair_rounds }}</div><div class="rv-small">tối đa 3 vòng</div></div>
            <div class="rv-tile"><div class="rv-k">Tổng thời gian</div><div class="rv-mid">{{ \App\Filament\Support\Agent\RunActivity::formatElapsed($flow['seconds']) }}</div>@if ($live)<div class="rv-small">đang chạy</div>@endif</div>
            <div class="rv-tile"><div class="rv-k">Token</div><div class="rv-mid">{{ number_format($flow['tokens'], 0, ',', '.') }}</div></div>
            <div class="rv-tile rv-tile--wide" data-testid="run-budget">
                <div class="rv-k">Chi phí / ngân sách phiên <x-studio.hint text="Ngân sách phiên hiện là giá trị mặc định trong RunPolicy; chưa nối cài đặt ngân sách thật theo ngày/tháng (AG-B09)." tone="warning" /></div>
                <div class="rv-mid">{{ number_format($flow['cost'], 0, ',', '.') }} ₫ <span class="rv-small">/ {{ number_format($flow['budget'], 0, ',', '.') }} ₫</span></div>
                <div class="rv-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $flow['budgetPct'] }}"><i style="width:{{ $flow['budgetPct'] }}%" class="{{ $flow['budgetPct'] >= 90 ? 'is-hot' : '' }}"></i></div>
            </div>
        </div>

        <div class="rv-actions">
            @if ($run->finalVersion)
                <x-filament::button tag="a" size="sm" icon="heroicon-o-document-text" :href="\App\Filament\Resources\ArticleVersionResource::getUrl('view', ['record' => $run->finalVersion])">Xem bản nháp v{{ $run->finalVersion->version }}</x-filament::button>
            @endif
            <span class="rv-disabled" data-testid="rerun-unwired" role="note">Chạy lại <x-studio.hint tone="warning" text="Chưa nối: hiện chưa có luồng chạy lại một phiên cũ an toàn. Muốn viết lại, giao lại bài cho đội AI từ trang bài viết." /> <em>Chưa nối</em></span>
        </div>

        {{-- Luồng chạy --}}
        <x-filament::section heading="Luồng đã đi qua">
            <x-slot name="description">Mỗi ô là một chặng; màu và biểu tượng cho biết chặng đó thực tế đã diễn ra thế nào trong phiên này.</x-slot>
            <div class="rv-flow" data-testid="stage-rail">
                @foreach ($nodes as $n)
                    @php [$stLabel, $stIcon] = RunFlow::STATE[$n['state']]; @endphp
                    <div class="rv-node rv-node--{{ $n['state'] }} @if ($n['state'] === 'running') ss-stage-live @elseif ($n['state'] === 'stale') ss-stage-stale @endif"
                         data-stage="{{ $n['code'] }}" data-state="{{ $n['state'] }}" @if ($n['state'] === 'running') data-live-stage="{{ $n['code'] }}" @endif>
                        <div class="rv-node__top">
                            <span class="rv-node__no">{{ $loop->iteration }}</span>
                            <span class="rv-node__title">{{ $n['title'] }}</span>
                        </div>
                        <div class="rv-node__state">
                            @if ($n['state'] === 'running')<x-studio.running size="sm" label="Đang chạy" />@else<x-filament::icon :icon="$stIcon" style="width:1rem;height:1rem" /><span>{{ $stLabel }}@if ($n['count'] > 1) ×{{ $n['count'] }}@endif</span>@endif
                        </div>
                        <div class="rv-node__line"><span>Vai trò</span> {{ $n['role'] }}</div>
                        @if ($n['skill'])<div class="rv-node__line" title="{{ $n['skill'] }}"><span>Kỹ năng</span> {{ $n['skill'] }}</div>@endif
                        @if ($n['models'])<div class="rv-node__line"><span>Model</span> {{ implode(', ', $n['models']) }}</div>@endif
                        @if ($n['seconds'] !== null)<div class="rv-node__line"><span>Thời lượng</span> ≈ {{ $fmtSec($n['seconds']) }}</div>@endif
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
                        @if ($n['state'] === 'idle')<div class="rv-node__note">không chạy</div>@endif
                    </div>
                @endforeach
            </div>

            @if ($rounds !== [])
                <div class="rv-loop" data-testid="score-timeline">
                    <div class="rv-loop__head">Vòng Chấm ↔ Sửa <x-studio.hint text="Mỗi lần chấm là một điểm. Điểm chỉ để theo dõi cải thiện; đạt hay không do luật P0 quyết định. Nếu điểm không tăng, hệ thống dừng sửa và ghi quyết định tự động." /></div>
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
        </x-filament::section>

        {{-- Quyết định tự động --}}
        <x-filament::section heading="Quyết định tự động" data-testid="auto-decisions">
            <x-slot name="description">Những lần hệ thống tự quyết định thay vì chờ người, kèm dữ kiện trước/sau.</x-slot>
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
                <p class="rv-small">Phiên này hệ thống không phải tự quyết định điều gì đặc biệt.</p>
            @endforelse
        </x-filament::section>

        {{-- Kết quả --}}
        <x-filament::section heading="Kết quả" data-testid="run-result">
            <div class="rv-result">
                @if ($run->outcome_reason)<div class="rv-result__reason"><span class="rv-k">Lý do kết thúc</span> {{ $run->outcome_reason }}</div>@endif
                @if ($run->finalVersion)
                    <div>Bản nháp v{{ $run->finalVersion->version }}: <a class="underline" href="{{ \App\Filament\Resources\ArticleVersionResource::getUrl('view', ['record' => $run->finalVersion]) }}">xem bài</a></div>
                @else
                    <div class="rv-small">Phiên này không tạo bản nháp.</div>
                @endif
                @include('filament.agent.cms-draft-line', ['version' => $run->finalVersion])
                @if ($run->ai_mode !== 'real')
                    <div class="rv-warn"><x-filament::icon icon="heroicon-m-exclamation-triangle" style="width:1rem;height:1rem" /> AI giả lập <x-studio.hint tone="warning" text="Phiên này dùng AI giả lập nên nội dung chỉ để kiểm luồng, không phản ánh chất lượng AI thật." /></div>
                @endif
            </div>
        </x-filament::section>

        {{-- Dòng thời gian --}}
        <x-filament::section heading="Nhật ký từng bước">
            <x-slot name="description">Mỗi bước ghi rõ giờ, người làm và lý do hệ thống làm như vậy.</x-slot>
            <ol class="rv-tl">
                @foreach ($run->steps as $s)
                    @php
                        $st = $stepState[$s->status] ?? 'idle';
                        $at = $flow['clock'][$s->id] ?? null;
                        [$short, $isLong] = $tail((string) $s->detail);
                        $isNew = $live && $loop->last;
                    @endphp
                    <li class="rv-tl__item rv-tl__item--{{ $st }} @if ($isNew) ss-step-new @endif" data-step="{{ $s->stage }}">
                        <div class="rv-tl__time">{{ $at ? VnTime::format($at, 'H:i:s') : '—' }}<small>#{{ $s->seq }}@if (($flow['durations'][$s->id] ?? null) !== null) · +{{ $flow['durations'][$s->id] }}s @endif</small></div>
                        <div class="rv-tl__dot" aria-hidden="true"></div>
                        <div class="rv-tl__main">
                            <div class="rv-tl__head">
                                <strong>{{ $s->title }}</strong>
                                <x-filament::badge size="sm" :color="['ok' => 'success', 'warn' => 'warning', 'blocked' => 'gray', 'failed' => 'danger', 'skipped' => 'gray'][$s->status] ?? 'gray'">{{ $stepLabel[$s->status] ?? $s->status }}</x-filament::badge>
                            </div>
                            <div class="rv-tl__who" data-testid="step-role-skill">Vai trò: {{ $s->data['role_name'] ?? ($roles[$s->role] ?? $s->role) }} · Kỹ năng: {{ $s->data['skill_label'] ?? 'Chỉ dẫn mặc định' }}@if (! empty($s->data['skill'])) · {{ $s->data['skill']['status'] }} · {{ $s->data['skill']['checksum'] }}@endif @if (! empty($s->data['skill_note'])) · {{ $s->data['skill_note'] }}@endif @if ($s->model)· Model: {{ $s->model }}@endif</div>
                            <div class="rv-tl__detail">{{ $short }} @if ($isLong)<x-studio.hint :text="$s->detail" />@endif</div>
                            @if (isset($s->data['score']))<div class="rv-small">Điểm {{ $s->data['score'] }}/100 @if (isset($s->data['threshold'])) · ngưỡng {{ $s->data['threshold'] }}@endif</div>@endif
                            @if (! empty($s->data['failed']))
                                <ul class="rv-fails">
                                    @foreach ($s->data['failed'] as $f)<li><code>{{ $f['code'] ?? '' }}</code> <span class="rv-sev rv-sev--{{ $f['severity'] ?? '' }}">{{ $f['severity'] ?? '' }}</span> {{ $f['message'] ?? '' }}</li>@endforeach
                                </ul>
                            @endif
                            @if ($s->tokens_used || $s->cost_vnd > 0)<div class="rv-small">{{ number_format($s->tokens_used) }} token · {{ number_format($s->cost_vnd, 0, ',', '.') }} ₫</div>@endif
                        </div>
                    </li>
                @endforeach
            </ol>
        </x-filament::section>

        <x-filament::section heading="Chặng nào đã thật, chặng nào cần Dev1" collapsible collapsed>
            <x-slot name="description">Bản thiết kế sống: cùng một nguồn với trang Cách hoạt động.</x-slot>
            @foreach ($blueprint as $b)
                <div class="rv-bp">
                    <x-studio.chip domain="readiness" :value="$b['readiness']" /> <strong class="text-sm">{{ $b['title'] }}</strong>
                    <div class="text-xs" style="opacity:.8">{{ $b['what'] }}</div>
                    <div class="text-xs" style="opacity:.8"><strong>Đã có:</strong> {{ $b['built'] }}</div>
                    <div class="text-xs" style="color:#b45309"><strong>Dev1 cần ({{ $b['task'] }}):</strong> {{ $b['dev1'] }}</div>
                </div>
            @endforeach
        </x-filament::section>
    </div>
</x-filament-panels::page>
