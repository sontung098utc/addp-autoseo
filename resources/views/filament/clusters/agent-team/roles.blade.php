<x-filament-panels::page>
    @php
        $total = count($cards);
        $withModel = count(array_filter($cards, fn ($c) => $c['assigned']));
        $busy = count(array_filter($cards, fn ($c) => $c['working'] !== []));
    @endphp
    <style>
        .rc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(18rem,1fr));gap:.75rem}
        .rc{border:1px solid rgba(128,128,128,.35);border-radius:.8rem;padding:.85rem;display:flex;flex-direction:column;gap:.55rem;min-width:0}
        .rc-top{display:flex;justify-content:space-between;align-items:center;gap:.5rem}
        .rc-id{display:flex;gap:.6rem;align-items:center;min-width:0;text-align:left;background:transparent;border:0;padding:0;color:inherit;cursor:pointer}
        .rc-id:hover strong{text-decoration:underline}
        .rc-name{min-width:0}.rc-name strong{display:block;line-height:1.2}.rc-name span{display:block;font-size:.75rem;opacity:.7}
        .rc-goal{font-size:.78rem;opacity:.8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .rc-foot{display:flex;flex-wrap:wrap;gap:.35rem;align-items:center}
        .rc-chip{display:inline-flex;align-items:center;gap:.3rem;max-width:100%;padding:.15rem .6rem;border-radius:999px;border:1px solid rgba(128,128,128,.4);font-size:.74rem;background:transparent;color:inherit;text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        button.rc-chip,a.rc-chip{cursor:pointer}button.rc-chip:hover,a.rc-chip:hover{background:rgba(128,128,128,.14)}
        .rc-chip--empty{border-style:dashed;border-color:#0f6b5c;color:#0f6b5c}
        .dark .rc-chip--empty{border-color:#5eead4;color:#5eead4}
        .rc-chip--live{border-color:rgba(14,165,233,.6);background:rgba(14,165,233,.1)}
        .rc-muted{font-size:.74rem;opacity:.6}
        .rc-sw{position:relative;width:2.3rem;height:1.3rem;border-radius:999px;border:0;background:rgba(128,128,128,.4);cursor:pointer;flex:none;transition:background .15s}
        .rc-sw i{position:absolute;top:.15rem;left:.15rem;width:1rem;height:1rem;border-radius:50%;background:#fff;transition:transform .15s}
        .rc-sw[aria-checked=true]{background:#0f6b5c}.rc-sw[aria-checked=true] i{transform:translateX(1rem)}
        .rc--off{opacity:.72}
        .rc-dot{width:.5rem;height:.5rem;border-radius:50%;background:rgb(14,165,233);flex:none;animation:rc-blink 1.2s ease-in-out infinite}
        @keyframes rc-blink{50%{opacity:.25}}
        @media (prefers-reduced-motion:reduce){.rc-dot{animation:none}}
    </style>
    <div data-testid="agent-roles" class="space-y-4" @if ($busy > 0) wire:poll.8s @endif>
        <div style="display:flex;align-items:center;gap:.4rem;flex-wrap:wrap">
            <span class="text-sm" style="opacity:.85">{{ $total }} nhân sự · {{ $withModel }} đã có model{{ $busy > 0 ? ' · '.$busy.' đang làm' : '' }}</span>
            <x-help title="Đội AI SEO">
                <p>Mỗi nhân sự là một vai trò trong dây chuyền làm bài, học từ bộ claude-seo và chỉnh cho website tiếng Việt.</p>
                <ul>
                    <li>Bấm tên để mở hồ sơ: tổng quan, huấn luyện, mô hình & kỹ thuật, vị trí trong luồng, hoạt động.</li>
                    <li>Bấm chip model để mở danh sách model khả dụng và chọn; công tắc để bật/tắt (chỉ admin).</li>
                    <li>Trạng thái phản ánh việc thật sự được nối vào phiên chạy, không phải kế hoạch. Biên tập (chấm luật) và Người viết luôn bật.</li>
                </ul>
            </x-help>
        </div>
        @if (! $hasProviders)
            <div class="text-sm" style="color:#d97706;display:flex;align-items:center;gap:.35rem" data-testid="roles-no-provider"><x-filament::icon icon="heroicon-o-exclamation-triangle" style="width:1rem;height:1rem" /> Chưa có tài khoản AI. <a href="{{ $connectionUrl }}" style="text-decoration:underline">Thêm ở Cấu hình AI</a></div>
        @endif
        <div class="rc-grid">
            @foreach ($cards as $c)
                @php $k = $c['key']; @endphp
                <article class="rc {{ $c['is_off'] ? 'rc--off' : '' }}" data-testid="role-card-{{ $k }}" wire:key="rc-{{ $k }}">
                    <div class="rc-top">
                        <button type="button" class="rc-id" wire:click="openStudio('{{ $k }}')" aria-label="Mở hồ sơ {{ $c['name'] }}">
                            @include('filament.clusters.agent-team.agent-studio-avatar', ['role' => $k, 'size' => 2.4])
                            <span class="rc-name"><strong>{{ $c['name'] }}</strong><span>{{ $c['profile']['title'] }}</span></span>
                        </button>
                        @if ($c['switchable'])
                            @if ($canSwitch)
                                <button type="button" class="rc-sw" role="switch" aria-checked="{{ $c['is_off'] ? 'false' : 'true' }}" aria-label="Bật/tắt {{ $c['name'] }}" wire:click="toggleRole('{{ $k }}')" data-testid="role-switch-{{ $k }}" title="{{ $c['is_off'] ? 'Đang tắt: bước dàn ý bị bỏ qua' : 'Đang bật' }}"><i></i></button>
                            @else
                                <span class="rc-muted" data-testid="role-switch-{{ $k }}" title="Chỉ admin đổi">{{ $c['is_off'] ? 'Đang tắt' : 'Đang bật' }}</span>
                            @endif
                        @else
                            <span class="rc-muted" title="Vai trò này luôn bật" data-testid="role-switch-{{ $k }}">Luôn bật</span>
                        @endif
                    </div>
                    <div class="rc-goal" title="{{ $c['goal'] }}">{{ $c['goal'] }}</div>
                    <div class="rc-foot">
                            @if ($c['assigned'])
                                @if ($canSwitch)
                                    <button type="button" class="rc-chip" wire:click="openStudio('{{ $k }}', 'model')" data-testid="role-model-chip-{{ $k }}" title="Bấm để chọn model từ danh sách"><span data-testid="role-model-{{ $k }}">{{ $c['assigned'] }}</span></button>
                                @else
                                    <span class="rc-chip" data-testid="role-model-chip-{{ $k }}"><span data-testid="role-model-{{ $k }}">{{ $c['assigned'] }}</span></span>
                                @endif
                            @elseif ($canSwitch)
                                <button type="button" class="rc-chip rc-chip--empty" wire:click="openStudio('{{ $k }}', 'model')" data-testid="role-model-chip-{{ $k }}" title="Bấm để chọn model từ danh sách"><span data-testid="role-model-{{ $k }}">Chưa gán model</span></button>
                            @else
                                <span class="rc-chip rc-chip--empty" data-testid="role-model-chip-{{ $k }}"><span data-testid="role-model-{{ $k }}">Chưa gán model</span></span>
                            @endif
                        @if ($c['working'])
                            @php $w = $c['working'][0]; @endphp
                            @if (\App\Filament\Resources\AgentRunResource::canViewAny())
                                <a class="rc-chip rc-chip--live" href="{{ \App\Filament\Resources\AgentRunResource::getUrl('view', ['record' => $w['run']]) }}" data-testid="role-status-{{ $k }}" data-status="working" title="Xem phiên đang chạy"><span class="rc-dot"></span>Đang làm: {{ $w['current'] ?? $w['job_title'] }} · {{ preg_replace('/^(Bài|Website): /u', '', $w['subject']) }}</a>
                            @else
                                <span class="rc-chip rc-chip--live" data-testid="role-status-{{ $k }}" data-status="working"><span class="rc-dot"></span>Đang làm: {{ $w['current'] ?? $w['job_title'] }} · {{ preg_replace('/^(Bài|Website): /u', '', $w['subject']) }}</span>
                            @endif
                            @if (count($c['working']) > 1)<span class="rc-muted">+{{ count($c['working']) - 1 }} phiên</span>@endif
                        @else
                            <span class="rc-muted" data-testid="role-status-{{ $k }}" data-status="idle">Rảnh</span>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    </div>
    @if ($studio)
        @include('filament.clusters.agent-team.agent-studio-window', ['s' => $studio])
    @endif
</x-filament-panels::page>
