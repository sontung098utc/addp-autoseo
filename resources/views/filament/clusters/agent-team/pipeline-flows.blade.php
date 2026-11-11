@php
    $statusText = ['active' => 'Chạy được', 'planned' => 'Chưa chạy'];
    $runStatus = ['running' => 'Đang chạy', 'ready_for_review' => 'Chờ duyệt', 'held' => 'Tạm giữ', 'blocked' => 'Bị chặn', 'failed' => 'Lỗi', 'published' => 'Đã đăng', 'approved' => 'Đã duyệt'];
    $kindName = ['rule' => 'Luật', 'llm' => 'AI', 'human' => 'Người'];
@endphp
<div data-testid="pipeline-flows" class="fl pc-root" x-data="{
        open: null, step: null,
        show(id) { this.open = id; this.step = null; history.replaceState(null, '', '#luong-' + id); document.documentElement.style.overflow = 'hidden'; this.$nextTick(() => { const c = this.$refs['close-' + id]; if (c) c.focus() }) },
        close() { this.open = null; history.replaceState(null, '', location.pathname + location.search); document.documentElement.style.overflow = '' },
        fromHash() { if (location.hash.startsWith('#luong-')) { const id = location.hash.slice(7); if (@js(collect($flows)->pluck('id')->all()).includes(id) && id !== this.open) this.show(id) } },
        init() { this.fromHash() }
    }" x-on:keydown.escape.window="open && close()" x-on:hashchange.window="fromHash()">

    <div class="pc-grid">
        @foreach ($flows as $f)
            @php
$c = $cards[$f['id']] ?? ['running' => 0, 'week' => 0, 'done' => 0, 'failed' => 0, 'rate' => null, 'avg_cost' => null];
@endphp
            <button type="button" class="pc" data-card="{{ $f['id'] }}" data-running="{{ $c['running'] }}" data-week="{{ $c['week'] }}" data-steps="{{ count($f['steps']) }}"
                style="--tone: {{ \App\Services\Agent\Pipeline\PipelineVisual::tone($f['id']) }}" x-on:click="show('{{ $f['id'] }}')" aria-haspopup="dialog">
                <x-flow.illustration :type="$f['id']" class="pc__illu" />
                <span class="pc__body">
                    <span class="pc__title"><span class="pc__name">{{ $f['title'] }}</span>@if ($f['status'] !== 'active')<span class="pv-tag pv-tag--off">{{ $statusText[$f['status']] ?? $f['status'] }}</span>@endif</span>
                    <span class="pc__plain">{{ $f['plain'] }}</span>
                    <span class="pc__stats">
                        <span class="pc__stat @if ($c['running'] > 0) pc__stat--live @endif" data-stat="running">
                            @if ($c['running'] > 0)<span class="pc__pulse" aria-hidden="true"></span><b>{{ $c['running'] }}</b> đang chạy @endif
                        </span>
                        @if ($c['week'] > 0)<span class="pc__stat" data-stat="week"><b>{{ $c['week'] }}</b> phiên 7 ngày</span>@endif
                        @if ($c['rate'] !== null)<span class="pc__stat" data-stat="rate"><b>{{ $c['rate'] }}%</b> xong @if ($c['failed'] > 0)· <b>{{ $c['failed'] }}</b> lỗi @endif</span>@endif
                        <span class="pc__stat" data-stat="steps"><b>{{ count($f['steps']) }}</b> bước</span>
                    </span>
                    @if ($f['has_human'])<span class="fl-chip fl-chip--human pc__human" data-human><x-filament::icon icon="heroicon-m-user" class="fl-ico" /> Có người can thiệp</span>@endif
                </span>
            </button>
        @endforeach
    </div>

    @foreach ($flows as $f)
        @php
            $c = $cards[$f['id']] ?? ['running' => 0, 'week' => 0, 'done' => 0, 'failed' => 0, 'rate' => null, 'avg_cost' => null];
            $tone = \App\Services\Agent\Pipeline\PipelineVisual::tone($f['id']);
            $total = count($f['steps']);
        @endphp
        <div class="pm" x-show="open === '{{ $f['id'] }}'" x-cloak x-transition.opacity.duration.150ms style="--tone: {{ $tone }}" role="dialog" aria-modal="true" aria-labelledby="pm-title-{{ $f['id'] }}">
            <div class="pm__back" x-on:click="close()"></div>
            <section class="pv-flow pm__box" data-flow="{{ $f['id'] }}">
                <header class="pm__head">
                    <div class="pm__titles">
                        <h3 id="pm-title-{{ $f['id'] }}">{{ $f['title'] }}</h3>
                        @if ($f['status'] !== 'active')<span class="pv-tag pv-tag--off">{{ $statusText[$f['status']] ?? $f['status'] }}</span>@endif
                        @if ($c['running'] > 0)<span class="pv-tag pv-tag--live"><span class="pc__pulse"></span>{{ $c['running'] }} đang chạy</span>@endif
                        @if ($f['help'])
                            <x-help title="{{ $f['title'] }}">
                                @foreach ($f['help'] as $line)<p>{{ $line }}</p>@endforeach
                            </x-help>
                        @endif
                    </div>
                    <div class="pm__actions">
                        <x-filament::button size="sm" color="info" tag="a" :href="$f['runs_url']" data-testid="runs-of-{{ $f['id'] }}" :disabled="! $f['runs_url']">Xem phiên đang chạy</x-filament::button>
                        @if ($f['url'])<x-filament::button size="sm" color="gray" tag="a" :href="$f['url']" data-testid="open-work-{{ $f['id'] }}">Mở trang làm việc</x-filament::button>@endif
                        <button type="button" class="pm__x" x-ref="close-{{ $f['id'] }}" x-on:click="close()" aria-label="Đóng"><x-filament::icon icon="heroicon-m-x-mark" style="width:1.25rem;height:1.25rem" /></button>
                    </div>
                </header>

                <div class="pm__scroll">
                    <div class="pm__kpis">
                        @if ($c['week'] > 0)<span><b>{{ $c['week'] }}</b> phiên 7 ngày</span>@endif
                        @if ($c['rate'] !== null)<span><b>{{ $c['rate'] }}%</b> xong, {{ $c['failed'] }} lỗi</span>@endif
                        @if ($c['avg_cost'])<span>Trung bình <b>{{ number_format($c['avg_cost'], 0, ',', '.') }} ₫</b> / phiên</span>@endif
                        @if ($f['budget'])<span>Ngân sách: {{ $f['budget'] }}</span>@endif
                    </div>

                    <div class="fl-wrap" data-testid="flow-map">
                        <div class="fl-grid">
                            @foreach ($f['steps'] as $s)
                                @php
