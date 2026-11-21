@php
    /** @var \App\Models\MediaAsset $asset */
    $asset = $getRecord();
    $kind = \App\Models\MediaAsset::KIND_LABELS[$asset->kind] ?? $asset->kind;
    $used = (int) ($asset->article_versions_count ?? 0);
    $statusLabel = ['inbox' => 'Chờ phân loại', 'archived' => 'Đã lưu trữ'][$asset->status] ?? null;
    $statusBg = $asset->status === 'archived' ? 'rgba(75,85,99,.92)' : 'rgba(217,119,6,.95)';
    $chip = 'position:absolute;display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600;line-height:1.5;color:#fff;backdrop-filter:blur(4px)';
@endphp
@once
<style>
    .fi-ta-content-grid .fi-ta-record { position: relative; overflow: hidden; }
    /* Ô chọn: tròn, nổi bật trên ảnh (viền trắng + bóng); hiện khi rê chuột, hoặc luôn hiện khi đã chọn ảnh nào đó */
    .fi-ta-content-grid .fi-ta-record .fi-ta-record-checkbox { position: absolute; top: 10px; left: 10px; z-index: 3; margin: 0; width: 24px; height: 24px; border-radius: 999px; background-color: #fff; border: 2px solid #fff; box-shadow: 0 0 0 1.5px rgba(17,24,39,.55), 0 2px 8px rgba(0,0,0,.4); opacity: 0; transition: opacity .15s, background-color .15s; cursor: pointer; }
    .fi-ta-content-grid .fi-ta-record:hover .fi-ta-record-checkbox, .fi-ta-content-grid:has(.fi-ta-record-checkbox:checked) .fi-ta-record-checkbox { opacity: 1; }
    .fi-ta-content-grid .fi-ta-record .fi-ta-record-checkbox:checked { opacity: 1; background-color: #d97706 !important; border-color: #fff; box-shadow: 0 0 0 1.5px #d97706, 0 2px 8px rgba(0,0,0,.4); }
    /* Nút ba chấm: nổi ở góc trên phải ảnh, hiện khi rê chuột (thao tác nhanh: xem/sửa, tải về, đổi tên, lưu trữ, xóa) */
    .fi-ta-content-grid .fi-ta-record .fi-ta-actions { position: absolute; top: 8px; right: 8px; z-index: 3; padding: 0 !important; opacity: 0; transition: opacity .15s; }
    .fi-ta-content-grid .fi-ta-record:hover .fi-ta-actions, .fi-ta-content-grid .fi-ta-record .fi-ta-actions:focus-within { opacity: 1; }
    .fi-ta-content-grid .fi-ta-record .fi-ta-actions > .fi-dropdown > .fi-dropdown-trigger button { background: rgba(17,24,39,.78); color: #fff; border-radius: 8px; }
    /* Bỏ toàn bộ đệm/khoảng cách mặc định của Filament quanh ô: ảnh sát mép thẻ, không thừa chỗ phía dưới */
    .fi-ta-content-grid .fi-ta-record > div.flex { padding: 0 !important; }
    .fi-ta-content-grid .fi-ta-record > div.flex > div.flex-col { padding: 0 !important; gap: 0 !important; }
    .fi-ta-content-grid .fi-ta-record .block.w-full.ps-2 { padding: 0 !important; }
</style>
@endonce
<div data-ctx="asset" data-id="{{ $asset->id }}" data-url="{{ $asset->url }}" data-locked="{{ $asset->in_publication ? 1 : 0 }}" data-archived="{{ $asset->status === 'archived' ? 1 : 0 }}" wire:click="openViewer({{ $asset->id }})" draggable="true" x-on:dragstart.stop="event.dataTransfer.setData('text/plain', 'asset:{{ $asset->id }}'); event.dataTransfer.effectAllowed = 'move'" style="min-width:0;cursor:grab">
    <div style="position:relative;aspect-ratio:4/3;overflow:hidden;border-radius:10px 10px 0 0;background:rgba(127,127,127,.15)">
        <img src="{{ $asset->thumbnail_url }}" alt="{{ $asset->alt_text }}" loading="lazy" decoding="async" style="width:100%;height:100%;object-fit:cover;display:block">
        @if ($statusLabel)
            <span style="{{ $chip }};bottom:8px;left:8px;background:{{ $statusBg }}">{{ $statusLabel }}</span>
        @endif
    </div>
    <div style="padding:9px 12px 11px;min-width:0">
        <div style="font-weight:600;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="{{ $asset->title }}">{{ $asset->title ?: '(không tiêu đề)' }}</div>
        <div style="font-size:11.5px;opacity:.65;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px">
            {{ $kind }} · {{ $asset->created_at?->diffForHumans() }}
        </div>
    </div>
</div>
