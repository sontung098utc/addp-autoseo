@if ($real)
    @include('filament.pages.competitors-real')
@else
@php
    $nf = fn ($n) => number_format($n, 0, ',', '.');
    $ready = $site && $state !== 'chua-ket-noi';
    $showGaps = $ready && $state === 'ket-noi' && ! $refreshError && count($domains) > 0 && $fetchedLabel;
@endphp
<x-filament-panels::page>
    <x-chain-context step="Khám phá" purpose="Tìm khoảng trống nội dung so với đối thủ." :inputs="[['label'=>'Từ khóa đối thủ','url'=>null,'note'=>'nhập CSV']]" :outputs="[['label'=>'Kho từ khóa (trang Từ khóa)','url'=>\App\Filament\Pages\KeywordsPage::getUrl(),'note'=>'từ khóa đối thủ có mà mình chưa có'],['label'=>'Kế hoạch nội dung','url'=>\App\Filament\Pages\ContentPlanPage::getUrl()]]" />
    <div class="ss-page" data-co-state="{{ $state }}">
        <div class="ss-toolbar">
            <label for="co-site" class="ss-text-sm ss-muted">Website</label>
            <select id="co-site" wire:model.live="websiteId">
                @foreach ($websites as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach
            </select>
            @if ($ready)
                {{ $this->refreshAction }}
                @if ($blockedReason)<x-studio.badge tone="danger" data-co-blocked>{{ $blockedReason }}</x-studio.badge>@endif
            @endif
            <span style="flex:1"></span>
            @if ($ready)
                <x-filament::button wire:click="addToPlan" color="primary" icon="heroicon-o-plus" :disabled="! $canWrite || count($selected) === 0" :title="! $canWrite ? 'Cần quyền tạo bài' : (count($selected) === 0 ? 'Chọn ít nhất một từ khóa ở tab Khoảng trống' : null)">Thêm vào kế hoạch ({{ count($selected) }})</x-filament::button>
            @endif
        </div>

        @if (! $site)
            <div class="ss-alert ss-alert--warn">Chưa có website nào ở trạng thái Hoạt động. Hãy thêm và kích hoạt website trước.</div>
        @elseif ($state === 'chua-ket-noi')
            <div class="ss-alert ss-alert--warn" data-co-state="chua-ket-noi">Chưa kết nối nguồn từ khóa trả phí nên chưa có dữ liệu đối thủ. Cần tài khoản nhà cung cấp, khóa API (lưu mã hóa) và hạn mức do quản trị đặt.</div>
        @else
            <div class="ss-kpis">
                <div class="ss-kpi"><div class="ss-kpi__l">Đối thủ theo dõi</div><div class="ss-kpi__v">{{ count($domains) }}</div><div class="ss-kpi__d ss-muted">tối đa {{ $maxCompetitors }}</div></div>
                <div class="ss-kpi"><div class="ss-kpi__l">Khoảng trống còn mở</div><div class="ss-kpi__v">{{ $showGaps ? $openCount : '—' }}</div><div class="ss-kpi__d ss-muted">{{ $showGaps ? ($totalGaps - $openCount).' đã có trong kế hoạch' : 'chưa có dữ liệu' }}</div></div>
                <div class="ss-kpi"><div class="ss-kpi__l">Chi phí làm mới ước tính</div><div class="ss-kpi__v">~{{ $nf($estimate['cost_vnd']) }} ₫</div><div class="ss-kpi__d ss-muted">còn {{ $nf($estimate['remaining']) }} dòng hạn mức</div></div>
            </div>

            @if (count($results))
                <div class="ss-card" data-co-section="results" role="status">
                    <div class="ss-card__h">Kết quả thêm vào kế hoạch</div>
                    <div class="ss-card__b ss-text-sm" style="display:grid;gap:.375rem">
                        @foreach ($results as $r)
                            <div><x-studio.badge :tone="$r['ok'] ? 'ok' : 'danger'">{{ $r['ok'] ? 'Đã thêm' : 'Không thêm' }}</x-studio.badge> {{ $r['keyword'] }}: {{ $r['message'] }}</div>
                        @endforeach
                    </div>
                </div>
            @endif

            <x-studio.tabs persist="doi-thu" :default="count($domains) === 0 ? 'competitors' : 'gaps'" :tabs="[
                ['key' => 'gaps', 'label' => 'Khoảng trống', 'count' => $showGaps ? count($gapRows) : null, 'tone' => ($state === 'loi-api' || $refreshError) ? 'danger' : null],
                ['key' => 'competitors', 'label' => 'Đối thủ', 'count' => count($domains)],
                ['key' => 'source', 'label' => 'Nguồn và chi phí'],
                ['key' => 'clusters', 'label' => 'Cụm gợi ý', 'count' => $showGaps ? count($clusterRows) : null],
                ['key' => 'rules', 'label' => 'Quy tắc'],
            ]">
                <x-studio.panel key="gaps">
                    @if ($state === 'dang-tai')
                        <div class="ss-alert ss-alert--info" data-co-state="dang-tai">Đang lấy dữ liệu từ nhà cung cấp; bảng hiện sau khi xong.</div>
                    @elseif ($state === 'loi-api' || $refreshError)
                        <div class="ss-alert ss-alert--danger" data-co-state="loi-api">
                            <strong>Không lấy được dữ liệu.</strong> {{ $refreshError ?? 'Nhà cung cấp báo hết hạn mức hoặc không phản hồi. Bấm "Làm mới dữ liệu" để thử lại.' }}
                            @if ($fetchedLabel) Dữ liệu cũ{{ $staleDays ? ' '.$staleDays.' ngày' : '' }} (bản lưu {{ $fetchedLabel }}) không được dùng để thêm vào kế hoạch.@endif
                        </div>
                    @elseif ($state === 'khong-doi-thu' || count($domains) === 0)
                        <div class="ss-alert ss-alert--warn">Chưa có đối thủ nào để so sánh. Thêm tên miền ở tab Đối thủ.</div>
                    @elseif (! $fetchedLabel)
                        <div class="ss-alert ss-alert--info" data-co-state="chua-lam-moi">Chưa có dữ liệu. Bấm "Làm mới dữ liệu" để lấy thứ hạng từ khóa của {{ count($domains) }} đối thủ.</div>
                    @else
                        <div data-co-section="gaps">
                            <div class="ss-text-xs ss-muted" style="margin-bottom:.5rem">Dữ liệu lúc {{ $fetchedLabel }}@if($staleDays) ({{ $staleDays }} ngày trước)@endif</div>
                            <x-studio.data-table id="co-gaps" :columns="$gapColumns" :rows="$gapRows" :selectable="$canWrite" select-model="selected" :selected="$selected" :page-size="25" search="Tìm từ khóa" empty="Chưa có khoảng trống" />
                        </div>
                    @endif
                </x-studio.panel>

                <x-studio.panel key="competitors">
                    <div class="ss-page">
                        <form wire:submit="addCompetitor" class="ss-toolbar">
                            <label for="co-new" class="ss-text-sm ss-muted">Thêm đối thủ</label>
                            <input id="co-new" type="text" wire:model="newDomain" placeholder="doithu.vn" autocomplete="off">
                            <x-filament::button type="submit" color="gray" size="sm">Thêm tên miền</x-filament::button>
                        </form>
                        <x-studio.data-table id="co-domains" :columns="[
                            ['key' => 'domain', 'label' => 'Tên miền', 'type' => 'text', 'sortable' => true],
                            ['key' => 'ranked', 'label' => 'Từ khóa xếp hạng', 'type' => 'number', 'sortable' => true],
                            ['key' => 'in_system', 'label' => 'Đã có trong hệ thống', 'type' => 'number', 'sortable' => true],
                            ['key' => 'overlap', 'label' => 'Mức trùng', 'type' => 'number', 'decimals' => 1, 'suffix' => '%', 'sortable' => true],
                            ['key' => 'gap', 'label' => 'Khoảng trống', 'type' => 'number', 'sortable' => true],
                            ['key' => 'act', 'label' => '', 'type' => 'actions', 'actions' => [['label' => 'Bỏ theo dõi', 'method' => 'removeCompetitor', 'tone' => 'danger']]],
                        ]" :rows="$domainRows" :page-size="10" search="Tìm tên miền" empty="Chưa có đối thủ nào" />
                    </div>
                </x-studio.panel>

                <x-studio.panel key="source">
                    <div class="ss-split">
                        <div class="ss-card" data-co-section="source"><div class="ss-card__h">Nguồn và hạn mức</div><div class="ss-card__b ss-text-sm" style="display:grid;gap:.375rem">
                            <div>Lần làm mới gần nhất: <strong>{{ $fetchedLabel ?? 'Chưa làm mới lần nào' }}</strong>@if($staleDays) ({{ $staleDays }} ngày trước)@endif</div>
                            <div>Chi phí ước tính lần này: <strong>~{{ $nf($estimate['cost_vnd']) }} ₫</strong> <span class="ss-muted">(ước tính: {{ $nf($estimate['rows']) }} dòng × {{ $nf($vndPer1000) }} ₫ / 1.000 dòng, giá giả định)</span></div>
                            <div>Hạn mức tháng: <strong>{{ $nf($used) }} / {{ $nf($monthlyLimit) }}</strong> dòng, còn {{ $nf($estimate['remaining']) }}</div>
                            @if ($blockedReason)<div><x-studio.badge tone="danger">{{ $blockedReason }}</x-studio.badge></div>@endif
                        </div></div>
                        <div>
                            <x-studio.data-table id="co-log" :columns="[
                                ['key' => 'at', 'label' => 'Thời điểm', 'type' => 'text', 'sortable' => true],
                                ['key' => 'by', 'label' => 'Người bấm', 'type' => 'text', 'sortable' => true],
                                ['key' => 'website', 'label' => 'Website', 'type' => 'text', 'sortable' => true, 'visible' => false],
                                ['key' => 'rows', 'label' => 'Dòng', 'type' => 'number', 'sortable' => true],
                                ['key' => 'cost', 'label' => 'Chi phí ước tính (₫)', 'type' => 'number', 'sortable' => true],
                            ]" :rows="$logRows" :page-size="10" search="Tìm trong lịch sử" empty="Chưa có lần làm mới nào" />
                        </div>
                    </div>
                </x-studio.panel>

                <x-studio.panel key="clusters">
                    @if ($showGaps)
                        <div class="ss-text-xs ss-muted" style="margin-bottom:.5rem"><x-studio.badge tone="accent">AI đề xuất</x-studio.badge> Gom theo từ chung, chỉ đề xuất.</div>
                        <x-studio.data-table id="co-clusters" :columns="array_values(array_filter([
                            ['key' => 'name', 'label' => 'Cụm', 'type' => 'text', 'sortable' => true],
                            ['key' => 'count', 'label' => 'Số từ khóa', 'type' => 'number', 'sortable' => true],
                            ['key' => 'volume', 'label' => 'Tổng volume', 'type' => 'number', 'sortable' => true],
                            ['key' => 'keywords', 'label' => 'Từ khóa nguồn', 'type' => 'text'],
                            $canWrite ? ['key' => 'act', 'label' => '', 'type' => 'actions', 'actions' => [['label' => 'Chọn cả cụm', 'method' => 'selectCluster']]] : null,
                        ]))" :rows="$clusterRows" :page-size="10" search="Tìm cụm" empty="Chưa đủ từ khóa có từ chung để gom cụm" />
                    @else
                        <div class="ss-alert ss-alert--info">Chưa có dữ liệu khoảng trống để gom cụm.</div>
                    @endif
                </x-studio.panel>

                <x-studio.panel key="rules">
                    @include('filament.pages.competitors-rules', ['formula' => $formula, 'maxCompetitors' => $maxCompetitors])
                </x-studio.panel>
            </x-studio.tabs>
        @endif
    </div>
    <x-filament-actions::modals />
</x-filament-panels::page>
@endif