$running = in_array($s['id'], $liveSteps[$f['id']] ?? [], true);
@endphp
                                <x-flow.node :n="$s['n']" :label="$s['info']['short']" :desc="$s['info']['desc']" :kind="$s['human'] ? 'human' : $s['kind']" :human="$s['human']" :planned="$s['planned']" :tier="$s['tier']"
                                    :avatar="$s['profile']['avatar']" :who="$s['profile']['title']" :state="$running ? 'running' : 'def'" :selected="false"
                                    :last="$loop->last" :link="$s['planned'] ? 'dashed' : ($running ? 'live' : null)" :when="$s['when']"
                                    data-step="{{ $s['id'] }}" data-role="{{ $s['profile']['key'] }}" x-bind:class="{ 'is-sel': open === '{{ $f['id'] }}' && step === '{{ $s['id'] }}' }" x-on:click="step = '{{ $s['id'] }}'">
                                    @if ($s['human'])<span class="fl-chip fl-chip--human" data-human-stop>Người quyết định</span>@endif
                                    @if ($s['planned'])<span class="fl-chip">Chưa chạy</span>@elseif ($s['when'])<span class="fl-chip fl-chip--warn" title="{{ $s['when'] }}">Tùy điều kiện</span>@endif
                                    @if ($running)<span class="fl-chip" style="background:var(--fl-run);color:#fff">Đang chạy</span>@endif
                                </x-flow.node>
                            @endforeach
                        </div>
                    </div>
                    <div class="pv-legend"><span><x-filament::icon icon="heroicon-m-cog-6-tooth" class="fl-ico" /> Luật</span><span><x-filament::icon icon="heroicon-m-sparkles" class="fl-ico" /> AI</span><span><x-filament::icon icon="heroicon-m-user" class="fl-ico" /> Người can thiệp</span><span>Chấm tròn trên đường nối: tùy điều kiện</span><span>Nét đứt mờ: chưa chạy</span></div>

                    {{-- MỘT khung chi tiết cho bước đang chọn --}}
                    <div class="pm__detail" data-testid="step-frame">
                        @foreach ($f['steps'] as $s)
                            @php
