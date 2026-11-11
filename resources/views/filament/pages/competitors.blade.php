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
    @php
        $multiSite = count($websites) > 1;
        $hasDomains = count($domains) > 0;
        $selectedCount = count($selected);
    @endphp
    <div class="ss-page" data-co-state="{{ $state }}">
        @if (! $site)
            <div class="ss-alert ss-alert--warn">Chưa có website nào ở trạng thái Hoạt động. Hãy thêm và kích hoạt website trước.</div>
        @elseif ($state === 'chua-ket-noi')
            <div class="ss-card" data-co-state="chua-ket-noi"><div class="ss-card__b" style="text-align:center;padding:2rem 1rem">
                <strong>Chưa kết nối nguồn dữ liệu đối thủ</strong>
                <x-help title="Vì sao chưa có dữ liệu?">
                    <p>Cần tài khoản nhà cung cấp từ khóa trả phí, khóa API (lưu mã hóa) và hạn mức do quản trị đặt.</p>
                </x-help>
            </div></div>
        @elseif ($state === 'dang-tai' && ! $hasDomains)
            <div class="ss-alert ss-alert--info" data-co-state="dang-tai">Đang lấy dữ liệu từ nhà cung cấp; bảng hiện sau khi xong.</div>
        @elseif (! $hasDomains)
            @if ($multiSite)
                <div class="ss-toolbar">
                    <label for="co-site" class="ss-text-sm ss-muted">Website</label>
                    <select id="co-site" wire:model.live="websiteId">@foreach ($websites as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</select>
                </div>
            @endif
            <div class="ss-card" data-co-section="empty"><div class="ss-card__b" style="display:grid;gap:.75rem;justify-items:center;text-align:center;padding:2.5rem 1rem">
                <div style="font-weight:600">Chưa theo dõi đối thủ nào
                    <x-help title="Hệ thống làm gì với đối thủ?">
                        @include('filament.pages.competitors-rules', ['formula' => $formula, 'maxCompetitors' => $maxCompetitors])
                    </x-help>
                </div>
                <form wire:submit="addCompetitor" class="ss-toolbar" style="justify-content:center">
                    <input id="co-new" type="text" wire:model="newDomain" placeholder="doithu.vn" autocomplete="off" aria-label="Tên miền đối thủ">
                    <x-filament::button type="submit" color="primary" icon="heroicon-o-plus">Thêm đối thủ</x-filament::button>
                </form>
            </div></div>
        @else
            <div class="ss-toolbar">
                @if ($multiSite)
                    <label for="co-site" class="ss-text-sm ss-muted">Website</label>
                    <select id="co-site" wire:model.live="websiteId">@foreach ($websites as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</select>
                @endif
                <x-help title="Cách hoạt động, hạn mức và quy tắc">
                    @include('filament.pages.competitors-rules', ['formula' => $formula, 'maxCompetitors' => $maxCompetitors])
                </x-help>
                <span style="flex:1"></span>
                @if ($blockedReason)<x-studio.badge tone="warn" data-co-blocked>{{ $blockedReason }}</x-studio.badge>@endif
                {{ $this->refreshAction }}
                @if ($selectedCount > 0)
                    <x-filament::button wire:click="addToPlan" color="primary" icon="heroicon-o-plus" :disabled="! $canWrite" :title="! $canWrite ? 'Cần quyền tạo bài' : null">Thêm vào kế hoạch ({{ $selectedCount }})</x-filament::button>
                @endif
            </div>

            @if ($showGaps)
                <div class="ss-kpis">
                    <div class="ss-kpi"><div class="ss-kpi__l">Đối thủ theo dõi</div><div class="ss-kpi__v">{{ count($domains) }}</div></div>
                    <div class="ss-kpi"><div class="ss-kpi__l">Khoảng trống còn mở</div><div class="ss-kpi__v">{{ $openCount }}</div><div class="ss-kpi__d ss-muted">{{ $totalGaps - $openCount }} đã có trong kế hoạch</div></div>
                    <div class="ss-kpi"><div class="ss-kpi__l">Chi phí làm mới ước tính</div><div class="ss-kpi__v">~{{ $nf($estimate['cost_vnd']) }} ₫</div></div>
                </div>
            @endif

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

            @if ($state === 'dang-tai')
                <div class="ss-alert ss-alert--info" data-co-state="dang-tai">Đang lấy dữ liệu từ nhà cung cấp; bảng hiện sau khi xong.</div>
            @elseif ($state === 'loi-api' || $refreshError)
                <div class="ss-alert ss-alert--danger" data-co-state="loi-api">
                    <strong>Không lấy được dữ liệu.</strong> {{ $refreshError ?? 'Nhà cung cấp báo hết hạn mức hoặc không phản hồi. Bấm "Làm mới dữ liệu" để thử lại.' }}
                    @if ($fetchedLabel) Dữ liệu cũ{{ $staleDays ? ' '.$staleDays.' ngày' : '' }} (bản lưu {{ $fetchedLabel }}) không được dùng để thêm vào kế hoạch.@endif
                </div>
            @endif

            @php
                $tabs = [];
                if ($showGaps) { $tabs[] = ['key' => 'gaps', 'label' => 'Khoảng trống', 'count' => count($gapRows)]; }
                $tabs[] = ['key' => 'competitors', 'label' => 'Đối thủ', 'count' => count($domains)];
                $tabs[] = ['key' => 'source', 'label' => 'Nguồn và chi phí'];
                if ($showGaps) { $tabs[] = ['key' => 'clusters', 'label' => 'Cụm gợi ý', 'count' => count($clusterRows)]; }
            @endphp
            <x-studio.tabs persist="doi-thu" :default="$showGaps ? 'gaps' : 'competitors'" :tabs="$tabs">
                @if ($showGaps)
                <x-studio.panel key="gaps">
                    <div data-co-section="gaps">
                        <div class="ss-text-xs ss-muted" style="margin-bottom:.5rem">Dữ liệu lúc {{ $fetchedLabel }}@if($staleDays) ({{ $staleDays }} ngày trước)@endif</div>
                        <x-studio.data-table id="co-gaps" :columns="$gapColumns" :rows="$gapRows" :selectable="$canWrite" select-model="selected" :selected="$selected" :page-size="25" search="Tìm từ khóa" empty="Chưa có khoảng trống" />
                    </div>
                </x-studio.panel>
                @endif

                <x-studio.panel key="competitors">
                    <div class="ss-page">
                        <form wire:submit="addCompetitor" class="ss-toolbar">
                            <input id="co-new" type="text" wire:model="newDomain" placeholder="Thêm đối thủ: doithu.vn" autocomplete="off" aria-label="Tên miền đối thủ">
                            <x-filament::button type="submit" color="gray" size="sm">Thêm</x-filament::button>
                            <span class="ss-text-xs ss-muted">{{ count($domains) }}/{{ $maxCompetitors }}</span>
                        </form>
                        @unless ($showGaps)
                            <div class="ss-text-sm ss-muted" data-co-state="chua-lam-moi">Chưa lấy dữ liệu thứ hạng. Bấm "Làm mới dữ liệu" ở góc phải trên.</div>
                        @endunless
                        <x-studio.data-table id="co-domains" :columns="$showGaps ? [
                            ['key' => 'domain', 'label' => 'Tên miền', 'type' => 'text', 'sortable' => true],
                            ['key' => 'ranked', 'label' => 'Từ khóa xếp hạng', 'type' => 'number', 'sortable' => true],
                            ['key' => 'in_system', 'label' => 'Đã có trong hệ thống', 'type' => 'number', 'sortable' => true],
                            ['key' => 'overlap', 'label' => 'Mức trùng', 'type' => 'number', 'decimals' => 1, 'suffix' => '%', 'sortable' => true],
                            ['key' => 'gap', 'label' => 'Khoảng trống', 'type' => 'number', 'sortable' => true],
                            ['key' => 'act', 'label' => '', 'type' => 'actions', 'actions' => [['label' => 'Bỏ theo dõi', 'method' => 'removeCompetitor', 'tone' => 'danger']]],
                        ] : [
                            ['key' => 'domain', 'label' => 'Tên miền', 'type' => 'text', 'sortable' => true],
                            ['key' => 'act', 'label' => '', 'type' => 'actions', 'actions' => [['label' => 'Bỏ theo dõi', 'method' => 'removeCompetitor', 'tone' => 'danger']]],
                        ]" :rows="$domainRows" :page-size="10" search="Tìm tên miền" empty="Chưa có đối thủ nào" />
                    </div>
                </x-studio.panel>

                <x-studio.panel key="source">
                    <div class="ss-split">
                        <div class="ss-card" data-co-section="source"><div class="ss-card__h">Nguồn và hạn mức
                            <x-help title="Cách tính chi phí" text="Ước tính: {{ $nf($estimate['rows']) }} dòng × {{ $nf($vndPer1000) }} ₫ / 1.000 dòng (giá giả định). Mỗi lần làm mới đều hiện chi phí và cần xác nhận."/></div>
                            <div class="ss-card__b ss-text-sm" style="display:grid;gap:.375rem">
                            <div>Lần làm mới gần nhất: <strong>{{ $fetchedLabel ?? 'Chưa làm mới lần nào' }}</strong>@if($staleDays) ({{ $staleDays }} ngày trước)@endif</div>
                            <div>Chi phí ước tính lần này: <strong>~{{ $nf($estimate['cost_vnd']) }} ₫</strong></div>
                            <div>Hạn mức tháng: <strong>{{ $nf($used) }} / {{ $nf($monthlyLimit) }}</strong> dòng, còn {{ $nf($estimate['remaining']) }}</div>
                            @if ($blockedReason)<div><x-studio.badge tone="warn">{{ $blockedReason }}</x-studio.badge></div>@endif
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

                @if ($showGaps)
                <x-studio.panel key="clusters">
                    <div class="ss-text-xs ss-muted" style="margin-bottom:.5rem"><x-studio.badge tone="accent">AI đề xuất</x-studio.badge> Gom theo từ chung, chỉ đề xuất.</div>
                    <x-studio.data-table id="co-clusters" :columns="array_values(array_filter([
                        ['key' => 'name', 'label' => 'Cụm', 'type' => 'text', 'sortable' => true],
                        ['key' => 'count', 'label' => 'Số từ khóa', 'type' => 'number', 'sortable' => true],
                        ['key' => 'volume', 'label' => 'Tổng volume', 'type' => 'number', 'sortable' => true],
                        ['key' => 'keywords', 'label' => 'Từ khóa nguồn', 'type' => 'text'],
                        $canWrite ? ['key' => 'act', 'label' => '', 'type' => 'actions', 'actions' => [['label' => 'Chọn cả cụm', 'method' => 'selectCluster']]] : null,
                    ]))" :rows="$clusterRows" :page-size="10" search="Tìm cụm" empty="Chưa đủ từ khóa có từ chung để gom cụm" />
                </x-studio.panel>
                @endif
            </x-studio.tabs>
        @endif
    </div>
    <x-filament-actions::modals />
</x-filament-panels::page>
@endif
