@props(['tone' => 'muted'])
@php $tone = in_array($tone, ['ok', 'warn', 'danger', 'info', 'muted', 'accent'], true) ? $tone : 'muted'; @endphp
<span {{ $attributes->class(['ss-badge', 'ss-badge--'.$tone]) }}>{{ $slot }}</span>
