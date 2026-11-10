@php
    $gapCols = [
        ['key' => 'keyword', 'label' => 'Từ khóa', 'type' => 'text', 'sortable' => true],
        ['key' => 'competitor', 'label' => 'Đối thủ', 'type' => 'text', 'sortable' => true, 'filter' => true],
        ['key' => 'crank', 'label' => 'Hạng đối thủ', 'type' => 'number', 'sortable' => true],
        ['key' => 'orank', 'label' => 'Hạng của ta', 'type' => 'number', 'sortable' => true],
        ['key' => 'volume', 'label' => 'Lượng tìm / tháng', 'type' => 'number', 'sortable' => true],
        ['key' => 'source', 'label' => 'Nguồn', 'type' => 'text', 'sortable' => true, 'visible' => false],
        ['key' => 'score', 'label' => 'Ưu tiên', 'type' => 'badge', 'sortable' => true],
        ['key' => 'status', 'label' => 'Trạng thái', 'type' => 'badge', 'sortable' => true],
        ['key' => 'act', 'label' => '', 'type' => 'actions', 'actions' => [['label' => 'Thêm từ khóa', 'method' => 'addGapKeyword'], ['label' => 'Bỏ qua', 'method' => 'ignoreGap', 'tone' => 'danger'], ['label' => 'Khôi phục', 'method' => 'restoreGap']]],
    ];
