@php
    $isOpenInit = in_array($node['id'], $pathIds, true) && $node['id'] !== $currentId ? true : ($node['id'] === $currentId);
@endphp
<div x-data="{ open: {{ $isOpenInit ? 'true' : 'false' }} }" wire:key="tree-{{ $node['id'] }}">
    <div class="mt-node {{ $currentId === $node['id'] ? 'is-active' : '' }}" data-ctx="folder" data-id="{{ $node['id'] }}" wire:click="openFolder({{ $node['id'] }})" draggable="true"
         x-on:dragstart.stop="event.dataTransfer.setData('text/plain', 'folder:{{ $node['id'] }}'); event.dataTransfer.effectAllowed = 'move'"
         {!! $dropAttr($node['id']) !!}>
        <span class="mt-caret" :class="open ? 'open' : ''" x-on:click.stop="open = ! open">@if ($node['children'])<x-filament::icon icon="heroicon-m-chevron-right" class="h-3.5 w-3.5" />@endif</span>
        <svg viewBox="0 0 24 24" width="16" height="16" fill="#f59e0b"><path d="M3 6a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6z"/></svg>
        <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="{{ $node['name'] }}">{{ $node['name'] }}</span>
        <button type="button" class="mt-add" title="Thư mục con mới" wire:click.stop="mountAction('newFolder', { parent: {{ $node['id'] }} })"><x-filament::icon icon="heroicon-m-plus" class="h-3.5 w-3.5" /></button>
        <span class="mt-count">{{ $node['total'] }}</span>
    </div>
    @if ($node['children'])
        <div class="mt-children" x-show="open" x-cloak>
            @foreach ($node['children'] as $child)
                @include('filament.media.folder-tree-node', ['node' => $child, 'pathIds' => $pathIds, 'currentId' => $currentId, 'dropAttr' => $dropAttr])
            @endforeach
        </div>
    @endif
</div>
