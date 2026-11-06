<x-filament-panels::page>
    <style>
        .au-card{border:1px solid rgba(128,128,128,.3);border-radius:.75rem;padding:1rem;margin-bottom:1rem}
        .au-head{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;justify-content:space-between;margin-bottom:.5rem}
        .au-h{font-weight:600;font-size:1rem;margin:0}
        .au-muted{opacity:.75;font-size:.8rem}
        .au-run{display:flex;flex-wrap:wrap;gap:1rem;justify-content:space-between}
        .au-kv{display:grid;grid-template-columns:max-content 1fr;gap:.25rem 1rem;font-size:.85rem;margin:0}
        .au-kv dt{opacity:.7}.au-kv dd{margin:0}
        .au-mono{font-family:ui-monospace,monospace;font-size:.8rem}
        .au-main{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.1fr);gap:1rem;align-items:start}
        @media (max-width:1100px){.au-main{grid-template-columns:minmax(0,1fr)}}
        .au-tbl{width:100%;border-collapse:collapse;font-size:.85rem}
        .au-tbl th,.au-tbl td{text-align:left;padding:.45rem .5rem;border-bottom:1px solid rgba(128,128,128,.25);vertical-align:top}
        .au-tbl .num{text-align:right;white-space:nowrap}
        .au-g.is-selected td{background:rgba(var(--primary-500),.12)}
        .au-pick{all:unset;cursor:pointer;display:block;min-height:2rem;border-radius:.25rem}
        .au-pick:focus-visible{outline:2px solid rgb(var(--primary-600));outline-offset:2px}
        .au-sev{display:inline-block;min-width:2rem;text-align:center;border-radius:.4rem;padding:.1rem .4rem;font-weight:600;font-size:.75rem;border:1px solid rgba(128,128,128,.5)}
        .au-sev-P0{background:rgba(var(--danger-500),.15);color:rgb(var(--danger-600));border-color:rgb(var(--danger-600))}
        .au-sev-P1{background:rgba(var(--warning-500),.15);color:rgb(var(--warning-600));border-color:rgb(var(--warning-600))}
        .au-tr{font-family:ui-monospace,monospace;font-size:.75rem;white-space:nowrap}
        .au-tr--better{color:rgb(var(--success-600))}.au-tr--worse{color:rgb(var(--danger-600))}
        .au-ev{list-style:none;margin:0;padding:0}
        .au-ev>li{padding:.6rem 0;border-bottom:1px solid rgba(128,128,128,.25);display:grid;gap:.25rem}
        .au-url{font-family:ui-monospace,monospace;font-size:.8rem;font-weight:600;overflow-wrap:anywhere}
        .au-code{margin:0;padding:.4rem .6rem;background:rgba(128,128,128,.1);border:1px solid rgba(128,128,128,.25);border-radius:.3rem;font-family:ui-monospace,monospace;font-size:.75rem;white-space:pre-wrap;overflow-wrap:anywhere}
        .au-ai{margin-top:1rem;padding:.8rem;border:1px dashed rgba(128,128,128,.5);border-radius:.5rem;background:rgba(128,128,128,.06)}
        .au-ai ol{margin:.5rem 0;padding-left:1.2rem;font-size:.85rem;display:grid;gap:.4rem}
        .au-badge{display:inline-block;border:1px solid rgba(128,128,128,.6);border-radius:.4rem;padding:.05rem .45rem;font-size:.72rem;font-weight:600}
        .au-banner{border:1px solid rgba(128,128,128,.5);border-left-width:4px;border-radius:.5rem;padding:.7rem .9rem;margin-bottom:1rem;font-size:.85rem}
        .au-banner--danger{border-color:rgb(var(--danger-600))}.au-banner--warn{border-color:rgb(var(--warning-600))}
        .au-progress{height:.6rem;border-radius:.3rem;background:rgba(128,128,128,.25);overflow:hidden;margin-top:.5rem}
        .au-progress>div{height:100%;background:rgb(var(--primary-600))}
        .au-lock{font-size:.78rem;opacity:.85;margin:.4rem 0 0}
        .au-pager{display:flex;gap:.5rem;align-items:center;margin-top:.6rem;font-size:.8rem}
        .au-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:50;display:flex;justify-content:flex-end}
        .au-drawer{background:var(--au-bg,#fff);color:inherit;width:min(640px,100%);height:100%;overflow:auto;padding:1.25rem;display:grid;gap:.75rem;align-content:start}
        .dark .au-drawer{--au-bg:#18181b}
        .au-drawer textarea{width:100%;min-height:22rem;font-family:ui-monospace,monospace;font-size:.78rem;border:1px solid rgba(128,128,128,.4);border-radius:.4rem;padding:.6rem;background:transparent;color:inherit}
        .au-rules summary{cursor:pointer;font-weight:600;min-height:2rem;display:flex;align-items:center}
        .au-rules table{width:100%;min-width:680px;border-collapse:collapse;font-size:.8rem;margin-top:.6rem}
        .au-rules th,.au-rules td{text-align:left;vertical-align:top;padding:.4rem .5rem;border-bottom:1px solid rgba(128,128,128,.25)}
    </style>

    <x-demo-banner source="crawler (task 1.1)" real="phân tích on-page, luật allowlist/tần suất, tạo brief" />

    <p class="au-muted" style="margin:0 0 1rem">Lỗi SEO on-page{{ $site ? ' của '.$site->name : '' }}, mỗi lỗi kèm URL và bằng chứng đo được. Chọn một nhóm lỗi để xem URL ảnh hưởng và biến nó thành brief.</p>

    {{-- Lần quét gần nhất --}}
    <section class="au-card" aria-labelledby="au-run-h">
        <div class="au-head">
            <h2 class="au-h" id="au-run-h">Lần quét gần nhất</h2>
            <div>
                <label for="au-site" class="au-muted">Website</label>
                <select id="au-site" wire:model.live="websiteId" class="fi-select-input" style="min-width:12rem;padding:.3rem .5rem;border-radius:.4rem;border:1px solid rgba(128,128,128,.4);background:transparent">
                    @foreach ($websites as $w)
                        <option value="{{ $w->id }}">{{ $w->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        @if ($state === 'chua-ket-noi' || ! $site)
            <p style="margin:.5rem 0"><strong>Website chưa được kết nối</strong></p>
            <p class="au-muted">Cần thêm website và domain vào mục Website kết nối trước khi quét. Domain phải nằm trong danh sách được phép của công ty.</p>
        @elseif ($crawlerOff)
            <p style="margin:.5rem 0"><strong>Chưa kết nối crawler</strong></p>
            <p class="au-muted">Crawler (task 1.1) chưa được triển khai nên chưa có lần quét nào. Màn này không hiện số liệu khi chưa có dữ liệu thật.</p>
        @elseif ($run)
            <div class="au-run">
                <dl class="au-kv">
                    <dt>Dữ liệu từ</dt><dd>Lần quét lúc {{ $vn($run['at']) }} <span class="au-muted">(giờ VN, lưu <span class="au-mono">{{ $utc($run['at']) }}</span>)</span>
                        @if ($stale) <span class="au-badge">Kết quả cũ</span> @endif</dd>
                    <dt>URL đã quét</dt><dd><span class="au-mono">{{ $run['urls_crawled'] }} / {{ $run['urls_total'] }}</span> URL · {{ $run['ok_200'] }} phản hồi 200, {{ $run['not_found'] }} trả 404</dd>
                    <dt>Thời lượng</dt><dd>{{ intdiv($run['duration_s'], 60) }} phút {{ $run['duration_s'] % 60 }} giây <span class="au-muted">(giả lập, mẫu)</span></dd>
                    <dt>Nguồn URL</dt><dd>{{ $run['source'] }}</dd>
                    <dt>Kích hoạt bởi</dt><dd>{{ $run['triggered_by'] }}@if ($run['prev_at']) · so với lần quét {{ \App\Filament\Support\Schedule\VnTime::format($run['prev_at'], 'd/m') }}@endif</dd>
                </dl>
                <div style="flex:0 1 300px">
                    <x-filament::button color="gray" wire:click="rescan" :disabled="! $canRescan">Quét lại {{ $site->name }}</x-filament::button>
                    @if ($scanning)
                        <p class="au-lock">Đang quét; nút bị vô hiệu cho tới khi xong.</p>
                    @elseif (! $guard['ok'])
                        <p class="au-lock">🔒 {{ $guard['reason'] }} @if ($nextAtLabel) {{ $nextAtLabel }} @endif</p>
                    @endif
                </div>
            </div>
        @else
            <p style="margin:.5rem 0"><strong>Chưa quét website này</strong></p>
            <p class="au-muted">Chưa có lần quét nào nên chưa biết có lỗi hay không. Trang không hiển thị số 0 vì 0 nghĩa là đã quét và không thấy lỗi.</p>
            <x-filament::button color="gray" wire:click="rescan" :disabled="! $canRescan">Quét lần đầu</x-filament::button>
            @if (! $guard['ok'])
                <p class="au-lock">🔒 {{ $guard['reason'] }}</p>
            @endif
        @endif
    </section>

    {{-- Trạng thái đang quét / lỗi --}}
    @if ($state === 'dang-quet' && $site)
        <div class="au-banner" role="status">
            <strong>Đang quét {{ $site->name }} · 26 / 40 URL (65%)</strong> <span class="au-muted">(tiến độ minh họa)</span>
            <div class="au-progress" role="progressbar" aria-label="Tiến độ quét" aria-valuemin="0" aria-valuemax="40" aria-valuenow="26"><div style="width:65%"></div></div>
            <p class="au-muted" style="margin:.4rem 0 0">Bảng lỗi bên dưới vẫn là kết quả của lần quét trước và được ghi rõ là cũ cho tới khi quét xong. "Quét lại" và "Tạo brief từ lỗi" bị vô hiệu trong lúc quét để brief không lấy số liệu nửa chừng.</p>
        </div>
    @elseif ($state === 'quet-loi' && $site)
        <div class="au-banner au-banner--danger" role="alert">
            <strong>Không quét được {{ $site->name }}: robots.txt chặn crawler</strong> <span class="au-muted">(lỗi minh họa)</span>
            <p class="au-muted" style="margin:.4rem 0 0">Lần quét dừng sau 1 URL: <span class="au-mono">/robots.txt</span> có <span class="au-mono">Disallow: /</span> cho user-agent của crawler. Không có kết quả mới được ghi; bảng bên dưới là kết quả cũ. Các nguyên nhân khác hiển thị tương tự: site chặn bot (403/429) hoặc timeout.</p>
        </div>
    @endif

    @if ($run && $group)
        @php $s = $run['summary']; $p0 = array_values(array_filter($run['groups'], fn ($g) => $g['severity'] === 'P0')); @endphp
        <div class="au-banner {{ $p0 ? 'au-banner--danger' : '' }}" role="status">
            @if ($p0)
                <strong>{{ count($p0) }} nhóm lỗi P0: {{ collect($p0)->map(fn ($g) => $g['urls'].' trang — '.mb_strtolower($g['label']))->implode('; ') }}</strong>
            @else
                <strong>Không có nhóm lỗi P0</strong>
            @endif
            <p class="au-muted" style="margin:.3rem 0 0">{{ $s['total'] }} lỗi trên {{ $s['groups'] }} nhóm @if ($run['previous_total'] !== null)@php $d = array_sum(array_column($run['groups'], 'urls')) - $run['previous_total']; @endphp, tổng URL-lỗi theo nhóm {{ $d > 0 ? '+' : '' }}{{ $d }} so với lần trước ({{ $run['previous_total'] }}) @endif. Một URL có thể mắc nhiều lỗi nên tổng nhóm lớn hơn số URL: {{ $s['urls_with_issue'] }} / {{ $run['urls_crawled'] }} URL có ít nhất một lỗi.</p>
        </div>

        <div class="au-main">
            <section class="au-card" aria-labelledby="au-g-h">
                <div class="au-head">
                    <h2 class="au-h" id="au-g-h">Nhóm lỗi @if ($stale) <span class="au-badge">cũ</span> @endif</h2>
                    <span class="au-muted">{{ $s['groups'] }} nhóm · P0 {{ $s['by_severity']['P0'] }} · P1 {{ $s['by_severity']['P1'] }} · P2 {{ $s['by_severity']['P2'] }}</span>
                </div>
                <div style="overflow-x:auto">
                <table class="au-tbl">
                    <caption class="sr-only">Nhóm lỗi on-page theo mức, số URL ảnh hưởng và xu hướng so với lần quét trước</caption>
                    <thead><tr><th scope="col">Mức</th><th scope="col">Loại lỗi</th><th scope="col" class="num">URL</th><th scope="col" class="num">So với lần trước</th></tr></thead>
                    <tbody>
                    @foreach ($run['groups'] as $g)
                        <tr class="au-g {{ $g['type'] === $group['type'] ? 'is-selected' : '' }}" wire:key="au-g-{{ $g['type'] }}">
                            <td><span class="au-sev au-sev-{{ $g['severity'] }}">{{ $g['severity'] }}</span></td>
                            <td><button type="button" class="au-pick" wire:click="selectGroup('{{ $g['type'] }}')" @if ($g['type'] === $group['type']) aria-current="true" @endif>
                                <strong>{{ $g['label'] }}</strong><br><span class="au-muted">{{ $g['sub'] }}</span></button></td>
                            <td class="num">{{ $g['urls'] }}</td>
                            <td class="num">
                                @if ($g['delta'] === null) <span class="au-tr">—</span>
                                @elseif ($g['delta'] < 0) <span class="au-tr au-tr--better">{{ $g['delta'] }} · tốt hơn</span>
                                @elseif ($g['delta'] > 0) <span class="au-tr au-tr--worse">+{{ $g['delta'] }} · xấu đi</span>
                                @else <span class="au-tr">0 · giữ nguyên</span> @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
                <p class="au-muted" style="margin:.6rem 0 0">Mức do hệ thống gán theo loại lỗi (bảng cố định), chưa chỉnh được.</p>
            </section>

            <section class="au-card" aria-labelledby="au-d-h">
                <div class="au-head">
                    <h2 class="au-h" id="au-d-h">{{ $group['label'] }}</h2>
                    <span><span class="au-sev au-sev-{{ $group['severity'] }}">{{ $group['severity'] }}</span>
                        <span class="au-muted">{{ $group['urls'] }} URL @if ($group['occurrences'] !== $group['urls']) · {{ $group['occurrences'] }} lượt đo @endif</span></span>
                </div>
                <ul class="au-ev">
                    @foreach ($pageItems as $it)
                        <li wire:key="au-ev-{{ $pageNo }}-{{ $loop->index }}">
                            <span class="au-url">{{ $it['url'] }}</span>
                            <span style="font-size:.85rem">Đo được: {{ $it['measured'] }}</span>
                            <pre class="au-code">{{ $it['evidence'] }}</pre>
                        </li>
                    @endforeach
                </ul>
                <div class="au-pager">
                    <span class="au-muted">Hiển thị {{ count($pageItems) }} / {{ count($group['items']) }} dòng · trang {{ $pageNo }}/{{ $pageCount }}. Mỗi dòng là một lần đo; bằng chứng luôn kèm URL gốc.</span>
                    <x-filament::button size="xs" color="gray" wire:click="setUrlPage({{ $pageNo - 1 }})" :disabled="$pageNo <= 1">Trước</x-filament::button>
                    <x-filament::button size="xs" color="gray" wire:click="setUrlPage({{ $pageNo + 1 }})" :disabled="$pageNo >= $pageCount">Sau</x-filament::button>
                </div>

                <div class="au-ai">
                    <span class="au-badge">AI đề xuất (minh họa)</span> <strong style="font-size:.85rem">Cách sửa gợi ý</strong>
                    <ol>@foreach ($fixSteps as $step)<li>{{ $step }}</li>@endforeach</ol>
                    <p class="au-muted" style="margin:0">Nguồn: {{ $group['urls'] }} URL và bằng chứng trong lần quét {{ $vn($run['at']) }}. Nội dung sinh từ mẫu văn bản theo loại lỗi, không gọi AI thật và không mở URL nào; cần người kiểm lại trước khi sửa hoặc đặt redirect.</p>
                </div>

                <div style="margin-top:1rem;display:flex;flex-wrap:wrap;gap:.75rem;align-items:center">
                    <x-filament::button wire:click="createBrief" :disabled="! $canBrief">Tạo brief từ lỗi</x-filament::button>
                    <span class="au-muted">Brief sẽ đính kèm đủ {{ $group['urls'] }} URL và bằng chứng của nhóm này.
                        @if (! $canAct) Chỉ approver trở lên được tạo brief. @elseif ($scanning) Đang quét nên chưa tạo được. @endif</span>
                </div>
            </section>
        </div>
    @elseif ($site && ! $crawlerOff && $state !== 'chua-ket-noi')
        <section class="au-card">
            <h2 class="au-h">Nhóm lỗi</h2>
            <table class="au-tbl"><thead><tr><th>Mức</th><th>Loại lỗi</th><th class="num">URL</th></tr></thead>
                <tbody><tr><td>—</td><td class="au-muted">Chưa quét</td><td class="num au-muted">—</td></tr></tbody></table>
        </section>
    @endif

    {{-- Brief đã tạo --}}
    @if ($briefs)
        <section class="au-card" aria-labelledby="au-b-h">
            <h2 class="au-h" id="au-b-h">Brief đã tạo ({{ count($briefs) }})</h2>
            <table class="au-tbl">
                <thead><tr><th>Brief</th><th>Người tạo</th><th>Thời điểm (giờ VN)</th></tr></thead>
                <tbody>
                @foreach (array_slice($briefs, 0, 10) as $b)
                    <tr wire:key="au-brief-{{ $b['id'] }}"><td>{{ $b['title'] }}</td><td>{{ $b['created_by_name'] }}</td><td>{{ $vn($b['created_at']) }}</td></tr>
                @endforeach
                </tbody>
            </table>
            <p class="au-muted" style="margin:.5rem 0 0">Danh sách lưu tạm (minh họa); sau này lưu bảng briefs. Brief chỉ tạo việc, không tự sửa website.</p>
        </section>
    @endif

    {{-- Luật nghiệp vụ --}}
    <section class="au-card au-rules" aria-label="Luật nghiệp vụ">
        <details>
            <summary>Luật nghiệp vụ của màn này</summary>
            <div style="overflow-x:auto">
            <table>
                <thead><tr><th>Luật</th><th>Nguồn dữ liệu</th><th>Tình trạng</th></tr></thead>
                <tbody>
                <tr><td>Chỉ quét website thuộc allowlist (đang active). Host kiểm bằng UrlSafetyService: chặn IP nội bộ và metadata cloud (chống SSRF). Site không đủ điều kiện: nút Quét lại vô hiệu kèm lý do.</td><td class="au-mono">websites.status, websites.base_url (sau này website_crawl_allowlist)</td><td>THẬT (luật). Domain mẫu không phân giải DNS vẫn cho xem luồng ở chế độ minh họa.</td></tr>
                <tr><td>Tối đa 1 lần quét mỗi website mỗi 6 giờ.</td><td class="au-mono">sau này audit_runs.started_at; hiện DemoStore seoaudit.site.*</td><td>Luật THẬT; mốc quét MINH HỌA. Con số 6 giờ là giả định, cần chủ dự án chốt.</td></tr>
                <tr><td>Chưa quét thì không hiện lỗi và không hiện số 0. Ghi "Chưa quét".</td><td class="au-mono">audit_runs (không có dòng nào)</td><td>THẬT (hành vi); ở production luôn là "chưa kết nối crawler".</td></tr>
                <tr><td>Mỗi lỗi có URL và bằng chứng (giá trị đo hoặc đoạn HTML). Không có bằng chứng thì không ghi lỗi.</td><td class="au-mono">sau này audit_issues.url, measured_value, evidence_html</td><td>Phân tích THẬT trên bộ trang mẫu; việc thu thập là MINH HỌA (crawler 1.1 chưa có).</td></tr>
                <tr><td>Quét lỗi (robots.txt, chặn bot, timeout) thì nói rõ, không bịa kết quả; kết quả cũ giữ nhãn "cũ".</td><td class="au-mono">sau này audit_runs.status, error_code, error_message</td><td>Chỉ xem được bằng ?demo=quet-loi hoặc ?demo=dang-quet.</td></tr>
                <tr><td>Mức P0/P1/P2 theo loại lỗi (bảng cố định). Xu hướng so với lần quét hoàn tất liền trước.</td><td class="au-mono">IssueCatalog; so hai lần quét</td><td>THẬT. Bảng mức cần Dev1 và chủ dự án chốt.</td></tr>
                <tr><td>Xem: writer trở lên. Quét lại và Tạo brief: approver trở lên (kiểm ở server).</td><td class="au-mono">users.role</td><td>THẬT.</td></tr>
                <tr><td>"AI đề xuất" chỉ dựa trên URL và bằng chứng của nhóm, gắn nhãn, cần người kiểm. Chỉ người bấm "Tạo brief từ lỗi" mới tạo việc.</td><td class="au-mono">mẫu văn bản theo loại lỗi; sau này briefs(source=audit, run_id, issue_type, urls[])</td><td>MINH HỌA (không gọi AI); brief THẬT.</td></tr>
                </tbody>
            </table>
            </div>
        </details>
    </section>

    {{-- Brief drawer --}}
    @if ($briefOpen && $brief)
        <div class="au-overlay" wire:key="au-brief-drawer" role="dialog" aria-modal="true" aria-labelledby="au-brief-t" x-data="{ copied: false }" x-on:keydown.escape.window="$wire.closeBrief()">
            <div class="au-drawer">
                <div class="au-head">
                    <h2 class="au-h" id="au-brief-t">{{ $brief['title'] }}</h2>
                    <x-filament::button color="gray" size="sm" wire:click="closeBrief">Đóng</x-filament::button>
                </div>
                <p class="au-muted" style="margin:0">Brief đã được lưu vào danh sách. Brief chỉ tạo việc, không tự sửa website.</p>
                <textarea x-ref="t" readonly aria-label="Nội dung brief">{{ $brief['body'] }}</textarea>
                <div>
                    <x-filament::button x-on:click="navigator.clipboard.writeText($refs.t.value); copied = true; setTimeout(() => copied = false, 2000)">Sao chép</x-filament::button>
                    <span class="au-muted" x-show="copied" x-cloak>Đã sao chép</span>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
