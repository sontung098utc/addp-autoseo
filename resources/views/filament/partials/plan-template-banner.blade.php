{{-- Khung tải FILE MẪU kế hoạch. Dùng ở khung giới thiệu 'Nhập từ file' và ở hộp chọn file. --}}
<div data-testid="plan-template-banner" style="display:flex;gap:.9rem;align-items:center;flex-wrap:wrap;border:1px solid rgba(128,128,128,.3);border-left:4px solid #0f766e;background:rgba(15,118,110,.06);border-radius:.8rem;padding:.8rem 1rem;margin:.2rem 0 .8rem">
    <span style="display:inline-flex;align-items:center;justify-content:center;width:2.2rem;height:2.2rem;border-radius:.6rem;background:rgba(15,118,110,.14);color:#0f766e;flex:none"><x-filament::icon icon="heroicon-o-document-arrow-down" style="width:1.3rem;height:1.3rem" /></span>
    <div style="flex:1;min-width:14rem">
        <div style="font-weight:600;font-size:.95rem">Tải file mẫu về điền cho chuẩn</div>
        <div style="font-size:.84rem;opacity:.8;margin-top:.15rem">File mẫu có sẵn đúng tên cột, 2 dòng ví dụ và sheet hướng dẫn từng cột. Làm theo mẫu thì hệ thống nhận đúng cột ngay; bạn chỉ cần xem trước rồi tạo bài.</div>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <button type="button" wire:click="downloadPlanTemplate('xlsx')" wire:loading.attr="disabled" wire:target="downloadPlanTemplate" data-testid="plan-template-xlsx" style="display:inline-flex;align-items:center;gap:.35rem;background:#0f766e;color:#fff;border:0;border-radius:.6rem;padding:.5rem .9rem;font-weight:600;cursor:pointer;font-size:.85rem"><x-filament::icon icon="heroicon-m-arrow-down-tray" style="width:1rem;height:1rem" /> Mẫu Excel (.xlsx)</button>
        <button type="button" wire:click="downloadPlanTemplate('csv')" wire:loading.attr="disabled" wire:target="downloadPlanTemplate" data-testid="plan-template-csv" style="display:inline-flex;align-items:center;gap:.35rem;background:transparent;color:inherit;border:1px solid rgba(128,128,128,.45);border-radius:.6rem;padding:.5rem .9rem;font-weight:600;cursor:pointer;font-size:.85rem"><x-filament::icon icon="heroicon-m-arrow-down-tray" style="width:1rem;height:1rem" /> CSV</button>
    </div>
</div>
