<x-filament-panels::page>
    <style>
        .kw-in{border:1px solid rgba(128,128,128,.5);border-radius:.4rem;padding:.35rem .55rem}
        .kw-bar{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:.5rem}
        .kw-cards{display:grid;gap:.6rem;grid-template-columns:repeat(auto-fill,minmax(20rem,1fr))}
        .kw-card{border:1px solid rgba(128,128,128,.3);border-radius:.5rem;padding:.7rem}
        .kw-card.is-merge{border-color:rgb(var(--primary-600))}
        .kw-chips{display:flex;flex-wrap:wrap;gap:.3rem;margin:.4rem 0}
        .kw-err{color:rgb(var(--danger-600))}
    </style>
    @php $unclustered = $total - $clustered; @endphp
    <div class="ss-page">
        <x-chain-context step="Khám phá" purpose="Kho từ khóa của website: nơi gom từ khóa từ CSV, AI đề xuất, đối thủ, Search Console; gom thành cụm để lập kế hoạch bài viết" :inputs="$chainInputs" :outputs="$chainOutputs" />
        @if ($metricsInfo)
            <div class="ss-card" style="margin-bottom:1rem" role="region" aria-label="Nguồn số liệu lượt tìm" data-testid="metrics-sources"><div class="ss-card__b">
                <div class="ss-card__h">Nguồn số liệu lượt tìm <x-studio.hint text="AI không biết lượt tìm. Số liệu thật chỉ đến từ CSV bạn nhập, nhà cung cấp số liệu (DataForSEO) hoặc Search Console. Chi phí nêu ở đây là ước tính theo cấu hình." /></div>
                <ul class="ss-text-sm" style="list-style:disc;padding-left:1.2rem">
                    <li><strong>CSV</strong>: luôn có (tab "Nhập từ khóa", cột volume).</li>
                    <li><strong>DataForSEO</strong>:
                        @if ($metricsInfo['available'] && ! $metricsInfo['demo'])đã cấu hình. Nút "Lấy lượt tìm thật" tra theo lô.
                        @elseif ($metricsInfo['demo'])chưa cấu hình; đang dùng số giả lập của môi trường local (không phải lượt tìm thật).
                        @else chưa cấu hình. {{ $metricsInfo['reason'] }}@endif
                    </li>
                    <li><strong>Search Console</strong>: {{ $metricsInfo['gsc'] }}</li>
                </ul>
                <p class="ss-text-sm" data-testid="metrics-count">{{ $metricsInfo['with'] }}/{{ $metricsInfo['total'] }} từ khóa có số liệu, {{ $metricsInfo['dataforseo'] }} từ nguồn DataForSEO, cập nhật gần nhất {{ $metricsInfo['latest'] ?? 'chưa có' }}.</p>
            </div></div>
        @endif
        <x-studio.tabs persist="tu-khoa" :tabs="[
            ['key' => 'list', 'label' => 'Danh sách', 'count' => $total],
            ['key' => 'import', 'label' => 'Nhập từ khóa', 'tone' => $preview ? 'info' : null],
            ['key' => 'propose', 'label' => 'Gom cụm', 'count' => count($proposals) ?: null, 'tone' => count($proposals) ? 'warn' : null],
            ['key' => 'clusters', 'label' => 'Cụm đã nhận', 'count' => count($clusters)],
            ['key' => 'rules', 'label' => 'Quy tắc'],
        ]">
            <x-studio.panel key="list">
                @if ($total === 0)
                    <div class="ss-alert ss-alert--info" role="status">Chưa có từ khóa. Nhập CSV hoặc thêm tay ở tab "Nhập từ khóa".</div>
                @else
                    <div class="kw-bar">
                        @if ($real)<x-studio.badge tone="ok">Dữ liệu thật</x-studio.badge>@endif
                        <x-studio.hint text="Cột lượng tìm hiện — (Chưa có số liệu) khi chưa có số. Muốn có số: bấm 'Lấy lượt tìm thật' hoặc nhập CSV có cột volume. Hệ thống không tự điền số. Cột 'Nguồn số liệu' cho biết số đến từ đâu." />
                    </div>
                    <x-studio.data-table id="keywords" :columns="$kwColumns" :rows="$kwRows" :page-size="25" search="Tìm từ khóa hoặc cụm" />
                @endif
            </x-studio.panel>

            <x-studio.panel key="import">
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
                <div class="ss-card__h">Nhập CSV <x-studio.hint text="Từ khóa lấy từ đâu — không bắt buộc mua dịch vụ: nhập tay, CSV (Keyword Planner/Search Console…), Search Console khi nối, AI đề xuất (không có lượng tìm). Lượng tìm chỉ có khi tệp CSV của bạn có cột volume." /></div>
                <p class="ss-muted ss-text-xs">Cột: <code>keyword</code> (bắt buộc), <code>volume</code>, <code>intent</code> · tối đa 5.000 dòng, 1 MB.</p>
                <div class="kw-bar">
                    <input type="file" wire:model="csvFile" accept=".csv,text/csv,text/plain" aria-label="Chọn tệp CSV">
                    <x-filament::button color="gray" size="sm" wire:click="loadSample">Dùng tệp mẫu</x-filament::button>
                </div>
                <textarea class="kw-in" style="width:100%" rows="4" wire:model="csvText" placeholder="Hoặc dán nội dung CSV vào đây" aria-label="Nội dung CSV"></textarea>
                <div class="kw-bar" style="margin-top:.5rem"><x-filament::button color="gray" wire:click="previewCsv">Xem trước</x-filament::button></div>

                    </div></div>
                    <div class="ss-card"><div class="ss-card__b">
                <div class="ss-card__h">Thêm tay <x-studio.hint text="Từ khóa nhập tay được ghi nguồn 'Nhập tay'. Bỏ trống lượng tìm nếu bạn không có số liệu." /></div>
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
            </x-studio.panel>

            <x-studio.panel key="propose">
                <div class="ss-card"><div class="ss-card__b">
                <div class="ss-card__h">Gom cụm @if ($proposals !== []) @if ($proposalSource === 'ai')<x-studio.badge tone="accent">AI đề xuất</x-studio.badge>@else<x-studio.badge>Bản minh họa (theo luật, không phải AI)</x-studio.badge>@endif @endif</div>
                <x-filament::button wire:click="propose" :disabled="$total - $clustered === 0">Gom cụm {{ $total - $clustered }} từ khóa chưa gom</x-filament::button>
                @if ($total - $clustered === 0 && $total > 0)<p class="ss-muted ss-text-xs">Mọi từ khóa đã có cụm.</p>@endif

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
                </div></div>
            </x-studio.panel>

            <x-studio.panel key="clusters">
                @if ($renaming !== null)
                    <div class="kw-bar" role="group" aria-label="Sửa tên cụm">
                        <input class="kw-in" wire:model="renameTo" aria-label="Tên cụm mới" wire:keydown.enter="saveRename">
                        <x-filament::button size="sm" wire:click="saveRename">Lưu</x-filament::button>
                    </div>
                @endif
                @if (count($clusters) === 0)
                    <div class="ss-alert ss-alert--info" role="status">Chưa có cụm nào.</div>
                @else
                    <x-studio.data-table id="clusters" :columns="$clusterColumns" :rows="$clusterRows" :page-size="25" search="Tìm cụm" />
                @endif
            </x-studio.panel>

            <x-studio.panel key="rules">
                <div class="ss-card"><div class="ss-card__b">
                    <ul class="ss-text-sm" style="list-style:disc;padding-left:1.2rem">
            <li>Chỉ Biên tập viên trở lên (đang hoạt động) xem và sửa được; máy chủ kiểm lại ở mọi thao tác.</li>
            <li>CSV: bắt buộc cột <code>keyword</code>; tối đa 5.000 dòng và 1 MB; dòng sai được báo số dòng và lý do.</li>
            <li>Từ khóa trùng (không phân biệt hoa/thường, gộp khoảng trắng) bị bỏ qua và báo lại.</li>
            <li>Xem trước 20 dòng đầu; chỉ lưu khi bấm "Xác nhận".</li>
            <li>Mỗi từ khóa thuộc tối đa một cụm. AI chỉ đề xuất; người duyệt nhận, sửa tên, gộp, tách hoặc bỏ.</li>
            <li>Xóa từ khóa không xóa bài đã tạo. Lượng tìm là số ghi trong tệp CSV bạn nhập, không phải dữ liệu Google; để trống khi nguồn không có.</li>
        </ul>
                </div></div>
            </x-studio.panel>
        </x-studio.tabs>
    </div>
</x-filament-panels::page>
