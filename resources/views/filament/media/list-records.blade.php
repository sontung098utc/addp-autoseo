<x-filament-panels::page
    @class([
        'fi-resource-list-records-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
        'mt-media-page',
    ])
>
    <style>
        /* Thanh sắp xếp/chọn tất cả mặc định của Filament thừa một hàng: đã có ô "Sắp xếp" ở thanh thư mục */
        .mt-media-page .fi-ta-content > div.bg-gray-50:first-child { display: none; }
        /* Bỏ khung lồng nhau: bảng/lưới ảnh nằm phẳng trong trang */
        .mt-media-page .fi-ta-ctn { box-shadow: none; background: transparent; --tw-ring-shadow: 0 0 #0000; border: 0; }
        .mt-media-page .fi-ta-header-toolbar { padding-inline: 0; }
        .mt-media-page .fi-ta-content-grid { padding-inline: 0 !important; }
        .mt-explorer { display: grid; grid-template-columns: 250px minmax(0, 1fr); gap: 20px; align-items: start; }
        @media (max-width: 900px) { .mt-explorer { grid-template-columns: minmax(0, 1fr); } }
    </style>
    <div class="mt-explorer" x-data="{
            open: false, x: 0, y: 0, type: null, id: null, url: '', locked: false, archived: false,
            onMenu(e) {
                const el = e.target.closest('[data-ctx]');
                if (! el) { return; }
                e.preventDefault();
                this.type = el.dataset.ctx; this.id = el.dataset.id ? parseInt(el.dataset.id) : null;
                this.url = el.dataset.url || ''; this.locked = el.dataset.locked === '1'; this.archived = el.dataset.archived === '1';
                this.x = Math.min(e.clientX, window.innerWidth - 240); this.y = Math.min(e.clientY, window.innerHeight - 340);
                this.open = true;
            },
            run(fn) { this.open = false; fn(); },
        }"
        x-on:contextmenu="onMenu($event)" x-on:click.window="open = false" x-on:keydown.escape.window="open = false" x-on:scroll.window="open = false">
        @include('filament.media.context-menu', ['page' => $this])
        @include('filament.media.folder-tree', ['page' => $this])

        <div class="flex flex-col gap-y-6" style="min-width:0" data-ctx="here">
            {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE, scopes: $this->getRenderHookScopes()) }}

            {{ $this->table }}

            {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER, scopes: $this->getRenderHookScopes()) }}
        </div>
    </div>
    @include('filament.media.viewer', ['page' => $this])
</x-filament-panels::page>
