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
    <x-chain-context step="Khám phá" purpose="Tìm khoảng trống nội dung so với đối thủ." :inputs="[['label' => 'Từ khóa đối thủ', 'note' => 'nhập CSV']]" :outputs="[['label' => 'Kho từ khóa (trang Từ khóa)', 'url' => \App\Filament\Pages\KeywordsPage::getUrl()], ['label' => 'Kế hoạch nội dung', 'url' => \App\Filament\Pages\ContentPlanPage::getUrl()]]" :compact="true" />
    @php $multiSite = count($websites) > 1; @endphp
    <div class="ss-page" data-co-state="real">
        @if (! $site)
            <div class="ss-alert ss-alert--warn">Chưa có website nào ở trạng thái Hoạt động. Hãy thêm và kích hoạt website trước.</div>
        @elseif ($compCount === 0)
            @if ($multiSite)
                <div class="ss-toolbar">
                    <label for="co-site" class="ss-text-sm ss-muted">Website</label>
                    <select id="co-site" wire:model.live="websiteId">@foreach ($websites as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</select>
                </div>
            @endif
            <div class="ss-card" data-co-section="empty"><div class="ss-card__b" style="display:grid;gap:.75rem;justify-items:center;text-align:center;padding:2.5rem 1rem">
                <div style="font-weight:600">Chưa theo dõi đối thủ nào
                    <x-help title="Hệ thống làm gì với đối thủ?">
                        <div style="max-height:60vh;overflow:auto">
                            <p>Dữ liệu thị trường không bắt buộc trả phí: nhập CSV (Keyword Planner, Search Console xuất tệp, công cụ bạn đang có), Search Console khi nối, trang đã quét. Hệ thống không truy cập website đối thủ và không tự điền lượng tìm hay điểm.</p>
                            <p>Đối thủ trực tiếp: tên thương hiệu và tên miền (bỏ đuôi) tự thành từ cấm nhóm doi_thu, bài nhắc tên sẽ bị chặn bởi luật BAN01.</p>
                            <p>Tên miền được chuẩn hóa; từ chối IP, localhost, tên miền nội bộ, trùng, tên miền của chính website và quá {{ $maxCompetitors }} đối thủ. Quyền: quản duyệt trở lên.</p>
                        </div>
                    </x-help>
                </div>
                <form wire:submit="addCompetitor" class="ss-toolbar" style="justify-content:center">
                    <input id="co-new" type="text" wire:model="newDomain" placeholder="doithu.vn" autocomplete="off" aria-label="Tên miền đối thủ">
                    <input type="text" wire:model="newBrand" placeholder="Tên thương hiệu (tùy chọn)" autocomplete="off" aria-label="Tên thương hiệu" maxlength="120">
                    <select wire:model="newKind" aria-label="Loại đối thủ">@foreach ($kinds as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</select>
                    <x-filament::button type="submit" color="primary" icon="heroicon-o-plus">Thêm đối thủ</x-filament::button>
                </form>
            </div></div>
        @else
            <div class="ss-toolbar">
                @if ($multiSite)
                    <label for="co-site" class="ss-text-sm ss-muted">Website</label>
                    <select id="co-site" wire:model.live="websiteId">@foreach ($websites as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</select>
                @endif
                <x-help title="Nguồn dữ liệu, điểm ưu tiên và quy tắc">
                    <div style="max-height:60vh;overflow:auto">
                        <p><b>Điểm ưu tiên</b> <span class="ss-mono">{{ $formulaReal }}</span><br>V = log10(lượng tìm + 1) ÷ log10(lượng tìm lớn nhất + 1) · O = số đối thủ cùng xếp hạng ÷ tổng số đối thủ. Dòng không có lượng tìm: để trống điểm. Chỉ để xếp thứ tự.</p>
                        <ul>
                            <li>Không bắt buộc trả phí: CSV, Search Console (khi nối), trang đã quét. Hệ thống không truy cập website đối thủ.</li>
                            <li>Tên miền được chuẩn hóa; từ chối IP, localhost, tên miền nội bộ, tài khoản/cổng, trùng, tên miền của chính website và quá {{ $maxCompetitors }} đối thủ.</li>
                            <li>"Thêm từ khóa" tạo từ khóa nguồn Đối thủ ở trang Từ khóa (không trùng), sau đó thêm vào kế hoạch như bình thường.</li>
                            <li>Quyền: quản duyệt trở lên.</li>
                        </ul>
                    </div>
                </x-help>
            </div>

            @php
                $tabs = [];
                if (count($gapRows) > 0) { $tabs[] = ['key' => 'gaps', 'label' => 'Khoảng trống', 'count' => count($gapRows)]; }
                $tabs[] = ['key' => 'competitors', 'label' => 'Đối thủ', 'count' => $compCount];
                $tabs[] = ['key' => 'import', 'label' => 'Nhập CSV'];
            @endphp
            <x-studio.tabs persist="doi-thu-that" :default="count($gapRows) > 0 ? 'gaps' : 'competitors'" :tabs="$tabs">
                @if (count($gapRows) > 0)
                <x-studio.panel key="gaps">
                    <div data-co-section="gaps">
                        <x-studio.data-table id="co-gaps-real" :columns="$gapCols" :rows="$gapRows" :page-size="25" search="Tìm từ khóa" empty="Chưa có khoảng trống" />
                    </div>
                </x-studio.panel>
                @endif

                <x-studio.panel key="competitors">
                    <div class="ss-page">
                        <form wire:submit="addCompetitor" class="ss-toolbar">
                            <input id="co-new" type="text" wire:model="newDomain" placeholder="doithu.vn" autocomplete="off" aria-label="Tên miền đối thủ">
                            <input type="text" wire:model="newBrand" placeholder="Tên thương hiệu (tùy chọn)" autocomplete="off" aria-label="Tên thương hiệu" maxlength="120">
                            <select wire:model="newKind" aria-label="Loại đối thủ">
                                @foreach ($kinds as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                            </select>
                            <x-filament::button type="submit" color="gray" size="sm">Thêm đối thủ</x-filament::button>
                            <x-help text="Đối thủ trực tiếp: tên thương hiệu và tên miền (bỏ đuôi) tự thành từ cấm nhóm doi_thu, bài nhắc tên sẽ bị chặn bởi luật BAN01." />
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
                        <div class="ss-text-sm">Nhập tệp CSV khoảng trống <x-help title="Định dạng CSV"><p>Cột bắt buộc <code>keyword</code>; tùy chọn <code>competitor_domain</code>, <code>competitor_rank</code>, <code>our_rank</code>, <code>volume</code>. Tối đa {{ number_format($maxRows, 0, ',', '.') }} dòng và 1 MB, UTF-8.</p><p>Không có volume thì để trống cột; hệ thống không tự điền số và không chấm điểm ưu tiên cho dòng thiếu lượng tìm. Tên miền đối thủ phải đã được thêm ở tab Đối thủ.</p></x-help></div>
                        <x-upload-zone model="csvFile" name="csv" accept=".csv,text/csv,text/plain" :max-mb="1" hint="CSV · tối đa 1 MB" label="Kéo thả tệp CSV vào đây hoặc bấm để chọn" />
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

            </x-studio.tabs>
        @endif
    </div>
</x-filament-panels::page>
