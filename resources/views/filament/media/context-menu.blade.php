@php
    /** Menu chuột phải cho ảnh, thư mục (trong cây) và vùng trống. Mọi thao tác dùng lại các action sẵn có. */
    $items = [
        'asset' => [
            ['Xem và chỉnh sửa', 'heroicon-o-pencil-square', '$wire.openViewer(id)'],
            ['Tải về', 'heroicon-o-arrow-down-tray', "\$wire.mountTableAction('download', String(id))"],
            ['Sao chép link', 'heroicon-o-link', 'navigator.clipboard.writeText(url)'],
            ['Đổi tên', 'heroicon-o-pencil', "\$wire.mountTableAction('rename', String(id))"],
            ['Đổi loại ảnh', 'heroicon-o-tag', "\$wire.mountTableAction('change_kind', String(id))"],
            ['Lưu trữ', 'heroicon-o-archive-box', "\$wire.mountTableAction('archive', String(id))", 'x-show' => '! archived'],
            ['Xóa', 'heroicon-o-trash', "\$wire.mountTableAction('delete', String(id))", 'danger' => true, 'x-show' => '! locked'],
        ],
        'folder' => [
            ['Mở thư mục', 'heroicon-o-folder-open', '$wire.openFolder(String(id))'],
            ['Tải ảnh lên vào thư mục', 'heroicon-o-arrow-up-tray', "\$wire.mountAction('upload', { folder: id })"],
            ['Thư mục con mới', 'heroicon-o-folder-plus', "\$wire.mountAction('newFolder', { parent: id })"],
            ['Đổi tên', 'heroicon-o-pencil', "\$wire.mountAction('renameFolder', { id: id })"],
            ['Di chuyển', 'heroicon-o-arrows-right-left', "\$wire.mountAction('moveFolder', { id: id })"],
            ['Xóa thư mục', 'heroicon-o-trash', "\$wire.mountAction('deleteFolder', { id: id })", 'danger' => true],
        ],
        'root' => [
            ['Tải ảnh lên vào gốc', 'heroicon-o-arrow-up-tray', "\$wire.mountAction('upload', { folder: null })"],
            ['Thư mục mới', 'heroicon-o-folder-plus', "\$wire.mountAction('newFolder', { parent: null })"],
        ],
        'here' => array_merge(
            [['Tải ảnh lên vào đây', 'heroicon-o-arrow-up-tray', "\$wire.mountAction('upload')"]],
            $page->canCreateFolderHere() ? [['Thư mục mới ở đây', 'heroicon-o-folder-plus', "\$wire.mountAction('newFolder')"]] : [],
        ),
    ];
@endphp
<style>
    .mt-ctx { position:fixed; z-index:70; min-width:210px; padding:6px; border-radius:12px; background:#fff; color:#111827; border:1px solid rgba(127,127,127,.25); box-shadow:0 12px 32px rgba(0,0,0,.22) }
    .dark .mt-ctx { background:#1f2937; color:#f3f4f6 }
    .mt-ctx button { display:flex; align-items:center; gap:10px; width:100%; padding:7px 10px; border:0; background:transparent; color:inherit; font-size:13px; border-radius:8px; cursor:pointer; text-align:left }
    .mt-ctx button:hover { background:rgba(127,127,127,.15) }
    .mt-ctx button.is-danger { color:#dc2626 }
    .mt-ctx button svg { opacity:.75; flex:none }
</style>
<div class="mt-ctx" x-show="open" x-cloak x-transition.opacity.duration.100ms x-bind:style="`left:${x}px;top:${y}px`" x-on:click.stop role="menu">
    @foreach ($items as $type => $list)
        @foreach ($list as $it)
            @php $extra = $it['x-show'] ?? null; @endphp
            <button type="button" role="menuitem" @class(['is-danger' => $it['danger'] ?? false])
                    x-show="type === '{{ $type }}'{!! $extra ? " && ($extra)" : '' !!}"
                    x-on:click="run(() => { {!! $it[2] !!} })">
                <x-filament::icon :icon="$it[1]" class="h-4 w-4" /> {{ $it[0] }}
            </button>
        @endforeach
    @endforeach
</div>
