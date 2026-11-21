@php
    /** @var \App\Filament\Resources\MediaAssetResource\Pages\ListMediaAssets $page */
    $asset = $page->viewing ? $page->viewingAsset() : null;
@endphp
@if ($asset)
    @php
        $folders = \App\Models\MediaCollection::treeOptions();
        $sites = \App\Models\Website::query()->orderBy('name')->pluck('name', 'id');
        $inUse = $asset->isUsedInPublication();
        $meta = [
            'Kích thước' => ($asset->width && $asset->height) ? $asset->width.' × '.$asset->height.' px' : '—',
            'Dung lượng' => $asset->human_file_size,
            'Định dạng' => $asset->mime_type,
            'Đang dùng' => $asset->article_versions_count > 0 ? $asset->article_versions_count.' phiên bản bài' : 'Chưa dùng',
            'Ngày tải' => $asset->created_at?->format('H:i d/m/Y'),
        ];
        $field = 'width:100%;padding:8px 10px;border-radius:8px;border:1px solid rgba(127,127,127,.35);background:transparent;color:inherit;font-size:13px';
        $label = 'display:block;font-size:12px;font-weight:600;margin:12px 0 4px;opacity:.8';
        $ibtn = 'display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:8px;border:1px solid rgba(127,127,127,.35);background:transparent;color:inherit;cursor:pointer';
    @endphp
    <style>
        .mt-viewer { position:fixed; inset:0; z-index:60; display:flex; background:rgba(8,10,14,.86); backdrop-filter:blur(3px) }
        .mt-viewer-stage { flex:1; min-width:0; position:relative; display:flex; align-items:center; justify-content:center; padding:32px 72px }
        .mt-viewer-stage img { max-width:100%; max-height:calc(100vh - 64px); object-fit:contain; border-radius:12px; box-shadow:0 12px 40px rgba(0,0,0,.5) }
        .mt-nav { position:absolute; top:50%; transform:translateY(-50%); width:44px; height:44px; border-radius:999px; border:0; background:rgba(17,24,39,.8); color:#fff; font-size:20px; cursor:pointer; display:flex; align-items:center; justify-content:center }
        .mt-nav:hover { background:rgba(17,24,39,1); outline:2px solid #fff }
        .mt-viewer-panel { width:400px; max-width:100%; background:#fff; color:#111827; display:flex; flex-direction:column; box-shadow:-8px 0 30px rgba(0,0,0,.35) }
        .dark .mt-viewer-panel { background:#111827; color:#f3f4f6 }
        .mt-viewer-panel .mt-scroll { flex:1; overflow:auto; padding:18px 20px 8px }
        .mt-viewer-panel select option { color:#111827 }
        .mt-viewer-panel input:focus, .mt-viewer-panel select:focus, .mt-viewer-panel textarea:focus { outline:2px solid #f59e0b; outline-offset:-1px }
        .mt-close { position:absolute; top:14px; right:14px; width:36px; height:36px; border-radius:999px; border:0; background:rgba(17,24,39,.8); color:#fff; cursor:pointer; font-size:18px }
        @media (max-width: 900px) { .mt-viewer { flex-direction:column; overflow:auto } .mt-viewer-stage { padding:56px 16px 16px; flex:none } .mt-viewer-panel { width:100%; flex:none } }
    </style>
    <div class="mt-viewer" role="dialog" aria-modal="true" aria-label="Xem ảnh"
         x-data x-on:keydown.window.escape="$wire.closeViewer()" x-on:keydown.window.arrow-left="if (!['INPUT','TEXTAREA','SELECT'].includes(document.activeElement.tagName)) $wire.viewerStep(-1)" x-on:keydown.window.arrow-right="if (!['INPUT','TEXTAREA','SELECT'].includes(document.activeElement.tagName)) $wire.viewerStep(1)">
        <div class="mt-viewer-stage" wire:click.self="closeViewer">
            <button type="button" class="mt-nav" style="left:18px" wire:click="viewerStep(-1)" aria-label="Ảnh trước"><x-filament::icon icon="heroicon-m-chevron-left" class="h-6 w-6" /></button>
            <img src="{{ $asset->variantUrl('content') }}" alt="{{ $asset->alt_text }}" wire:key="viewer-img-{{ $asset->id }}">
            <button type="button" class="mt-nav" style="right:18px" wire:click="viewerStep(1)" aria-label="Ảnh sau"><x-filament::icon icon="heroicon-m-chevron-right" class="h-6 w-6" /></button>
        </div>
        <aside class="mt-viewer-panel" wire:key="viewer-panel-{{ $asset->id }}">
            <button type="button" class="mt-close" wire:click="closeViewer" aria-label="Đóng"><x-filament::icon icon="heroicon-m-x-mark" class="h-5 w-5" style="margin:auto" /></button>
            <div class="mt-scroll">
                <h2 style="font-size:16px;font-weight:700;margin:0 40px 12px 0;word-break:break-word">{{ $asset->title ?: '(không tiêu đề)' }}</h2>
                @php
                    $sug = (array) ($asset->metadata['suggestion'] ?? []);
                    $sugKind = in_array($sug['kind'] ?? null, \App\Models\MediaAsset::KINDS, true) && $sug['kind'] !== $asset->kind ? $sug['kind'] : null;
                    $sugFolder = ! empty($sug['folder_id']) && (int) $sug['folder_id'] !== (int) $asset->folder_id ? \App\Models\MediaCollection::find($sug['folder_id']) : null;
                @endphp
                @if ($sugKind || $sugFolder)
                    <div style="margin:0 0 12px;padding:10px 12px;border-radius:10px;background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.45);font-size:12.5px">
                        <div style="font-weight:600;margin-bottom:4px">Gợi ý phân loại (chưa áp dụng)</div>
                        @if ($sugKind)<div>Loại: <strong>{{ \App\Models\MediaAsset::KIND_LABELS[$sugKind] }}</strong></div>@endif
                        @if ($sugFolder)<div>Thư mục: <strong>{{ $sugFolder->path_label }}</strong></div>@endif
                        @foreach ((array) ($sug['reasons'] ?? []) as $why)<div style="opacity:.7">• {{ $why }}</div>@endforeach
                        <div style="display:flex;gap:8px;margin-top:8px">
                            <button type="button" wire:click="applySuggestion" style="padding:4px 12px;border-radius:8px;border:0;background:#d97706;color:#fff;font-size:12px;font-weight:600;cursor:pointer">Áp dụng</button>
                            <button type="button" wire:click="dismissSuggestion" style="padding:4px 12px;border-radius:8px;border:1px solid rgba(127,127,127,.4);background:transparent;color:inherit;font-size:12px;cursor:pointer">Bỏ qua</button>
                        </div>
                    </div>
                @endif
                <dl style="margin:0;font-size:12.5px">
                    @foreach ($meta as $k => $v)
                        <div style="display:flex;justify-content:space-between;gap:12px;padding:5px 0;border-bottom:1px solid rgba(127,127,127,.18)">
                            <dt style="opacity:.6">{{ $k }}</dt><dd style="margin:0;text-align:right">{{ $v }}</dd>
                        </div>
                    @endforeach
                </dl>

                <label style="{{ $label }}">Tiêu đề</label>
                <input type="text" style="{{ $field }}" wire:model="edit.title" maxlength="255">
                <label style="{{ $label }}">Thẻ alt (văn bản thay thế)</label>
                <input type="text" style="{{ $field }}" wire:model="edit.alt_text" maxlength="255" placeholder="Mô tả ảnh cho SEO và người khiếm thị">
                @error('edit.alt_text')<p style="color:#dc2626;font-size:12px;margin:4px 0 0">{{ $message }}</p>@enderror
                <label style="{{ $label }}">Chú thích</label>
                <textarea rows="2" style="{{ $field }}" wire:model="edit.caption"></textarea>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                    <div>
                        <label style="{{ $label }}">Loại ảnh</label>
                        <select style="{{ $field }}" wire:model="edit.kind">
                            @foreach (\App\Models\MediaAsset::KIND_LABELS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label style="{{ $label }}">Trạng thái</label>
                        <select style="{{ $field }}" wire:model="edit.status">
                            @foreach (\App\Models\MediaAsset::STATUS_LABELS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                        </select>
                    </div>
                </div>
                <label style="{{ $label }}">Thư mục</label>
                <select style="{{ $field }}" wire:model="edit.folder_id">
                    <option value="">Chưa xếp thư mục</option>
                    @foreach ($folders as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
                <label style="{{ $label }}">Website</label>
                <select style="{{ $field }}" wire:model="edit.website_id">
                    <option value="">Dùng chung cho toàn công ty</option>
                    @foreach ($sites as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
                <label style="{{ $label }}">Thẻ (ngăn cách bằng dấu phẩy)</label>
                <input type="text" style="{{ $field }}" wire:model="edit.tags" placeholder="sofa, phòng khách">
                <label style="display:flex;align-items:center;gap:8px;margin:14px 0 4px;font-size:13px;cursor:pointer">
                    <input type="checkbox" wire:model="edit.is_featured"> Đánh dấu là ảnh đại diện nổi bật
                </label>
            </div>
            <div style="display:flex;align-items:center;gap:8px;padding:12px 20px;border-top:1px solid rgba(127,127,127,.25)">
                <button type="button" wire:click="saveViewer" wire:loading.attr="disabled" wire:target="saveViewer"
                        style="flex:1;height:36px;border-radius:8px;border:0;background:#d97706;color:#fff;font-weight:600;font-size:13px;cursor:pointer">
                    <span wire:loading.remove wire:target="saveViewer">Lưu thay đổi</span><span wire:loading wire:target="saveViewer">Đang lưu…</span>
                </button>
                <button type="button" style="{{ $ibtn }}" title="Tải về" wire:click="viewerDownload"><x-filament::icon icon="heroicon-o-arrow-down-tray" class="h-4 w-4" /></button>
                <button type="button" style="{{ $ibtn }}" title="Sao chép link" x-data="{ ok: false }" x-on:click="navigator.clipboard.writeText({{ json_encode($asset->url) }}); ok = true; setTimeout(() => ok = false, 1200)"><span x-show="! ok"><x-filament::icon icon="heroicon-o-link" class="h-4 w-4" /></span><span x-show="ok" x-cloak style="color:#059669"><x-filament::icon icon="heroicon-m-check" class="h-4 w-4" /></span></button>
                @if ($asset->status !== 'archived')
                    <button type="button" style="{{ $ibtn }}" title="Lưu trữ" wire:click="viewerArchive"><x-filament::icon icon="heroicon-o-archive-box" class="h-4 w-4" /></button>
                @endif
                @unless ($inUse)
                    <button type="button" style="{{ $ibtn }};color:#dc2626" title="Xóa ảnh" wire:click="viewerDelete" wire:confirm="Xóa ảnh này khỏi thư viện? Nên dùng Lưu trữ nếu không chắc."><x-filament::icon icon="heroicon-o-trash" class="h-4 w-4" /></button>
                @endunless
            </div>
        </aside>
    </div>
@endif
