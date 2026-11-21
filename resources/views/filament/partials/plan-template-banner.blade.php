{{-- Liên kết tải FILE MẪU kế hoạch (nút phụ, viền mảnh). Dùng cạnh nút "Chọn file kế hoạch" và ở hộp chọn file. --}}
@once
    <style>
        .lk-tpl { display: inline-flex; flex-wrap: wrap; align-items: center; gap: .45rem; color: var(--ss-text-muted, #64748b); font-size: .84rem; }
        .lk-tpl button { display: inline-flex; align-items: center; gap: .35rem; padding: .35rem .75rem; border: 1px solid var(--ss-border, rgba(128,128,128,.4)); border-radius: .6rem; background: transparent; color: var(--ss-text, inherit); font-weight: 600; font-size: .84rem; cursor: pointer; }
        .lk-tpl button:hover { border-color: var(--ss-accent-fg, #0f766e); color: var(--ss-accent-fg, #0f766e); }
        .lk-tpl svg { width: 1rem; height: 1rem; }
    </style>
@endonce
<div class="lk-tpl" data-testid="plan-template-banner" title="Mẫu Excel gồm 4 sheet: Bài viết (một dòng một bài: từ khóa phụ, ý định, đối tượng, giọng văn, độ dài, số bảng/ảnh, câu hỏi cần trả lời, nguồn, liên kết nội bộ, điều cần tránh, ngày, ưu tiên...), Chiến dịch, Hướng dẫn từng cột và Ví dụ đã điền">
    <span>Chưa có file? Tải mẫu:</span>
    <button type="button" wire:click="downloadPlanTemplate('xlsx')" wire:loading.attr="disabled" wire:target="downloadPlanTemplate" data-testid="plan-template-xlsx"><x-filament::icon icon="heroicon-m-arrow-down-tray" /> Excel (.xlsx)</button>
    <button type="button" wire:click="downloadPlanTemplate('csv')" wire:loading.attr="disabled" wire:target="downloadPlanTemplate" data-testid="plan-template-csv"><x-filament::icon icon="heroicon-m-arrow-down-tray" /> CSV</button>
</div>
