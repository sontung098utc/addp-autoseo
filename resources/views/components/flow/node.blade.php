@props(['n' => null, 'label', 'desc' => null, 'state' => 'idle', 'kind' => 'rule', 'avatar' => null, 'who' => null, 'tier' => null, 'planned' => false, 'human' => false, 'selected' => false, 'tag' => 'button',
    'last' => false, 'link' => null, 'when' => null, 'loop' => null, 'stateLabel' => null, 'stateIcon' => null])
{{--
    Một bước của luồng, vẽ thành thẻ lớn: số bước, loại (luật/AI/người), biểu tượng trạng thái, tên, mô tả một dòng, avatar nhân sự, chỗ cho chip và số liệu (slot).
    state: done|running|warn|failed|blocked|stale|waiting|pending|idle|skipped. kind: rule|llm|human. link: done|live|dashed|null (đường nối sang bước kế; bỏ qua ở bước cuối).
    loop: nhãn vòng lặp (ví dụ "2 vòng"). Gắn wire:click / x-on:click / data-* qua attributes (rơi vào thẻ bấm được).
--}}
@php
    $cls = 'fl-node fl-node--'.($planned ? 'planned' : $state).($human ? ' fl-node--human' : '').($selected ? ' is-sel' : '');
    $icon = ['rule' => 'heroicon-m-cog-6-tooth', 'llm' => 'heroicon-m-sparkles', 'human' => 'heroicon-m-user'][$kind] ?? 'heroicon-m-cog-6-tooth';
    $kindText = ['rule' => 'Luật', 'llm' => 'AI', 'human' => 'Người'][$kind] ?? 'Luật';
@endphp
<div class="fl-cell" data-cell>
    <{{ $tag }} {{ $attributes->merge(['class' => $cls])->when($tag === 'button', fn ($a) => $a->merge(['type' => 'button'])) }}>
        <span class="fl-node__head">
            @if ($n !== null)<span class="fl-num">{{ $n }}</span>@endif
            <span class="fl-kind" @if ($tier) title="Mức model: {{ $tier }}" @endif><x-filament::icon :icon="$icon" class="fl-ico" data-kind="{{ $kind }}" :title="$kindText" />{{ $kindText }}</span>
            @if ($human && $kind !== 'human')<x-filament::icon icon="heroicon-m-user" class="fl-ico" data-kind="human" title="Người can thiệp" />@endif
            @if ($stateIcon)<x-filament::icon :icon="$stateIcon" class="fl-stico" :title="$stateLabel" />@endif
        </span>
        <span class="fl-name">{{ $label }}</span>
        @if ($desc)<span class="fl-desc" title="{{ $desc }}">{{ $desc }}</span>@endif
        <span class="fl-foot">
            @if ($avatar)<span class="fl-av" data-avatar title="{{ $who }}">{{ $avatar }}</span>@endif
            @if ($who)<span class="fl-who">{{ $who }}</span>@endif
            {{ $slot }}
        </span>
    </{{ $tag }}>
    @unless ($last)
        <span class="fl-link @if ($link === 'done') fl-link--done @elseif ($link === 'live') fl-link--live @endif @if ($link === 'dashed' || $when || $loop !== null) fl-link--dashed @endif @if ($when) fl-link--dot @endif @if ($loop !== null) fl-link--loop @endif"
            @if ($when) title="{{ $when }}" data-when @endif aria-hidden="true"></span>
    @endunless
    @if ($loop !== null)
        <span class="fl-loop" data-testid="flow-loop" aria-label="Vòng lặp Chấm và Sửa: {{ $loop }}">
            <svg viewBox="0 0 60 18" preserveAspectRatio="none"><path d="M2 17C2 -2 58 -2 58 15" fill="none" stroke="var(--fl-wait)" stroke-width="2.5" stroke-linecap="round"/><path d="M53 10l5 6 6-5" fill="none" stroke="var(--fl-wait)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" transform="translate(-4 0)"/></svg>
            <b data-loop>↻ {{ $loop }}</b>
        </span>
    @endif
</div>
