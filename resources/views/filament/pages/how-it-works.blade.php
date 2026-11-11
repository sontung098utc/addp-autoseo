<x-filament-panels::page>
    <x-filament::section>
        <div class="text-sm">
            AI viết nháp, hệ thống chấm chất lượng, <strong>người duyệt</strong> quyết định, rồi hệ thống đăng lên CMS.
            <x-help title="Đọc nhãn thế nào"><p>Mỗi tính năng bên dưới ghi rõ <strong>đang chạy thật hay chưa</strong>; nhãn lấy từ một sổ duy nhất và mục "Đang hoạt động" luôn kèm bằng chứng.</p></x-help>
        </div>
        <div class="mt-3" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center">
            @foreach (['active','demo','not_connected','in_development','idea'] as $r)
                <x-studio.chip domain="readiness" :value="$r" /> <span class="text-xs" style="opacity:.7">{{ $counts[$r] }}</span>
            @endforeach
        </div>
    </x-filament::section>

    @php $order = ['idea' => 0, 'in_development' => 1, 'not_connected' => 2, 'demo' => 3, 'active' => 4]; @endphp
    <div class="hw-grid" data-testid="hw-stages">
        <style>.hw-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.75rem}@media (max-width:1100px){.hw-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media (max-width:640px){.hw-grid{grid-template-columns:minmax(0,1fr)}}</style>
        @foreach ($stages as $s)
            @php
                // Huy hiệu của thẻ = mức KÉM nhất trong các tính năng của giai đoạn, để không nói quá sự thật.
                $weakest = collect($s['items'])->sortBy(fn ($f) => $order[$f['readiness']] ?? 0)->first();
            @endphp
            <x-filament::section>
                <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem">
                    <div class="text-xs" style="opacity:.65">Giai đoạn {{ $s['n'] }}</div>
                    @if ($weakest)<span data-testid="hw-badge"><x-studio.chip domain="readiness" :value="$weakest['readiness']" /></span>@endif
                </div>
                <div style="font-weight:600">
                    {{ $s['title'] }}
                    <x-help title="Chi tiết {{ $s['title'] }}">
                        @foreach ($s['items'] as $f)
                            @php $url = \App\Filament\Pages\HowItWorksPage::urlFor($f['route']); @endphp
                            <p><x-studio.chip domain="readiness" :value="$f['readiness']" />
                                @if ($url)<a href="{{ $url }}" class="underline">{{ $f['title'] }}</a>@else<strong>{{ $f['title'] }}</strong>@endif
                                @if ($f['caveat'])<br><span style="opacity:.75">{{ $f['caveat'] }}</span>@endif</p>
                        @endforeach
                    </x-help>
                </div>
                <div class="text-xs" style="opacity:.75;margin-top:.25rem">{{ $s['what'] }}</div>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section collapsible collapsed>
        <x-slot name="heading">Luồng của đội AI: đã có gì, Dev1 cần xây gì <x-help title="Về phần này"><p>Bản thiết kế sống của lát dọc đầu tiên (AG-S01). Mỗi chặng ghi phần đã làm và điểm nối còn thiếu.</p></x-help></x-slot>
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
    </x-filament::section>

    <x-filament::section collapsible collapsed>
        <x-slot name="heading">Tự động hóa AI (đề xuất) <x-help title="Về phần này"><p>Phần đang được đề xuất — chưa có trong hệ thống.</p></x-help></x-slot>
        @foreach ($automation as $f)
            <div style="margin:.4rem 0">
                <x-studio.chip domain="readiness" :value="$f['readiness']" /> <strong class="text-sm">{{ $f['title'] }}</strong>
                <div class="text-xs" style="opacity:.7">{{ $f['what'] }} {{ $f['caveat'] }}</div>
            </div>
        @endforeach
    </x-filament::section>

    <x-filament::section collapsible collapsed>
        <x-slot name="heading">Bằng chứng cho mục "Đang hoạt động" <x-help title="Về phần này"><p>Dựa trên test tự động và mã nguồn. Chưa nghiệm thu staging/người thật trừ khi ghi khác.</p></x-help></x-slot>
        @foreach (\App\Filament\Support\Agent\FeatureRegistry::byReadiness('active') as $f)
            <div style="margin:.5rem 0">
                <strong class="text-sm">{{ $f['title'] }}</strong> <span class="text-xs" style="opacity:.6">(task {{ $f['task'] }})</span>
                <div class="text-xs" style="opacity:.8">{{ $f['evidence'] }}</div>
                @if ($f['caveat'])<div class="text-xs" style="opacity:.65">Lưu ý: {{ $f['caveat'] }}</div>@endif
            </div>
        @endforeach
    </x-filament::section>
</x-filament-panels::page>
