@props(['brand' => 'custom', 'size' => 2])
{{--
    Biểu tượng thương hiệu ĐƠN GIẢN HÓA, SVG nội tuyến (không dùng logo gốc): nền tròn nhẹ + ký hiệu một nét.
    brand: google | anthropic | openai | openrouter | custom (khóa `logo` của preset trong config/ai_models.php).
--}}
@php
    $map = [
        'google' => ['#4285F4', '#e8f0fe', 'Google'],
        'anthropic' => ['#C2562F', '#fbeee8', 'Anthropic'],
        'openai' => ['#0E8F6E', '#e3f5ef', 'OpenAI'],
        'openrouter' => ['#5B5FE0', '#ececfd', 'OpenRouter'],
        'custom' => ['#475569', '#eef1f5', 'Tùy chỉnh'],
    ];
    [$fg, $bg, $name] = $map[$brand] ?? $map['custom'];
@endphp
<span {{ $attributes->merge(['class' => 'ai-logo']) }} role="img" aria-label="{{ $name }}" data-logo="{{ $brand }}" style="flex:none;display:inline-flex;align-items:center;justify-content:center;width:{{ $size }}rem;height:{{ $size }}rem;border-radius:999px;background:{{ $bg }};color:{{ $fg }}">
    <svg viewBox="0 0 24 24" width="62%" height="62%" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        @switch($brand)
            @case('google')
                <path d="M19 12.2h-6.6"/><path d="M18.4 7.2A7.5 7.5 0 1 0 19 12.2"/>
                @break
            @case('anthropic')
                <path d="M12 4 5.5 20"/><path d="M12 4l6.5 16"/><path d="M8 14.5h8"/>
                @break
            @case('openai')
                <path d="M12 3.5l7.4 4.25v8.5L12 20.5l-7.4-4.25v-8.5L12 3.5z"/><circle cx="12" cy="12" r="2.6"/>
                @break
            @case('openrouter')
                <path d="M3.5 8h9l3 4-3 4h-9"/><path d="M17 5.5l3.5 2.5L17 10.5"/><path d="M17 13.5l3.5 2.5-3.5 2.5"/>
                @break
            @default
                <rect x="4" y="4.5" width="16" height="6" rx="1.5"/><rect x="4" y="13.5" width="16" height="6" rx="1.5"/><path d="M7.5 7.5h.01M7.5 16.5h.01"/>
        @endswitch
    </svg>
</span>
