@props(['n' => null, 'label', 'state' => 'idle', 'kind' => 'rule', 'avatar' => null, 'who' => null, 'tier' => null, 'planned' => false, 'human' => false, 'selected' => false, 'tag' => 'button'])
{{--
    Một nút của luồng: số bước nhỏ, tên đậm, một dòng phụ (⚙ luật / ✨ AI / 👤 người + avatar), chỗ cho trạng thái và số liệu (slot).
    state: done|running|warn|failed|blocked|stale|waiting|pending|idle|skipped. kind: rule|llm|human. Gắn wire:click / x-on:click / data-* qua attributes.
--}}
@php
    $cls = 'fl-node fl-node--'.($planned ? 'planned' : $state).($human ? ' fl-node--human' : '').($selected ? ' is-sel' : '');
    $icon = ['rule' => 'heroicon-m-cog-6-tooth', 'llm' => 'heroicon-m-sparkles', 'human' => 'heroicon-m-user'][$kind] ?? 'heroicon-m-cog-6-tooth';
    $kindText = ['rule' => 'Luật', 'llm' => 'AI', 'human' => 'Người'][$kind] ?? 'Luật';
@endphp
<{{ $tag }} {{ $attributes->merge(['class' => $cls])->when($tag === 'button', fn ($a) => $a->merge(['type' => 'button'])) }}>
    <span class="fl-node__top">@if ($n !== null)<span class="fl-num">{{ $n }}</span>@endif<span class="fl-name">{{ $label }}</span></span>
    <span class="fl-sub" @if ($tier) title="Mức model: {{ $tier }}" @endif>
        <x-filament::icon :icon="$icon" class="fl-ico" data-kind="{{ $kind }}" :title="$kindText" />
        @if ($human && $kind !== 'human')<x-filament::icon icon="heroicon-m-user" class="fl-ico" data-kind="human" title="Người can thiệp" />@endif
        @if ($avatar)<span class="fl-av" data-avatar title="{{ $who }}">{{ $avatar }}</span>@endif
    </span>
    {{ $slot }}
</{{ $tag }}>
