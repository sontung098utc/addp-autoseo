<x-filament-panels::page>
    <style>
        .kw-box{border:1px solid rgba(128,128,128,.3);border-radius:.6rem;padding:1rem;margin-bottom:1rem}
        .kw-h{font-weight:600;margin-bottom:.5rem}
        .kw-muted{opacity:.75;font-size:.8rem}
        .kw-bar{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:.75rem}
        .kw-in{border:1px solid rgba(128,128,128,.5);border-radius:.4rem;padding:.35rem .55rem;font-size:.85rem;background:transparent;color:inherit}
        .kw-table{width:100%;border-collapse:collapse;font-size:.85rem}
        .kw-table th,.kw-table td{text-align:left;padding:.4rem .5rem;border-bottom:1px solid rgba(128,128,128,.2)}
        .kw-num{text-align:right!important}
        .kw-badge{display:inline-block;border:1px solid rgba(128,128,128,.5);border-radius:.8rem;padding:0 .5rem;font-size:.72rem}
        .kw-cards{display:grid;gap:.6rem}
        .kw-card{border:1px solid rgba(128,128,128,.3);border-radius:.5rem;padding:.7rem}
        .kw-card.is-merge{border-color:rgb(var(--primary-600))}
        .kw-chips{display:flex;flex-wrap:wrap;gap:.3rem;margin:.4rem 0}
        .kw-err{color:rgb(var(--danger-600));font-size:.8rem}
        .kw-split{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr);gap:1rem}
        @media (max-width:1024px){.kw-split{grid-template-columns:1fr}}
    </style>

    <x-demo-banner source="kho từ khóa và AI gom cụm" real="phân tích CSV, kiểm trùng, luật cụm, phân quyền" />

    @if ($preview)
        <div class="kw-box" role="region" aria-label="Xem trước nhập CSV">
            <div class="kw-h">Xem trước — chưa lưu gì</div>
            @if ($preview['fatal'])
                <p class="kw-err">{{ $preview['fatal'] }}</p>
            @else
                <p>{{ count($preview['rows']) }} hợp lệ · {{ count($preview['errors']) }} lỗi · {{ count($preview['duplicates']) }} trùng (sẽ bỏ qua)</p>
                <table class="kw-table">
                    <thead><tr><th>Dòng</th><th>Từ khóa</th><th class="kw-num">Volume</th><th>Ý định</th></tr></thead>
                    <tbody>
                    @foreach (array_slice($preview['rows'], 0, 20) as $r)
                        <tr><td>{{ $r['line'] }}</td><td>{{ $r['keyword'] }}</td><td class="kw-num">{{ $r['volume'] !== null ? number_format($r['volume'], 0, ',', '.') : '—' }}</td><td>{{ $intents[$r['intent']] ?? $r['intent'] }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
                @if (count($preview['rows']) > 20)<p class="kw-muted">Hiện 20 dòng đầu trong {{ count($preview['rows']) }} dòng hợp lệ.</p>@endif
                @foreach ($preview['errors'] as $e)<p class="kw-err">Dòng {{ $e['line'] }}: {{ $e['reason'] }}</p>@endforeach
                @foreach ($preview['duplicates'] as $d)<p class="kw-muted">Dòng {{ $d['line'] }}: "{{ $d['keyword'] }}" — {{ $d['reason'] }}</p>@endforeach
            @endif
            <div class="kw-bar" style="margin-top:.75rem">
                <x-filament::button wire:click="confirmImport" :disabled="$preview['fatal'] || count($preview['rows']) === 0">Xác nhận nhập {{ count($preview['rows']) }} từ khóa</x-filament::button>
                <x-filament::button color="gray" wire:click="cancelImport">Hủy</x-filament::button>
            </div>
        </div>
    @endif

    <div class="kw-split">
        <section>
            <div class="kw-box">
                <div class="kw-h">Nhập từ khóa</div>
                <p class="kw-muted">Cột bắt buộc <code>keyword</code>; tùy chọn <code>volume</code>, <code>intent</code>. Dấu , hoặc ; · UTF-8 · tối đa 5.000 dòng và 1 MB. Ý định để trống = Thông tin. Từ khóa trùng (không phân biệt hoa/thường) bị bỏ qua và báo lại.</p>
                <div class="kw-bar">
                    <input type="file" wire:model="csvFile" accept=".csv,text/csv,text/plain" aria-label="Chọn tệp CSV">
                    <x-filament::button color="gray" size="sm" wire:click="loadSample">Dùng tệp mẫu</x-filament::button>
                </div>
                <textarea class="kw-in" style="width:100%" rows="4" wire:model="csvText" placeholder="Hoặc dán nội dung CSV vào đây" aria-label="Nội dung CSV"></textarea>
                <div class="kw-bar" style="margin-top:.5rem"><x-filament::button color="gray" wire:click="previewCsv">Xem trước</x-filament::button></div>
                <hr style="margin:.75rem 0;opacity:.3">
                <div class="kw-h">Thêm tay một từ khóa</div>
                <div class="kw-bar">
                    <input class="kw-in" wire:model="newKeyword" placeholder="Từ khóa" aria-label="Từ khóa mới" maxlength="255">
                    <input class="kw-in" wire:model="newVolume" placeholder="Volume (tùy chọn)" aria-label="Volume" style="width:9rem">
                    <select class="kw-in" wire:model="newIntent" aria-label="Ý định">
                        @foreach ($intents as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                    </select>
                    <x-filament::button wire:click="addKeyword">Thêm</x-filament::button>
                </div>
            </div>

            <div class="kw-box">
                <div class="kw-h">Danh sách từ khóa <span class="kw-muted">{{ $filteredCount }} / {{ $total }} dòng · {{ $clustered }} đã gom cụm</span></div>
                @if ($total === 0)
                    <div role="status">
                        <strong>Chưa có từ khóa.</strong>
                        <p class="kw-muted">Nhập tệp CSV hoặc thêm tay ở trên. Sau đó bấm "Gom cụm bằng AI" để chia thành cụm chủ đề rồi sang trang Kế hoạch nội dung.</p>
                    </div>
                @else
                    <div class="kw-bar" role="search">
                        <input class="kw-in" type="search" wire:model.live.debounce.300ms="search" placeholder="Tìm từ khóa hoặc cụm" aria-label="Tìm từ khóa">
                        <select class="kw-in" wire:model.live="filterCluster" aria-label="Lọc theo cụm">
                            <option value="">Mọi cụm</option><option value="__none">Chưa gom cụm</option>
                            @foreach ($clusters as $c)<option value="{{ $c['name'] }}">{{ $c['name'] }}</option>@endforeach
                        </select>
                        <select class="kw-in" wire:model.live="filterIntent" aria-label="Lọc theo ý định">
                            <option value="">Mọi ý định</option>@foreach ($intents as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                        </select>
                        <select class="kw-in" wire:model.live="filterSource" aria-label="Lọc theo nguồn">
                            <option value="">Mọi nguồn</option><option value="csv">CSV</option><option value="tay">Tay</option><option value="ai">AI</option>
                        </select>
                        <select class="kw-in" wire:model.live="sortBy" aria-label="Sắp xếp">
                            <option value="alpha">Theo bảng chữ cái</option><option value="volume">Volume giảm dần</option><option value="newest">Mới thêm trước</option>
                        </select>
                    </div>
                    <table class="kw-table">
                        <caption class="sr-only">Danh sách từ khóa</caption>
                        <thead><tr><th>Từ khóa</th><th>Ý định</th><th>Cụm chủ đề</th><th class="kw-num" title="Giá trị ghi trong tệp CSV nội bộ; không phải dữ liệu Google Keyword Planner">Volume CSV</th><th>Nguồn</th><th></th></tr></thead>
                        <tbody>
                        @forelse ($rows as $r)
                            <tr wire:key="kw-{{ $r['id'] }}">
                                <td>{{ $r['keyword'] }}</td>
                                <td><span class="kw-badge">{{ $intents[$r['intent']] ?? $r['intent'] }}</span></td>
                                <td>{{ $r['cluster'] ?? '—' }}</td>
                                <td class="kw-num">{{ $r['volume'] !== null ? number_format($r['volume'], 0, ',', '.') : '—' }}</td>
                                <td><span class="kw-badge">{{ ['csv' => 'CSV', 'tay' => 'Tay', 'ai' => 'AI'][$r['source']] ?? $r['source'] }}</span></td>
                                <td><button type="button" class="kw-muted" style="text-decoration:underline" wire:click="deleteKeyword({{ $r['id'] }})" wire:confirm="Xóa từ khóa này khỏi kho? Bài đã tạo không bị xóa." aria-label="Xóa từ khóa {{ $r['keyword'] }}">Xóa</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="kw-muted">Không có từ khóa khớp bộ lọc.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                    @if ($filteredCount > $limit)
                        <div class="kw-bar" style="margin-top:.5rem"><x-filament::button color="gray" size="sm" wire:click="showMore">Hiện thêm</x-filament::button></div>
                    @endif
                @endif
            </div>
        </section>

        <aside>
            <div class="kw-box">
                <div class="kw-h">Gom cụm bằng AI <span class="kw-badge">AI đề xuất (minh họa)</span></div>
                <p class="kw-muted">Đề xuất chỉ gom các từ khóa chưa có cụm. Bạn duyệt từng đề xuất; chưa nhận thì chưa có cụm nào được tạo.</p>
                <x-filament::button wire:click="propose" :disabled="$total - $clustered === 0">Gom cụm {{ $total - $clustered }} từ khóa chưa gom</x-filament::button>
                @if ($total - $clustered === 0 && $total > 0)<p class="kw-muted">Mọi từ khóa đã có cụm.</p>@endif

                <div class="kw-cards" style="margin-top:.75rem">
                    @foreach ($proposals as $p)
                        <article class="kw-card {{ $mergeFrom === $p['id'] ? 'is-merge' : '' }}" wire:key="prop-{{ $p['id'] }}">
                            <input class="kw-in" style="width:100%" wire:model="proposalNames.{{ $p['id'] }}" placeholder="{{ $p['name'] }}" aria-label="Tên cụm {{ $p['name'] }}">
                            <div class="kw-muted">từ {{ count($p['keywords']) }} từ khóa · ý định chính: {{ $intents[$p['intent']] ?? $p['intent'] }}</div>
                            <div class="kw-chips">@foreach (array_slice($p['keywords'], 0, 6) as $k)<span class="kw-badge">{{ $k }}</span>@endforeach @if (count($p['keywords']) > 6)<span class="kw-muted">+{{ count($p['keywords']) - 6 }}</span>@endif</div>
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
                                    <p class="kw-muted">Chọn các từ khóa chuyển sang cụm mới:</p>
                                    @foreach ($p['keywords'] as $k)<label style="display:block;font-size:.8rem"><input type="checkbox" wire:model="splitKeywords" value="{{ $k }}"> {{ $k }}</label>@endforeach
                                    <input class="kw-in" wire:model="splitName" placeholder="Tên cụm mới" aria-label="Tên cụm mới">
                                    <x-filament::button size="sm" wire:click="saveSplit">Tách</x-filament::button>
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>
            </div>

            <div class="kw-box">
                <div class="kw-h">Cụm đã nhận ({{ count($clusters) }})</div>
                @forelse ($clusters as $c)
                    <div class="kw-card" style="margin-bottom:.5rem" wire:key="cl-{{ $loop->index }}">
                        @if ($renaming === $c['name'])
                            <input class="kw-in" wire:model="renameTo" aria-label="Tên cụm mới" wire:keydown.enter="saveRename">
                            <x-filament::button size="sm" wire:click="saveRename">Lưu</x-filament::button>
                        @else
                            <strong>{{ $c['name'] }}</strong>
                            <button type="button" class="kw-muted" style="text-decoration:underline" wire:click="startRename(@js($c['name']))">Sửa tên</button>
                        @endif
                        <div class="kw-muted">{{ count($c['keywords']) }} từ khóa · {{ $intents[$c['intent']] ?? $c['intent'] }}</div>
                    </div>
                @empty
                    <p class="kw-muted">Chưa có cụm nào. Cụm đã nhận sẽ xuất hiện ở đây và ở trang Kế hoạch nội dung.</p>
                @endforelse
            </div>
        </aside>
    </div>

    <details class="kw-box">
        <summary class="kw-h">Luật nghiệp vụ của màn này</summary>
        <ul class="kw-muted" style="list-style:disc;padding-left:1.2rem">
            <li>Chỉ tài khoản Biên tập viên trở lên (đang hoạt động) mới xem và sửa được; máy chủ kiểm lại ở mọi thao tác.</li>
            <li>CSV: bắt buộc cột <code>keyword</code>; tối đa 5.000 dòng và 1 MB; dòng sai được báo số dòng + lý do, không bỏ im lặng.</li>
            <li>Từ khóa trùng (không phân biệt hoa/thường, gộp khoảng trắng) trong tệp hoặc với kho hiện có bị bỏ qua và báo lại.</li>
            <li>Xem trước 20 dòng đầu; chỉ lưu khi bấm "Xác nhận".</li>
            <li>Mỗi từ khóa thuộc tối đa một cụm. Gom cụm bằng AI chỉ là đề xuất minh họa; người duyệt nhận / sửa tên / gộp / tách / bỏ.</li>
            <li>Xóa từ khóa không xóa bài đã tạo. Volume là số ghi trong tệp CSV, không phải dữ liệu Google.</li>
            <li>Kho từ khóa hiện lưu tạm (minh họa) cho tới khi Dev1 thêm bảng thật; ở production chưa có dữ liệu.</li>
        </ul>
    </details>
</x-filament-panels::page>
