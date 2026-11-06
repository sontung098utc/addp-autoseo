<x-filament-panels::page>
    @php $t = $this->sampleTable(); @endphp
    <div class="ss-page">
        <x-studio.tabs persist="ui-kit" :tabs="[
            ['key' => 'colors', 'label' => 'Màu và chữ'],
            ['key' => 'table', 'label' => 'Bảng dữ liệu', 'count' => count($t['rows'])],
            ['key' => 'tabs', 'label' => 'Tab ngang', 'tone' => 'warn'],
        ]">
            <x-studio.panel key="colors">
                <div class="ss-kpis">
                    <div class="ss-kpi"><div class="ss-kpi__l">Clicks (28 ngày)</div><div class="ss-kpi__v">2.133</div><div class="ss-kpi__d"><x-studio.delta :value="-14" unit="%" good="up" /> so với kỳ trước</div></div>
                    <div class="ss-kpi"><div class="ss-kpi__l">Impressions</div><div class="ss-kpi__v">44.380</div><div class="ss-kpi__d"><x-studio.delta :value="2.2" unit="%" good="up" /></div></div>
                    <div class="ss-kpi"><div class="ss-kpi__l">Vị trí trung bình</div><div class="ss-kpi__v">8,8</div><div class="ss-kpi__d"><x-studio.delta :value="0.6" good="down" /> (số nhỏ hơn là tốt)</div></div>
                    <div class="ss-kpi"><div class="ss-kpi__l">Lỗi P0</div><div class="ss-kpi__v">3</div><div class="ss-kpi__d"><x-studio.delta :value="0" good="down" /></div></div>
                </div>
                <div class="ss-card" style="margin-top:1rem"><div class="ss-card__h">Huy hiệu trạng thái</div><div class="ss-card__b ss-toolbar">
                    <x-studio.badge tone="ok">Đã đăng</x-studio.badge><x-studio.badge tone="warn">Chờ duyệt</x-studio.badge><x-studio.badge tone="danger">Lỗi</x-studio.badge>
                    <x-studio.badge tone="info">Đang viết</x-studio.badge><x-studio.badge tone="accent">Đã xếp lịch</x-studio.badge><x-studio.badge tone="muted">Chưa có</x-studio.badge>
                </div></div>
                <div class="ss-card" style="margin-top:1rem"><div class="ss-card__h">Cảnh báo</div><div class="ss-card__b" style="display:grid;gap:.5rem">
                    <div class="ss-alert ss-alert--danger">Có 3 nhóm lỗi P0 cần xử lý trước khi xuất bản.</div>
                    <div class="ss-alert ss-alert--warn">1 bài vượt ngưỡng chi phí.</div>
                    <div class="ss-alert ss-alert--ok">Đã đồng bộ dữ liệu mới nhất.</div>
                    <div class="ss-alert ss-alert--info">Kỳ so sánh kết thúc ở ngày có dữ liệu cuối.</div>
                </div></div>
            </x-studio.panel>
            <x-studio.panel key="table">
                <x-studio.data-table id="ui-kit-sample" :columns="$t['columns']" :rows="$t['rows']" :page-size="10" search="Tìm truy vấn" />
            </x-studio.panel>
            <x-studio.panel key="tabs">
                <div class="ss-card"><div class="ss-card__b">Tab đổi nhanh (không tải lại trang), nhớ tab đang chọn trong phiên, điều hướng bằng phím ← → Home End, tab có chấm màu khi cần chú ý.</div></div>
            </x-studio.panel>
        </x-studio.tabs>
    </div>
</x-filament-panels::page>
