@php
    /** @var \App\Filament\Resources\MediaAssetResource\Pages\ListMediaAssets $page */
    $tree = \App\Models\MediaCollection::tree();
    $pathIds = array_column($page->crumbs(), 'id');
    $currentId = $page->currentFolder()?->id;
    $rootActive = $page->folder === null;
    $totalAssets = \App\Models\MediaAsset::count();
    $dropAttr = fn ($id) => "x-data=\"{ over: false }\" x-on:dragover.prevent=\"over = true\" x-on:dragleave=\"over = false\" x-on:drop.prevent=\"over = false; \$wire.dropOn(event.dataTransfer.getData('text/plain'), ".($id === null ? 'null' : (int) $id).")\" x-bind:style=\"over ? 'outline:2px solid #f59e0b;outline-offset:-2px;border-radius:8px' : ''\"";
@endphp
<style>
    .mt-tree { margin-top:3.7rem; border:1px solid rgba(127,127,127,.25); border-radius:12px; padding:10px 8px; position:sticky; top:84px; max-height:calc(100vh - 100px); overflow:auto; font-size:13px }
    .mt-tree-title { font-size:11px; letter-spacing:.04em; text-transform:uppercase; opacity:.55; padding:2px 8px 8px }
    .mt-node { display:flex; align-items:center; gap:4px; padding:5px 6px; border-radius:8px; cursor:pointer; user-select:none }
    .mt-node:hover { background:rgba(127,127,127,.14) }
    .mt-node.is-active { background:rgba(245,158,11,.18); font-weight:600 }
    .mt-node .mt-count { margin-left:auto; font-size:11px; opacity:.55 }
    .mt-node .mt-add { opacity:0; border:0; background:transparent; color:inherit; cursor:pointer; padding:0 4px; font-size:15px; line-height:1 }
    .mt-node:hover .mt-add { opacity:.8 }
    .mt-caret { width:16px; text-align:center; opacity:.6; transition:transform .12s; flex:none }
    .mt-caret.open { transform:rotate(90deg) }
    .mt-children { margin-left:14px; border-left:1px dashed rgba(127,127,127,.3); padding-left:2px }
</style>
<aside class="mt-tree" aria-label="Cây thư mục">
    <div class="mt-tree-title">Thư mục</div>
    <div class="mt-node {{ $rootActive ? 'is-active' : '' }}" data-ctx="root" wire:click="openFolder(null)" {!! $dropAttr(null) !!}>
        <span class="mt-caret"></span>
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 11l9-8 9 8M5 10v10h14V10"/></svg>
        <span>Thư viện ảnh</span>
        <button type="button" class="mt-add" title="Thư mục mới ở gốc" wire:click.stop="mountAction('newFolder', { parent: null })"><x-filament::icon icon="heroicon-m-plus" class="h-3.5 w-3.5" /></button>
        <span class="mt-count">{{ $totalAssets }}</span>
    </div>
    @foreach ($tree as $node)
        @include('filament.media.folder-tree-node', ['node' => $node, 'pathIds' => $pathIds, 'currentId' => $currentId, 'dropAttr' => $dropAttr])
    @endforeach
    <div class="mt-node {{ $page->folder === 'all' ? 'is-active' : '' }}" wire:click="openFolder('all')" style="margin-top:8px;border-top:1px solid rgba(127,127,127,.2);border-radius:0 0 8px 8px">
        <span class="mt-caret"></span>
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        <span>Tất cả ảnh</span>
        <span class="mt-count">{{ $totalAssets }}</span>
    </div>
</aside>