$stat = $stepStats[$f['id']][$s['id']] ?? null;
@endphp
                            <div data-detail-step="{{ $s['id'] }}" x-show="step === '{{ $s['id'] }}' && open === '{{ $f['id'] }}'" x-cloak>
                                <div class="pm__dhead">
                                    <span class="fl-num">{{ $s['n'] }}</span><strong>{{ $s['info']['short'] }}</strong>
                                    <span class="fl-chip">{{ $kindName[$s['human'] ? 'human' : $s['kind']] }}@if ($s['kind'] === 'llm' && $s['tier']) · {{ $s['tier'] }}@endif</span>
                                    @if ($s['human'])<span class="fl-chip fl-chip--human">Điểm dừng của người</span>@endif
                                </div>
                                <dl class="pm__dl">
                                    <div><dt>Vì sao có bước này</dt><dd>{{ $s['info']['why'] }}</dd></div>
                                    <div><dt>Luật / tiêu chí áp dụng</dt><dd>{{ $s['info']['rule'] ?: 'Không có luật riêng; chạy theo thứ tự của luồng.' }}</dd></div>
                                    <div><dt>Dữ liệu vào</dt><dd>{{ $s['from'] ? 'Kết quả của bước «'.$s['from'].'»' : (implode('; ', array_slice($f['inputs'], 0, 4)) ?: 'Chưa khai') }}</dd></div>
                                    <div><dt>Dữ liệu ra</dt><dd>{{ $s['to'] ? 'Chuyển cho bước «'.$s['to'].'»' : (implode('; ', array_slice($f['outputs'], 0, 3)) ?: 'Chưa khai') }}</dd></div>
                                    <div><dt>Điều kiện</dt><dd>{{ $s['when'] ?: 'Luôn chạy.' }}</dd></div>
                                    <div><dt>Nhân sự phụ trách</dt><dd><span class="fl-av">{{ $s['profile']['avatar'] }}</span> {{ $s['profile']['name'] }}, {{ $s['profile']['title'] }}
                                        @if ($rolesUrl)<a href="{{ $rolesUrl }}" class="pm__lnk">Vai trò</a>@endif @if ($skillsUrl)<a href="{{ $skillsUrl }}" class="pm__lnk">Kỹ năng</a>@endif</dd></div>
                                    @if ($stat)<div><dt>Số liệu 30 ngày</dt><dd>{{ $stat['runs'] }} lần chạy · {{ $stat['ok'] }} đạt, {{ $stat['warn'] }} cảnh báo, {{ $stat['failed'] }} lỗi · trung bình {{ number_format($stat['avg_cost'], 0, ',', '.') }} ₫</dd></div>@endif
                                </dl>
                                @if ($isAdmin)
                                    <details class="pm__tech" data-testid="step-tech"><summary>Chi tiết kỹ thuật (quản trị)</summary>
                                        <div>Mã bước: <code>{{ $s['id'] }}</code> · Loại: {{ $s['kind'] }} · Vai trò: <code>{{ $s['profile']['key'] }}</code>@if ($s['kind'] === 'llm') · Mức model: {{ $s['tier'] ?? 'chưa định' }}@endif</div>
                                        <div>Nhãn gốc: {{ $s['label'] }}</div>
                                    </details>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <div class="fl-ends">
                        <x-flow.end label="Đầu vào của cả luồng" side="in" :items="$f['inputs']" :links="$f['input_links']" data-end="input" />
                        <x-flow.end label="Kết quả của cả luồng" side="out" :items="$f['outputs']" :links="$f['output_links']" data-end="output" />
                    </div>

                    @if (! empty($recent[$f['id']] ?? []))
                    <div class="pm__recent" data-testid="recent-runs">
                        <h4>Phiên gần đây của luồng này</h4>
                        @foreach ($recent[$f['id']] as $r)
                            <a class="pm__run" @if ($r['url']) href="{{ $r['url'] }}" @endif data-recent-run="{{ $r['id'] }}">
                                <span class="pm__runid">#{{ $r['id'] }}</span><span class="pm__runsub">{{ $r['subject'] }}</span>
                                <span class="pv-tag @if (in_array($r['status'], ['failed', 'blocked'], true)) pv-tag--bad @elseif ($r['status'] === 'running') pv-tag--live @endif">{{ $runStatus[$r['status']] ?? $r['status'] }}</span>
                                <span class="pm__runwhen">{{ $r['when'] }}</span>
                            </a>
                        @endforeach
                    </div>
                    @endif
                </div>
            </section>
        </div>
    @endforeach
</div>
