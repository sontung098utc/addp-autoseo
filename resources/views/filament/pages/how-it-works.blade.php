<x-filament-panels::page>
    <x-filament::section>
        <p class="text-sm">
            AI viết nháp, hệ thống chấm chất lượng, <strong>người duyệt</strong> quyết định, rồi hệ thống đăng lên CMS.
            Mỗi tính năng bên dưới ghi rõ <strong>đang chạy thật hay chưa</strong>; nhãn lấy từ một sổ duy nhất và mục "Đang hoạt động" luôn kèm bằng chứng.
        </p>
        <div class="mt-3" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center">
            @foreach (['active','demo','not_connected','in_development','idea'] as $r)
                <x-studio.chip domain="readiness" :value="$r" /> <span class="text-xs" style="opacity:.7">{{ $counts[$r] }}</span>
            @endforeach
        </div>
    </x-filament::section>

    <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.75rem">
        @foreach ($stages as $s)
            <x-filament::section>
                <div class="text-xs" style="opacity:.65">Giai đoạn {{ $s['n'] }}</div>
                <div style="font-weight:600">{{ $s['title'] }}</div>
                <div class="text-xs" style="opacity:.75;margin:.25rem 0 .5rem">{{ $s['what'] }}</div>
                @foreach ($s['items'] as $f)
                    @php $url = \App\Filament\Pages\HowItWorksPage::urlFor($f['route']); @endphp
                    <div style="margin:.35rem 0">
                        <x-studio.chip domain="readiness" :value="$f['readiness']" />
                        @if ($url)<a href="{{ $url }}" class="text-xs underline">{{ $f['title'] }}</a>@else<span class="text-xs">{{ $f['title'] }}</span>@endif
                        @if ($f['caveat'])<div class="text-xs" style="opacity:.65;margin-top:.1rem">{{ $f['caveat'] }}</div>@endif
                    </div>
                @endforeach
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section heading="Luồng của đội AI: đã có gì, Dev1 cần xây gì" description="Bản thiết kế sống của lát dọc đầu tiên (AG-S01). Mỗi chặng ghi phần đã làm và điểm nối còn thiếu.">
        <div data-testid="flow-blueprint">
            @foreach ($flow as $i => $b)
                <div style="display:grid;grid-template-columns:2rem 1fr;gap:.6rem;padding:.55rem 0;border-bottom:1px solid rgba(128,128,128,.18)">
                    <div style="opacity:.6">{{ $i + 1 }}</div>
                    <div>
                        <x-studio.chip domain="readiness" :value="$b['readiness']" /> <strong class="text-sm">{{ $b['title'] }}</strong>
                        <span class="text-xs" style="opacity:.6"> · {{ $b['role'] }}</span>
                        <div class="text-xs" style="opacity:.8">{{ $b['what'] }}</div>
                        <div class="text-xs" style="opacity:.8"><strong>Đã có:</strong> {{ $b['built'] }}</div>
                        <div class="text-xs" style="color:#b45309"><strong>Dev1 cần ({{ $b['task'] }}):</strong> {{ $b['dev1'] }}</div>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="mt-2 text-xs" style="opacity:.7">Xem một phiên chạy thật ở mục "Phiên chạy của đội AI" (menu Chiến dịch).</div>
    </x-filament::section>

    <x-filament::section heading="Tự động hóa AI" description="Phần đang được đề xuất — chưa có trong hệ thống.">
        @foreach ($automation as $f)
            <div style="margin:.4rem 0">
                <x-studio.chip domain="readiness" :value="$f['readiness']" /> <strong class="text-sm">{{ $f['title'] }}</strong>
                <div class="text-xs" style="opacity:.7">{{ $f['what'] }} {{ $f['caveat'] }}</div>
            </div>
        @endforeach
    </x-filament::section>

    <x-filament::section heading="Bằng chứng cho mục &quot;Đang hoạt động&quot;" description="Dựa trên test tự động và mã nguồn. Chưa nghiệm thu staging/người thật trừ khi ghi khác.">
        @foreach (\App\Filament\Support\Agent\FeatureRegistry::byReadiness('active') as $f)
            <div style="margin:.5rem 0">
                <strong class="text-sm">{{ $f['title'] }}</strong> <span class="text-xs" style="opacity:.6">(task {{ $f['task'] }})</span>
                <div class="text-xs" style="opacity:.8">{{ $f['evidence'] }}</div>
                @if ($f['caveat'])<div class="text-xs" style="opacity:.65">Lưu ý: {{ $f['caveat'] }}</div>@endif
            </div>
        @endforeach
    </x-filament::section>
</x-filament-panels::page>
