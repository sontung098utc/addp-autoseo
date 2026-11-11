@props(['type' => 'article_write'])
{{-- Hình minh họa phẳng cho từng loại việc (SVG nội tuyến, tự vẽ). Màu nhấn lấy từ --tone của thẻ chứa. --}}
@php($known = ['market_research', 'site_audit', 'content_plan', 'article_write', 'article_refresh', 'brain_build', 'measure'])
<div {{ $attributes->class(['fl-illu']) }} data-illustration="{{ $type }}" role="img" aria-label="Hình minh họa {{ $type }}">
<svg viewBox="0 0 320 150" xmlns="http://www.w3.org/2000/svg" focusable="false" aria-hidden="true">
    <circle cx="268" cy="30" r="46" fill="var(--tone)" opacity=".10"/>
    <circle cx="44" cy="128" r="34" fill="var(--tone)" opacity=".08"/>
    @switch(in_array($type, $known, true) ? $type : 'article_write')
        @case('site_audit')
            <rect x="92" y="18" width="104" height="116" rx="10" class="p"/>
            <rect x="106" y="34" width="50" height="8" rx="4" fill="var(--tone)"/>
            <rect x="106" y="52" width="76" height="6" rx="3" class="l"/><rect x="106" y="66" width="62" height="6" rx="3" class="l"/><rect x="106" y="80" width="72" height="6" rx="3" class="l"/><rect x="106" y="94" width="40" height="6" rx="3" class="l"/>
            <circle cx="204" cy="86" r="30" class="p" stroke="var(--tone)" stroke-width="7"/>
            <circle cx="204" cy="86" r="30" fill="var(--tone)" opacity=".10"/>
            <path d="M226 108l30 28" stroke="var(--tone)" stroke-width="10" stroke-linecap="round"/>
            <path d="M192 86l9 9 15-18" fill="none" stroke="var(--tone)" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>
            @break
        @case('market_research')
            <rect x="52" y="24" width="100" height="104" rx="10" class="p"/><rect x="168" y="24" width="100" height="104" rx="10" class="p"/>
            <rect x="64" y="36" width="44" height="8" rx="4" fill="var(--tone)"/><rect x="180" y="36" width="44" height="8" rx="4" fill="var(--tone)" opacity=".55"/>
            @foreach ([58, 78, 98] as $i => $y)
                <rect x="64" y="{{ $y }}" width="{{ [76, 60, 68][$i] }}" height="8" rx="4" class="l"/><rect x="180" y="{{ $y }}" width="{{ [64, 78, 52][$i] }}" height="8" rx="4" class="l"/>
            @endforeach
            <circle cx="160" cy="76" r="18" fill="var(--tone)"/><path d="M152 76h16M162 69l7 7-7 7" fill="none" stroke="#fff" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
            @break
        @case('content_plan')
            <rect x="66" y="20" width="188" height="116" rx="12" class="p"/>
            <path d="M66 32a12 12 0 0112-12h164a12 12 0 0112 12v18H66z" fill="var(--tone)"/>
            <rect x="94" y="12" width="8" height="18" rx="4" class="p" stroke="var(--tone)" stroke-width="3"/><rect x="218" y="12" width="8" height="18" rx="4" class="p" stroke="var(--tone)" stroke-width="3"/>
            @foreach ([0, 1, 2] as $r)@foreach ([0, 1, 2, 3, 4] as $c)
                <rect x="{{ 82 + $c * 32 }}" y="{{ 62 + $r * 24 }}" width="22" height="14" rx="4" @if (($r * 5 + $c) % 4 === 1) fill="var(--tone)" @elseif (($r * 5 + $c) % 4 === 3) fill="var(--tone)" opacity=".4" @else class="l" @endif/>
            @endforeach @endforeach
            @break
        @case('article_refresh')
            <rect x="96" y="18" width="104" height="116" rx="10" class="p"/>
            <rect x="110" y="34" width="52" height="8" rx="4" fill="var(--tone)"/>
            <rect x="110" y="54" width="76" height="6" rx="3" class="l"/><rect x="110" y="68" width="60" height="6" rx="3" class="l"/><rect x="110" y="82" width="70" height="6" rx="3" class="l"/>
            <path d="M232 52a34 34 0 00-58-12M174 40v-20m0 20h20" fill="none" stroke="var(--tone)" stroke-width="8" stroke-linecap="round" stroke-linejoin="round" transform="translate(30 6)"/>
            <path d="M204 108a34 34 0 0058 12M262 120v20m0-20h-20" fill="none" stroke="var(--tone)" stroke-width="8" stroke-linecap="round" stroke-linejoin="round" opacity=".55" transform="translate(-16 -26)"/>
            @break
        @case('brain_build')
            <rect x="60" y="104" width="200" height="12" rx="3" fill="var(--tone)" opacity=".55"/>
            @foreach ([[72, 54, 22, '1'], [98, 40, 22, '.6'], [124, 58, 22, '1'], [150, 46, 22, '.6'], [176, 52, 22, '1']] as [$x, $y, $w, $o])
                <rect x="{{ $x }}" y="{{ $y }}" width="{{ $w }}" height="{{ 104 - $y }}" rx="4" fill="var(--tone)" opacity="{{ $o }}"/>
                <rect x="{{ $x + 6 }}" y="{{ $y + 8 }}" width="10" height="4" rx="2" fill="#fff" opacity=".8"/>
            @endforeach
            <ellipse cx="228" cy="48" rx="26" ry="9" class="p" stroke="var(--tone)" stroke-width="4"/><path d="M202 48v34c0 5 12 9 26 9s26-4 26-9V48" class="p" stroke="var(--tone)" stroke-width="4"/>
            <path d="M202 65c0 5 12 9 26 9s26-4 26-9" fill="none" stroke="var(--tone)" stroke-width="4"/>
            @break
        @case('measure')
            <rect x="58" y="18" width="204" height="118" rx="12" class="p"/>
            <path d="M80 118h160" stroke="currentColor" opacity=".25" stroke-width="2"/>
            @foreach ([[88, 40], [120, 58], [152, 34], [184, 70], [216, 50]] as $i => [$x, $h])
                <rect x="{{ $x }}" y="{{ 118 - $h }}" width="22" height="{{ $h }}" rx="5" fill="var(--tone)" opacity="{{ $i % 2 ? .5 : 1 }}"/>
            @endforeach
            <path d="M84 84l36-14 34 18 36-34 40-12" fill="none" stroke="var(--tone)" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" opacity=".9"/>
            <circle cx="230" cy="28" r="7" fill="var(--tone)"/>
            @break
        @default
            <rect x="82" y="16" width="112" height="120" rx="10" class="p"/>
            <rect x="98" y="32" width="60" height="9" rx="4" fill="var(--tone)"/>
            <rect x="98" y="52" width="80" height="6" rx="3" class="l"/><rect x="98" y="66" width="68" height="6" rx="3" class="l"/><rect x="98" y="80" width="76" height="6" rx="3" class="l"/><rect x="98" y="94" width="50" height="6" rx="3" class="l"/>
            <g transform="rotate(38 216 80)"><rect x="206" y="28" width="20" height="82" rx="4" fill="var(--tone)"/><path d="M206 110h20l-10 20z" fill="var(--tone)" opacity=".6"/><rect x="206" y="28" width="20" height="14" rx="4" fill="#fff" opacity=".35"/></g>
    @endswitch
</svg>
</div>