@endphp
<x-filament-panels::page>
    <x-chain-context step="Khám phá" purpose="Tìm khoảng trống nội dung so với đối thủ." :inputs="[['label' => 'Từ khóa đối thủ', 'note' => 'nhập CSV']]" :outputs="[['label' => 'Kho từ khóa (trang Từ khóa)', 'url' => \App\Filament\Pages\KeywordsPage::getUrl()], ['label' => 'Kế hoạch nội dung', 'url' => \App\Filament\Pages\ContentPlanPage::getUrl()]]" />
    <div class="ss-page" data-co-state="real">
        <div class="ss-toolbar">
            <label for="co-site" class="ss-text-sm ss-muted">Website</label>
            <select id="co-site" wire:model.live="websiteId">
                @foreach ($websites as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach
            </select>
            <x-studio.hint text="Dữ liệu thị trường không bắt buộc trả phí: nhập CSV (Keyword Planner, Search Console xuất tệp, công cụ bạn đang có), Search Console khi nối, trang đã quét. Hệ thống không truy cập website đối thủ và không tự điền lượng tìm hay điểm." />
        </div>

        @if (! $site)
            <div class="ss-alert ss-alert--warn">Chưa có website nào ở trạng thái Hoạt động. Hãy thêm và kích hoạt website trước.</div>
        @else
            <div class="ss-kpis">
                <div class="ss-kpi"><div class="ss-kpi__l">Đối thủ theo dõi</div><div class="ss-kpi__v">{{ $compCount }}</div><div class="ss-kpi__d ss-muted">tối đa {{ $maxCompetitors }}</div></div>
                <div class="ss-kpi"><div class="ss-kpi__l">Khoảng trống còn mới</div><div class="ss-kpi__v">{{ $compCount || $openCount ? $openCount : '—' }}</div><div class="ss-kpi__d ss-muted">{{ $addedCount }} đã thêm vào Từ khóa</div></div>
            </div>

            <x-studio.tabs persist="doi-thu-that" :tabs="[
                ['key' => 'gaps', 'label' => 'Khoảng trống', 'count' => count($gapRows)],
                ['key' => 'competitors', 'label' => 'Đối thủ', 'count' => $compCount],
                ['key' => 'import', 'label' => 'Nhập CSV'],
                ['key' => 'rules', 'label' => 'Quy tắc'],
            ]">
                <x-studio.panel key="gaps">
                    @if (count($gapRows) === 0)
                        <div class="ss-alert ss-alert--info" data-co-state="chua-co-du-lieu">Chưa có dữ liệu khoảng trống. Thêm đối thủ ở tab Đối thủ rồi nhập CSV ở tab Nhập CSV.</div>
                    @else
                        <div data-co-section="gaps">
                            <x-studio.data-table id="co-gaps-real" :columns="$gapCols" :rows="$gapRows" :page-size="25" search="Tìm từ khóa" empty="Chưa có khoảng trống" />
                        </div>
                    @endif
                </x-studio.panel>

                <x-studio.panel key="competitors">
                    <div class="ss-page">
                        <form wire:submit="addCompetitor" class="ss-toolbar">
                            <label for="co-new" class="ss-text-sm ss-muted">Thêm đối thủ</label>
                            <input id="co-new" type="text" wire:model="newDomain" placeholder="doithu.vn" autocomplete="off" aria-label="Tên miền đối thủ">
                            <input type="text" wire:model="newBrand" placeholder="Tên thương hiệu (tùy chọn)" autocomplete="off" aria-label="Tên thương hiệu" maxlength="120">
                            <select wire:model="newKind" aria-label="Loại đối thủ">
                                @foreach ($kinds as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                            </select>
                            <x-filament::button type="submit" color="gray" size="sm">Thêm đối thủ</x-filament::button>
                            <x-studio.hint text="Đối thủ trực tiếp: tên thương hiệu và tên miền (bỏ đuôi) tự thành từ cấm nhóm doi_thu, bài nhắc tên sẽ bị chặn bởi luật BAN01." />
                        </form>
                        <x-studio.data-table id="co-domains-real" :columns="[
                            ['key' => 'domain', 'label' => 'Tên miền', 'type' => 'text', 'sortable' => true],
                            ['key' => 'brand', 'label' => 'Thương hiệu', 'type' => 'text', 'sortable' => true],
                            ['key' => 'kind', 'label' => 'Loại', 'type' => 'text', 'sortable' => true],
                            ['key' => 'gaps', 'label' => 'Khoảng trống mới', 'type' => 'number', 'sortable' => true],
                            ['key' => 'act', 'label' => '', 'type' => 'actions', 'actions' => [['label' => 'Bỏ theo dõi', 'method' => 'removeCompetitorById', 'tone' => 'danger']]],
                        ]" :rows="$compRows" :page-size="10" search="Tìm tên miền" empty="Chưa có đối thủ nào" />
                    </div>
                </x-studio.panel>

                <x-studio.panel key="import">
                    <div class="ss-card" role="region" aria-label="Nhập CSV khoảng trống"><div class="ss-card__b" style="display:grid;gap:.5rem">
                        <div class="ss-text-sm">Cột bắt buộc <code>keyword</code>; tùy chọn <code>competitor_domain</code>, <code>competitor_rank</code>, <code>our_rank</code>, <code>volume</code>. Tối đa {{ number_format($maxRows, 0, ',', '.') }} dòng và 1 MB, UTF-8. <x-studio.hint text="Không có volume thì để trống cột; hệ thống không tự điền số và không chấm điểm ưu tiên cho dòng thiếu lượng tìm. Tên miền đối thủ phải đã được thêm ở tab Đối thủ." /></div>
                        <input type="file" wire:model="csvFile" accept=".csv,text/csv,text/plain" aria-label="Chọn tệp CSV">
                        <textarea style="width:100%" rows="4" wire:model="csvText" placeholder="Hoặc dán nội dung CSV vào đây" aria-label="Nội dung CSV"></textarea>
                        <div><x-filament::button color="gray" wire:click="previewCsv">Xem trước</x-filament::button></div>
                        @if ($csvPreview)
                            <div class="ss-text-sm" data-co-section="csv-preview" role="status" style="display:grid;gap:.25rem">
                                @if ($csvPreview['fatal'])
                                    <x-studio.badge tone="danger">{{ $csvPreview['fatal'] }}</x-studio.badge>
                                @else
                                    <div><x-studio.badge tone="ok">{{ count($csvPreview['rows']) }} dòng hợp lệ</x-studio.badge> <x-studio.badge tone="danger">{{ count($csvPreview['errors']) }} lỗi</x-studio.badge> <x-studio.badge tone="muted">{{ count($csvPreview['duplicates']) }} trùng</x-studio.badge></div>
                                    @foreach (array_slice($csvPreview['errors'], 0, 20) as $e)<div>Dòng {{ $e['line'] }}: {{ $e['reason'] }}</div>@endforeach
                                    @foreach (array_slice($csvPreview['duplicates'], 0, 20) as $e)<div>Dòng {{ $e['line'] }} ({{ $e['keyword'] }}): {{ $e['reason'] }}</div>@endforeach
                                    <div><x-filament::button wire:click="importCsv" color="primary" :disabled="count($csvPreview['rows']) === 0">Nhập {{ count($csvPreview['rows']) }} dòng hợp lệ</x-filament::button></div>
                                @endif
                            </div>
                        @endif
                    </div></div>
                </x-studio.panel>

                <x-studio.panel key="rules">
                    <div class="ss-grid ss-text-sm">
                        <div class="ss-card"><div class="ss-card__h">Điểm ưu tiên</div><div class="ss-card__b" style="display:grid;gap:.5rem">
                            <div class="ss-mono">{{ $formulaReal }}</div>
                            <div class="ss-muted">V = log10(lượng tìm + 1) ÷ log10(lượng tìm lớn nhất + 1) · O = số đối thủ cùng xếp hạng ÷ tổng số đối thủ. Dòng không có lượng tìm: để trống điểm. Chỉ để xếp thứ tự.</div>
                        </div></div>
                        <div class="ss-card"><div class="ss-card__h">Nguồn dữ liệu và phạm vi</div><div class="ss-card__b"><ul style="list-style:disc;padding-left:1.25rem;display:grid;gap:.25rem">
                            <li>Không bắt buộc trả phí: CSV, Search Console (khi nối), trang đã quét. Hệ thống không truy cập website đối thủ.</li>
                            <li>Tên miền được chuẩn hóa; từ chối IP, localhost, tên miền nội bộ, tài khoản/cổng, trùng, tên miền của chính website và quá {{ $maxCompetitors }} đối thủ.</li>
                            <li>"Thêm từ khóa" tạo từ khóa nguồn Đối thủ ở trang Từ khóa (không trùng), sau đó thêm vào kế hoạch như bình thường.</li>
                            <li>Quyền: quản duyệt trở lên.</li>
                        </ul></div></div>
                    </div>
                </x-studio.panel>
            </x-studio.tabs>
        @endif
    </div>
</x-filament-panels::page>
