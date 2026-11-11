<x-filament-panels::page>
    <style>
        .kw-in{border:1px solid rgba(128,128,128,.5);border-radius:.4rem;padding:.35rem .55rem}
        .kw-bar{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:.5rem}
        .kw-cards{display:grid;gap:.6rem;grid-template-columns:repeat(auto-fill,minmax(20rem,1fr))}
        .kw-card{border:1px solid rgba(128,128,128,.3);border-radius:.5rem;padding:.7rem}
        .kw-card.is-merge{border-color:rgb(var(--primary-600))}
        .kw-chips{display:flex;flex-wrap:wrap;gap:.3rem;margin:.4rem 0}
        .kw-err{color:rgb(var(--danger-600))}
        .kw-steps{display:grid;gap:.6rem;grid-template-columns:repeat(4,minmax(0,1fr));margin-bottom:1rem;padding:0;list-style:none}
        @media (max-width:900px){.kw-steps{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media (max-width:520px){.kw-steps{grid-template-columns:minmax(0,1fr)}}
        .kw-step{display:flex;flex-direction:column;gap:.35rem;border:1px solid rgba(128,128,128,.3);border-radius:.6rem;padding:.7rem .8rem;position:relative}
        .kw-step.is-next{border:2px solid rgb(var(--primary-600));background:rgba(var(--primary-500),.07);padding:calc(.7rem - 1px) calc(.8rem - 1px)}
        .kw-step__head{display:flex;align-items:center;gap:.4rem;font-size:.8rem;font-weight:600}
        .kw-step__n{display:inline-flex;align-items:center;justify-content:center;width:1.4rem;height:1.4rem;border-radius:999px;border:1px solid rgba(128,128,128,.55);font-size:.75rem}
        .kw-step.is-done .kw-step__n{background:rgb(var(--success-600));border-color:rgb(var(--success-600));color:#fff}
        .kw-step.is-next .kw-step__n{background:rgb(var(--primary-600));border-color:rgb(var(--primary-600));color:#fff}
        .kw-step__tag{margin-left:auto;font-size:.7rem;font-weight:600;color:rgb(var(--primary-600))}
        .kw-step__main{all:unset;cursor:pointer;display:block}
        .kw-step__main:focus-visible{outline:2px solid currentColor;outline-offset:2px;border-radius:.3rem}
        .kw-step__val{font-size:1.6rem;font-weight:700;line-height:1.1}
        .kw-step__hint{font-size:.78rem;opacity:.75}
        .kw-step__note{font-size:.72rem;opacity:.7}
        .kw-step__btns{display:flex;flex-wrap:wrap;gap:.35rem;margin-top:auto;padding-top:.3rem}
        .kw-fold{border:1px solid rgba(128,128,128,.3);border-radius:.6rem;margin-top:1rem}
        .kw-fold__btn{all:unset;box-sizing:border-box;cursor:pointer;display:flex;width:100%;align-items:center;gap:.5rem;padding:.6rem .8rem;font-weight:600;font-size:.9rem}
        .kw-fold__btn:focus-visible{outline:2px solid currentColor;outline-offset:-2px}
        .kw-fold__body{padding:0 .8rem .8rem}
    </style>
    @php
        $items = $steps['items'];
        $next = $steps['next'];
        $unclustered = $total - $clustered;
    @endphp
    <script>
        /* Bấm ô trong dải: sang tab Từ khóa và lọc đúng phần còn thiếu (bộ lọc của bảng), hoặc bỏ lọc nếu không có. */
        window.kwGoTab = function (key) { var t = document.querySelector('[role=tab][data-key="' + key + '"]'); if (t) t.click(); };
        window.kwFilter = function (col, val) {
            window.kwGoTab('tu-khoa');
            var el = document.querySelector('#kw-table .ss-dt');
            if (!el || !window.Alpine) return;
            var d = window.Alpine.$data(el);
            d.query = '';
            Object.keys(d.filters).forEach(function (k) { d.filters[k] = ''; });
            if (col) d.filters[col] = val;
            el.scrollIntoView({ block: 'nearest' });
        };
        window.kwOpenClusters = function () { window.kwGoTab('tu-khoa'); window.dispatchEvent(new CustomEvent('kw-open-clusters')); };
    </script>
    <div class="ss-page">
        <ol class="kw-steps" aria-label="Bốn bước làm việc với từ khóa" data-testid="kw-steps" data-next="{{ $next }}">
            @foreach ($items as $it)
                @php $isNext = $next === $it['key']; @endphp
                <li class="kw-step {{ $isNext ? 'is-next' : '' }} {{ $it['done'] ? 'is-done' : '' }}" data-step="{{ $it['key'] }}" @if ($isNext) aria-current="step" @endif>
                    <div class="kw-step__head"><span class="kw-step__n" aria-hidden="true">{{ $it['n'] }}</span><span>{{ $it['title'] }}</span>@if ($isNext)<span class="kw-step__tag">Làm tiếp</span>@endif</div>
                    <button type="button" class="kw-step__main" @if ($it['filter']) onclick="kwFilter(@js($it['filter'][0]), @js($it['filter'][1]))" title="Bấm để xem các từ khóa còn thiếu bước này" @else onclick="kwFilter(null, null)" title="Bấm để xem tất cả từ khóa" @endif>
                        <span class="kw-step__val" data-testid="kw-step-{{ $it['key'] }}">{{ $it['value'] }}</span>
                        <span class="kw-step__hint" style="display:block">{{ $it['hint'] }}</span>
                    </button>
                    @if (! empty($it['note']))<span class="kw-step__note">{{ $it['note'] }}</span>@endif
                    <div class="kw-step__btns">
                        @if ($it['key'] === 'add')
                            <x-filament::button size="sm" :color="$isNext ? 'primary' : 'gray'" type="button" onclick="kwGoTab('nhap-them')">Nhập từ khóa</x-filament::button>
                            {{ $this->marketResearchAction }}
                        @elseif ($it['key'] === 'metrics')
                            {{ $this->fetchMetricsAction->color($isNext ? 'primary' : 'gray')->size('sm') }}
                        @elseif ($it['key'] === 'cluster')
                            <x-filament::button size="sm" :color="$isNext ? 'primary' : 'gray'" wire:click="propose" :disabled="$unclustered === 0">Gom cụm {{ $unclustered }} từ khóa</x-filament::button>
                            @if (count($proposals) > 0 || count($clusters) > 0)
                                <x-filament::button size="sm" color="gray" type="button" onclick="kwOpenClusters()">{{ count($proposals) > 0 ? 'Duyệt '.count($proposals).' đề xuất' : 'Quản lý cụm' }}</x-filament::button>
                            @endif
                        @else
                            <x-filament::button size="sm" :color="$isNext || $next === 'done' ? 'primary' : 'gray'" tag="a" :href="$planUrl">Mở Kế hoạch</x-filament::button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
        @if ($metricsInfo && $metricsInfo['with'] > 0)
            <p class="ss-text-sm ss-muted" role="region" aria-label="Nguồn số liệu lượt tìm" data-testid="metrics-sources"><span data-testid="metrics-count">{{ $metricsInfo['with'] }}/{{ $metricsInfo['total'] }} từ khóa có số liệu, {{ $metricsInfo['dataforseo'] }} từ nguồn DataForSEO, cập nhật gần nhất {{ $metricsInfo['latest'] ?? '—' }}.</span> Nguồn số liệu lượt tìm: CSV bạn nhập, DataForSEO hoặc Search Console (chưa nối). AI không biết lượt tìm.</p>
        @endif
        @php
            $tabs = [
                ['key' => 'tu-khoa', 'label' => 'Từ khóa', 'count' => $total],
                ['key' => 'nhap-them', 'label' => 'Nhập thêm', 'tone' => $preview ? 'info' : null],
            ];
        @endphp
        <x-studio.tabs persist="tu-khoa" :default="$total === 0 || $preview ? 'nhap-them' : 'tu-khoa'" :tabs="$tabs">
            <x-studio.panel key="tu-khoa">
                @if ($total === 0)
                    <div class="kw-bar" role="status">
                        <span class="ss-text-sm ss-muted">Chưa có từ khóa.</span>
                        <x-filament::button size="sm" type="button" onclick="kwGoTab('nhap-them')">Nhập từ khóa</x-filament::button>
                    </div>
                @else
                    @if ($real)<div class="kw-bar"><x-studio.badge tone="ok">Dữ liệu thật</x-studio.badge></div>@endif
                    <div id="kw-table">
                        <x-studio.data-table id="keywords" :columns="$kwColumns" :rows="$kwRows" :page-size="25" search="Tìm từ khóa hoặc cụm" />
                    </div>
                @endif
                @if ($total > 0 || count($proposals) > 0)
                    <div class="kw-fold" x-data="{ open: @js(count($proposals) > 0 || $renaming !== null) }" x-on:kw-open-clusters.window="open = true" data-testid="kw-clusters">
                        <button type="button" class="kw-fold__btn" x-on:click="open = ! open" x-bind:aria-expanded="open ? 'true' : 'false'">
                            <span aria-hidden="true" x-text="open ? '▾' : '▸'"></span>
                            <span>Cụm chủ đề</span>
                            <span class="ss-muted ss-text-xs" style="font-weight:400">{{ count($clusters) }} cụm @if (count($proposals) > 0)· {{ count($proposals) }} đề xuất chờ nhận @endif</span>
                        </button>
                        <div class="kw-fold__body" x-show="open" x-cloak>
                            <div class="ss-card__h" style="margin-top:.25rem">Đề xuất gom cụm @if ($proposals !== []) @if ($proposalSource === 'ai')<x-studio.badge tone="accent">AI đề xuất</x-studio.badge>@else<x-studio.badge>Bản minh họa (theo luật, không phải AI)</x-studio.badge>@endif @endif</div>
                            @if ($proposals === [])
                                <p class="ss-text-sm ss-muted">Chưa có đề xuất. Bấm "Gom cụm" ở bước ③ để AI nhóm các từ khóa chưa có cụm; bạn duyệt rồi mới nhận.</p>
                            @endif
                            <div class="kw-cards" style="margin-top:.75rem">
                    @foreach ($proposals as $p)
                        <article class="kw-card {{ $mergeFrom === $p['id'] ? 'is-merge' : '' }}" wire:key="prop-{{ $p['id'] }}">
                            <input class="kw-in" style="width:100%" wire:model="proposalNames.{{ $p['id'] }}" placeholder="{{ $p['name'] }}" aria-label="Tên cụm {{ $p['name'] }}">
                            <div class="ss-muted ss-text-xs">từ {{ count($p['keywords']) }} từ khóa · ý định chính: {{ $intents[$p['intent']] ?? $p['intent'] }}</div>
                            <div class="kw-chips">@foreach (array_slice($p['keywords'], 0, 6) as $k)<span class="ss-badge">{{ $k }}</span>@endforeach @if (count($p['keywords']) > 6)<span class="ss-muted ss-text-xs">+{{ count($p['keywords']) - 6 }}</span>@endif</div>
                            <div class="kw-bar">
                                <x-filament::button size="sm" wire:click="acceptProposal({{ $p['id'] }})">Nhận cụm</x-filament::button>
                                @if ($mergeFrom !== null && $mergeFrom !== $p['id'])
                                    <x-filament::button size="sm" color="gray" wire:click="mergeInto({{ $p['id'] }})">Gộp vào cụm này</x-filament::button>
                                @else
                                    <x-filament::button size="sm" color="gray" wire:click="startMerge({{ $p['id'] }})">{{ $mergeFrom === $p['id'] ? 'Bỏ chọn gộp' : 'Gộp…' }}</x-filament::button>
                                @endif
                                <x-filament::button size="sm" color="gray" wire:click="startSplit({{ $p['id'] }})" :disabled="count($p['keywords']) < 2">Tách…</x-filament::button>
                                <x-filament::button size="sm" color="gray" wire:click="discardProposal({{ $p['id'] }})">Bỏ</x-filament::button>
                            </div>
                            @if ($splitting === $p['id'])
                                <div style="margin-top:.5rem">
                                    <p class="ss-muted ss-text-xs">Chọn các từ khóa chuyển sang cụm mới:</p>
                                    @foreach ($p['keywords'] as $k)<label style="display:block;font-size:.8rem"><input type="checkbox" wire:model="splitKeywords" value="{{ $k }}"> {{ $k }}</label>@endforeach
                                    <input class="kw-in" wire:model="splitName" placeholder="Tên cụm mới" aria-label="Tên cụm mới">
                                    <x-filament::button size="sm" wire:click="saveSplit">Tách</x-filament::button>
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>
                            <div class="ss-card__h" style="margin-top:1rem">Cụm đã nhận</div>
                @if ($renaming !== null)
                <div class="kw-bar" role="group" aria-label="Sửa tên cụm">
                    <input class="kw-in" wire:model="renameTo" aria-label="Tên cụm mới" wire:keydown.enter="saveRename">
                    <x-filament::button size="sm" wire:click="saveRename">Lưu</x-filament::button>
                </div>
            @endif
            @if (count($clusters) === 0)
                <div class="ss-text-sm ss-muted" role="status">Chưa có cụm nào.</div>
            @else
                <x-studio.data-table id="clusters" :columns="$clusterColumns" :rows="$clusterRows" :page-size="25" search="Tìm cụm" />
            @endif
                        </div>
                    </div>
                @endif
            </x-studio.panel>

            <x-studio.panel key="nhap-them">
    @if ($preview)
        <div class="ss-card" style="margin-bottom:1rem" role="region" aria-label="Xem trước nhập CSV"><div class="ss-card__b">
            <div class="ss-card__h">Xem trước</div>
            @if ($preview['fatal'])
                <p class="kw-err ss-text-sm">{{ $preview['fatal'] }}</p>
            @else
                <p>{{ count($preview['rows']) }} hợp lệ · {{ count($preview['errors']) }} lỗi · {{ count($preview['duplicates']) }} trùng (sẽ bỏ qua)</p>
                <table class="ss-dt__table">
                    <thead><tr><th>Dòng</th><th>Từ khóa</th><th class="is-right">Volume</th><th>Ý định</th></tr></thead>
                    <tbody>
                    @foreach (array_slice($preview['rows'], 0, 20) as $r)
                        <tr><td>{{ $r['line'] }}</td><td>{{ $r['keyword'] }}</td><td class="is-right">{{ $r['volume'] !== null ? number_format($r['volume'], 0, ',', '.') : '—' }}</td><td>{{ $intents[$r['intent']] ?? $r['intent'] }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
                @if (count($preview['rows']) > 20)<p class="ss-muted ss-text-xs">Hiện 20 dòng đầu trong {{ count($preview['rows']) }} dòng hợp lệ.</p>@endif
                @foreach ($preview['errors'] as $e)<p class="kw-err ss-text-sm">Dòng {{ $e['line'] }}: {{ $e['reason'] }}</p>@endforeach
                @foreach ($preview['duplicates'] as $d)<p class="ss-muted ss-text-xs">Dòng {{ $d['line'] }}: "{{ $d['keyword'] }}" — {{ $d['reason'] }}</p>@endforeach
            @endif
            <div class="kw-bar" style="margin-top:.75rem">
                <x-filament::button wire:click="confirmImport" :disabled="$preview['fatal'] || count($preview['rows']) === 0">Xác nhận nhập {{ count($preview['rows']) }} từ khóa</x-filament::button>
                <x-filament::button color="gray" wire:click="cancelImport">Hủy</x-filament::button>
            </div>
        </div></div>
    @endif
                <div class="ss-split">
                    <div class="ss-card"><div class="ss-card__b">
                <div class="ss-card__h">Nhập CSV</div>
                <div style="margin-bottom:.5rem"><x-upload-zone model="csvFile" name="csv" accept=".csv,text/csv,text/plain" :max-mb="1" hint="CSV · tối đa 1 MB" label="Kéo thả tệp CSV vào đây hoặc bấm để chọn">
                    <div><x-filament::button color="gray" size="sm" wire:click="loadSample">Dùng tệp mẫu</x-filament::button></div>
                </x-upload-zone></div>
                <textarea class="kw-in" style="width:100%" rows="4" wire:model="csvText" placeholder="Hoặc dán nội dung CSV vào đây" aria-label="Nội dung CSV"></textarea>
                <div class="kw-bar" style="margin-top:.5rem"><x-filament::button color="gray" wire:click="previewCsv">Xem trước</x-filament::button></div>

                    </div></div>
                    <div class="ss-card"><div class="ss-card__b">
                <div class="ss-card__h">Thêm tay</div>
                <div class="kw-bar">
                    <input class="kw-in" wire:model="newKeyword" placeholder="Từ khóa" aria-label="Từ khóa mới" maxlength="255">
                    <input class="kw-in" wire:model="newVolume" placeholder="Volume (tùy chọn)" aria-label="Volume" style="width:9rem">
                    <select class="kw-in" wire:model="newIntent" aria-label="Ý định">
                        @foreach ($intents as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                    </select>
                    <x-filament::button wire:click="addKeyword">Thêm</x-filament::button>
                </div>
                    </div></div>
                </div>
                <div class="ss-card" style="margin-top:1rem"><div class="ss-card__b">
                    <div class="ss-card__h">Khảo sát thị trường (AI)</div>
                    <p class="ss-text-sm ss-muted">AI đề xuất thêm từ khóa theo thương hiệu và từ khóa đã có; từ mới có nhãn "AI đề xuất". AI không biết lượt tìm, nên cột Lượt tìm để trống cho tới khi bạn lấy số thật ở bước ②.</p>
                    <div class="kw-bar" style="margin-top:.5rem">{{ $this->marketResearchAction }}</div>
                </div></div>

                <p class="ss-muted ss-text-xs" style="margin-top:.75rem">CSV: bắt buộc cột <code>keyword</code>; tối đa 5.000 dòng, 1 MB. Từ khóa trùng (không phân biệt hoa/thường) bị bỏ qua và báo lại. Chỉ lưu khi bấm "Xác nhận".</p>
            </x-studio.panel>
        </x-studio.tabs>
    </div>
</x-filament-panels::page>
