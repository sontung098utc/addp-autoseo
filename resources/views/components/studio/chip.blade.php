{{-- AG-U01: nhãn trạng thái trung thực. domain: readiness | source | article | publication | website. --}}
@props(['domain' => 'readiness', 'value' => null])
@php
    $tones = ['success' => 'ok', 'warning' => 'warn', 'danger' => 'danger', 'info' => 'info', 'gray' => 'muted'];
    $tone = $tones[\App\Filament\Support\StatusPresenter::color($value, $domain)] ?? 'muted';
    $hint = \App\Filament\Support\StatusPresenter::hint($value, $domain);
@endphp
<x-studio.badge :tone="$tone" :title="$hint" {{ $attributes }}>{{ \App\Filament\Support\StatusPresenter::label($value, $domain) }}</x-studio.badge>
