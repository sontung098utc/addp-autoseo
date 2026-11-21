@php $page = app('mt.media.page'); @endphp
<div style="display:flex;align-items:center;gap:8px">
    <x-filament::input.wrapper style="min-width:150px">
        <x-filament::input.select wire:model.live="activeTab" aria-label="Lọc theo trạng thái">
            @foreach ($page->getTabs() as $key => $tab)
                <option value="{{ $key }}">{{ $tab->getLabel() }} ({{ $tab->getBadge() }})</option>
            @endforeach
        </x-filament::input.select>
    </x-filament::input.wrapper>
    <x-filament::input.wrapper style="min-width:150px">
        <x-filament::input.select wire:model.live="sortMode" aria-label="Sắp xếp">
            <option value="newest">Mới nhất</option>
            <option value="oldest">Cũ nhất</option>
            <option value="name">Tên A → Z</option>
            <option value="size">Dung lượng lớn nhất</option>
        </x-filament::input.select>
    </x-filament::input.wrapper>
    @if ($page->canCreateFolderHere())
        <x-filament::button color="gray" size="sm" icon="heroicon-o-folder-plus" wire:click="mountAction('newFolder')">Thư mục mới</x-filament::button>
    @endif
</div>
