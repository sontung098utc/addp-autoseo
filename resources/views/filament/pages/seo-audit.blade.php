<x-filament-panels::page>
    <style>
        .au-mono{font-family:ui-monospace,monospace}
        .au-code{margin:0;padding:.4rem .6rem;background:var(--ss-surface);border:1px solid var(--ss-border);border-radius:.3rem;white-space:pre-wrap;overflow-wrap:anywhere}
        .au-ai{padding:.75rem;border:1px dashed var(--ss-border);border-radius:.5rem}
        .au-ai ol{margin:.5rem 0;padding-left:1.2rem;display:grid;gap:.35rem}
        .au-progress{height:.5rem;border-radius:.3rem;background:var(--ss-border);overflow:hidden;margin-top:.5rem}
        .au-progress>div{height:100%;background:var(--ss-info-fg)}
        .au-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:50;display:flex;justify-content:flex-end}
        .au-drawer{background:var(--ss-surface);color:inherit;width:min(640px,100%);height:100%;overflow:auto;padding:1.25rem;display:grid;gap:.75rem;align-content:start}
        .au-drawer textarea{width:100%;min-height:22rem}
        .au-rules{width:100%;border-collapse:collapse}
        .au-rules th,.au-rules td{text-align:left;vertical-align:top;padding:.4rem .5rem;border-bottom:1px solid var(--ss-border)}
    </style>

    @php
        $hasData = $run && $group;
        $p0 = $hasData ? ($sevStats['P0']['n'] ?? 0) : 0;
        $tabs = [];
        if ($hasData) {
            $tabs[] = ['key' => 'groups', 'label' => 'Nhóm lỗi', 'count' => count($groupRows), 'tone' => $p0 > 0 ? 'danger' : null];
        }
        $tabs[] = ['key' => 'briefs', 'label' => 'Brief đã tạo', 'count' => count($briefRows)];
        $tabs[] = ['key' => 'rules', 'label' => 'Quy tắc'];
        $ctone = ['P0' => 'danger', 'P1' => 'warn', 'P2' => 'muted'];
    @endphp

    <div class="ss-page">
        <div class="ss-toolbar">
            <label class="ss-text-sm ss-muted" for="au-site">Website</label>
            <select id="au-site" wire:model.live="websiteId">
                @foreach ($websites as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach
            </select>
            @if ($site && $state !== 'chua-ket-noi')
                <x-filament::button color="gray" wire:click="rescan" :disabled="! $canRescan">{{ $run ? 'Quét lại' : 'Quét lần đầu' }}</x-filament::button>
                @if ($scanning)
                    <span class="ss-text-sm ss-muted">Đang quét, chờ xong mới quét lại được.</span>
                @elseif (! $guard['ok'])
                    <span class="ss-text-sm ss-muted" title="{{ $guard['reason'] }}">🔒 {{ $guard['reason'] }} {{ $nextAtLabel }}</span>
                @endif
            @endif
            <span style="flex:1"></span>
            @if ($hasData)
                <x-filament::button wire:click="createBrief" :disabled="! $canBrief" title="{{ ! $canAct ? 'Chỉ approver trở lên được tạo brief.' : ($scanning ? 'Đang quét nên chưa tạo được.' : 'Tạo brief từ nhóm lỗi đang chọn') }}">Tạo brief từ lỗi</x-filament::button>
            @endif
        </div>

        @if ($state === 'chua-ket-noi' || ! $site)
            <div class="ss-alert ss-alert--warn" role="status">Website chưa được kết nối. Thêm website vào mục Website kết nối trước khi quét.</div>
        @elseif ($crawlerOff)
            <div class="ss-alert ss-alert--info" role="status">Chưa kết nối crawler nên chưa có lần quét nào.</div>
        @elseif (! $run)
            <div class="ss-alert ss-alert--info" role="status">Chưa quét website này.</div>
        @endif

        @if ($state === 'dang-quet' && $site)
            <div class="ss-alert ss-alert--info" role="status">
                <strong>Đang quét {{ $site->name }}: 26 / 40 URL (65%)</strong>
                <div class="au-progress" role="progressbar" aria-label="Tiến độ quét" aria-valuemin="0" aria-valuemax="40" aria-valuenow="26"><div style="width:65%"></div></div>
            </div>
        @elseif ($state === 'quet-loi' && $site)
            <div class="ss-alert ss-alert--danger" role="alert"><strong>Không quét được {{ $site->name }}:</strong> robots.txt chặn crawler. Bảng dưới là kết quả cũ.</div>
        @endif

        @if ($run)
            @php $s = $run['summary']; @endphp
            <div class="ss-kpis">
                <div class="ss-kpi"><div class="ss-kpi__l">URL đã quét</div><div class="ss-kpi__v">{{ $run['urls_crawled'] }}<span class="ss-muted ss-text-sm"> / {{ $run['urls_total'] }}</span></div><div class="ss-kpi__d ss-muted">{{ $s['urls_with_issue'] }} URL có lỗi</div></div>
                @foreach (['P0' => 'Lỗi P0', 'P1' => 'Lỗi P1', 'P2' => 'Lỗi P2'] as $sv => $lab)
                    <div class="ss-kpi"><div class="ss-kpi__l">{{ $lab }} <x-studio.badge :tone="$ctone[$sv]">{{ $sv }}</x-studio.badge></div><div class="ss-kpi__v">{{ $sevStats[$sv]['n'] }}</div>
                        <div class="ss-kpi__d"><x-studio.delta :value="$sevStats[$sv]['delta']" good="down" :decimals="0" /> <span class="ss-muted">so với lần trước</span></div></div>
                @endforeach
                <div class="ss-kpi"><div class="ss-kpi__l">Quét gần nhất</div><div class="ss-kpi__v">{{ \App\Filament\Support\Schedule\VnTime::format($run['at'], 'H:i d/m') }}</div>
                    <div class="ss-kpi__d ss-muted">giờ VN @if ($stale) <x-studio.badge tone="warn">Kết quả cũ</x-studio.badge> @endif</div></div>
            </div>
        @endif

        <x-studio.tabs persist="audit" :tabs="$tabs">
            @if ($hasData)
                <x-studio.panel key="groups">
                    <div class="ss-split">
                        <x-studio.data-table id="au-groups" :page-size="10" search="Tìm loại lỗi" select-method="selectGroup" :selected-id="$group['type']" :rows="$groupRows" :columns="[
                            ['key' => 'severity', 'label' => 'Mức', 'type' => 'badge', 'filter' => true, 'sortable' => true, 'tones' => ['P0' => 'danger', 'P1' => 'warn', 'P2' => 'muted']],
                            ['key' => 'label', 'label' => 'Loại lỗi', 'type' => 'text', 'sortable' => true, 'filter' => true],
                            ['key' => 'sub', 'label' => 'Mô tả', 'type' => 'text', 'visible' => false],
                            ['key' => 'urls', 'label' => 'URL', 'type' => 'number', 'sortable' => true],
                            ['key' => 'delta', 'label' => 'So với lần trước', 'type' => 'delta', 'good' => 'down', 'decimals' => 0, 'sortable' => true],
                        ]" />
                        <div class="ss-card">
                            <div class="ss-card__h">{{ $group['label'] }} <x-studio.badge :tone="$ctone[$group['severity']] ?? 'muted'">{{ $group['severity'] }}</x-studio.badge> <span class="ss-text-sm ss-muted">{{ $group['urls'] }} URL</span></div>
                            <div class="ss-card__b" style="display:grid;gap:1rem">
                                <x-studio.data-table id="au-evidence" :page-size="5" search="Tìm URL" :rows="$evidenceRows" :columns="[
                                    ['key' => 'url', 'label' => 'URL', 'type' => 'mono', 'sortable' => true],
                                    ['key' => 'measured', 'label' => 'Đo được', 'type' => 'text'],
                                    ['key' => 'evidence', 'label' => 'Bằng chứng', 'type' => 'mono', 'visible' => false],
                                ]" />
                                <div class="au-ai">
                                    <x-studio.badge tone="accent">AI đề xuất</x-studio.badge> <strong>Cách sửa gợi ý</strong>
                                    <ol class="ss-text-sm">@foreach ($fixSteps as $step)<li>{{ $step }}</li>@endforeach</ol>
                                    <div class="ss-text-xs ss-muted">Nguồn: {{ $group['urls'] }} URL trong lần quét {{ $vn($run['at']) }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </x-studio.panel>
            @endif

            <x-studio.panel key="briefs">
                <x-studio.data-table id="au-briefs" :page-size="10" search="Tìm brief" empty="Chưa có brief nào" :rows="$briefRows" :columns="[
                    ['key' => 'title', 'label' => 'Brief', 'type' => 'text', 'sortable' => true],
                    ['key' => 'by', 'label' => 'Người tạo', 'type' => 'text', 'sortable' => true, 'filter' => true],
                    ['key' => 'at', 'label' => 'Thời điểm (giờ VN)', 'type' => 'text'],
                ]" />
            </x-studio.panel>

            <x-studio.panel key="rules">
                <div class="ss-card"><div class="ss-card__b" style="overflow-x:auto">
                    <table class="au-rules ss-text-sm">
                        <thead><tr><th>Luật</th><th>Nguồn dữ liệu</th></tr></thead>
                        <tbody>
                        <tr><td>Chỉ quét website thuộc allowlist (đang active); chặn IP nội bộ và metadata cloud (chống SSRF). Không đủ điều kiện thì nút Quét lại vô hiệu kèm lý do.</td><td class="au-mono">websites.status, websites.base_url</td></tr>
                        <tr><td>Tối đa 1 lần quét mỗi website mỗi 6 giờ (giả định, cần chủ dự án chốt).</td><td class="au-mono">audit_runs.started_at</td></tr>
                        <tr><td>Chưa quét thì ghi "Chưa quét", không hiện số 0. Mỗi lỗi có URL và bằng chứng.</td><td class="au-mono">audit_issues</td></tr>
                        <tr><td>Quét lỗi (robots.txt, chặn bot, timeout) thì nói rõ; kết quả cũ gắn nhãn "cũ".</td><td class="au-mono">audit_runs.status</td></tr>
                        <tr><td>Mức P0/P1/P2 theo loại lỗi (bảng cố định); xu hướng so với lần quét hoàn tất liền trước; số lỗi tăng là xấu.</td><td class="au-mono">IssueCatalog</td></tr>
                        <tr><td>Xem: writer trở lên. Quét lại và Tạo brief: approver trở lên (kiểm ở server).</td><td class="au-mono">users.role</td></tr>
                        <tr><td>"AI đề xuất" chỉ dựa trên URL và bằng chứng của nhóm, cần người kiểm. Brief chỉ tạo việc, không tự sửa website.</td><td class="au-mono">briefs(source=audit)</td></tr>
                        </tbody>
                    </table>
                </div></div>
            </x-studio.panel>
        </x-studio.tabs>
    </div>

    @if ($briefOpen && $brief)
        <div class="au-overlay" wire:key="au-brief-drawer" role="dialog" aria-modal="true" aria-labelledby="au-brief-t" x-data="{ copied: false }" x-on:keydown.escape.window="$wire.closeBrief()">
            <div class="au-drawer">
                <div class="ss-toolbar">
                    <h2 class="ss-card__h" id="au-brief-t" style="border:0;padding:0;flex:1">{{ $brief['title'] }}</h2>
                    <x-filament::button color="gray" size="sm" wire:click="closeBrief">Đóng</x-filament::button>
                </div>
                <textarea x-ref="t" readonly aria-label="Nội dung brief">{{ $brief['body'] }}</textarea>
                <div>
                    <x-filament::button color="gray" x-on:click="navigator.clipboard.writeText($refs.t.value); copied = true; setTimeout(() => copied = false, 2000)">Sao chép</x-filament::button>
                    <span class="ss-text-sm ss-muted" x-show="copied" x-cloak>Đã sao chép</span>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
