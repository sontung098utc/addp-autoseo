@php
    $col = ['ok' => '#16a34a', 'warn' => '#d97706', 'blocked' => '#6b7280', 'failed' => '#dc2626'];
    $lab = ['ok' => 'xong', 'warn' => 'cần chú ý', 'blocked' => 'bị chặn', 'failed' => 'lỗi'];
@endphp
<x-filament-panels::page>
    <div data-testid="agent-overview" class="space-y-4">
        <x-filament::section :heading="'Hoàn tất cài đặt: còn '.$stepsLeft.' bước'" description="Bước chưa có dữ liệu thật được ghi là chưa làm, không tính là xong.">
            @unless ($ai['connected'])
                <div class="text-sm" style="color:#b45309;margin-bottom:.6rem" data-testid="ai-warning">
                    Chưa có khóa AI. Không có khóa thì lượt tự động không chạy. <a class="underline" href="{{ \App\Filament\Clusters\AgentTeam\Pages\AgentConnection::getUrl() }}">Nhập khóa ở Kết nối AI</a>
                </div>
            @endunless
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(10.5rem,1fr));gap:.6rem">
                @foreach ($steps as $s)
                    <{{ $s['url'] ? 'a' : 'div' }} @if ($s['url']) href="{{ $s['url'] }}" @endif data-step="{{ $s['n'] }}" data-state="{{ $s['state'] }}" style="border:1px solid rgba(128,128,128,.35);border-radius:.6rem;padding:.6rem;text-decoration:none;color:inherit;{{ $s['state'] === 'todo' ? 'border-color:#d97706;' : '' }}">
                        <div style="font-size:.7rem;opacity:.6">Bước {{ $s['n'] }}</div>
                        <div style="font-weight:600;font-size:.88rem">{{ $s['title'] }}
                            @if ($s['state'] === 'done')<span style="color:#16a34a">✓</span>@elseif ($s['state'] === 'todo')<span style="color:#d97706">●</span>@endif
                        </div>
                        <div style="font-size:.72rem;opacity:.8;margin-top:.2rem">{{ $s['note'] }}</div>
                    </{{ $s['url'] ? 'a' : 'div' }}>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section heading="Đường đi của một bài" description="Số liệu thật ngay bây giờ. Bấm để tới nơi xử lý.">
            <div style="display:flex;flex-wrap:wrap;gap:.4rem;align-items:stretch" data-testid="lifecycle">
                @foreach ($life as $l)
                    @if (! $loop->first)<div style="align-self:center;opacity:.5">→</div>@endif
                    <a href="{{ $l['url'] }}" style="flex:1 1 7rem;border:1px solid rgba(128,128,128,.35);border-radius:.6rem;padding:.55rem;text-decoration:none;color:inherit">
                        <div style="font-size:.68rem;opacity:.6">{{ $l['who'] }}</div>
                        <div style="font-size:1.3rem;font-weight:700;line-height:1.1">{{ $l['n'] }}</div>
                        <div style="font-size:.75rem">{{ $l['label'] }}</div>
                    </a>
                @endforeach
            </div>
            <p class="mt-2 text-xs" style="opacity:.7">Đội AI chỉ tạo bản nháp. Từ "Chờ duyệt" trở đi là việc của người duyệt và hệ thống đăng đã có sẵn, không đổi.</p>
        </x-filament::section>

        @if ($attention->isNotEmpty() || ! $ai['connected'])
            <x-filament::section heading="Cần bạn xem">
                @unless ($ai['connected'])<div class="text-sm" style="color:#b45309">Chưa kết nối AI nên lượt tự động không chạy được.</div>@endunless
                @foreach ($attention as $r)
                    <div class="text-sm" style="margin:.3rem 0">
                        <x-filament::badge :color="$statusMap[$r->status][1] ?? 'gray'">{{ $statusMap[$r->status][0] ?? $r->status }}</x-filament::badge>
                        <a class="underline" href="{{ \App\Filament\Resources\AgentRunResource::getUrl('view', ['record' => $r]) }}">{{ $r->article?->primary_keyword }}</a>
                        <span class="text-xs" style="opacity:.7">{{ \Illuminate\Support\Str::limit((string) $r->outcome_reason, 90) }}</span>
                    </div>
                @endforeach
            </x-filament::section>
        @endif

        <x-filament::section heading="Các chặng bên trong đội AI" description="{{ $total }} phiên chạy thật trong 30 ngày qua. Bấm vào chặng để xem phần đã có và phần Dev1 cần xây." collapsible>
            <div style="display:flex;flex-wrap:wrap;gap:.4rem">
                @foreach ($nodes as $n)
                    @if (! $loop->first)<div style="align-self:center;opacity:.5">→</div>@endif
                    <details style="flex:1 1 8.5rem;border:1px solid rgba(128,128,128,.4);border-radius:.6rem;padding:.55rem" data-stage="{{ $n['stage'] }}">
                        <summary style="cursor:pointer;list-style:none">
                            <div style="font-size:.68rem;opacity:.6">{{ $n['role'] }}</div>
                            <div style="font-weight:600;font-size:.82rem">{{ $n['title'] }}</div>
                            <div style="margin:.25rem 0"><x-studio.chip domain="readiness" :value="$n['readiness']" /></div>
                            <div style="font-size:.68rem">
                                @forelse ($n['stats'] as $st => $c)<span style="color:{{ $col[$st] ?? '#6b7280' }}">{{ $c }} {{ $lab[$st] ?? $st }}</span>@if (! $loop->last) · @endif @empty<span style="opacity:.55">chưa có số liệu</span>@endforelse
                            </div>
                        </summary>
                        <div style="font-size:.72rem;margin-top:.5rem;line-height:1.35">
                            <div>{{ $n['what'] }}</div>
                            <div style="margin-top:.3rem"><strong>Đã có:</strong> {{ $n['built'] }}</div>
                            <div style="margin-top:.3rem;color:#b45309"><strong>Dev1 cần ({{ $n['task'] }}):</strong> {{ $n['dev1'] }}</div>
                        </div>
                    </details>
                @endforeach
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
